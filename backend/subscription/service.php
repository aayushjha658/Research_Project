<?php

declare(strict_types=1);

/**
 * Research AI
 * Subscription Service
 *
 * Phase 1B
 *
 * Responsibilities:
 * - Find current user subscription
 * - Ensure Free subscription exists
 * - Return current plan
 * - Return paper-selection entitlement
 * - Return available upgrade options
 *
 * Payment/Razorpay activation is NOT handled here.
 */

require_once __DIR__ . '/../common/api.php';


/*
|--------------------------------------------------------------------------
| Normalize Subscription Plan
|--------------------------------------------------------------------------
*/

function subscriptionNormalizePlan(array $plan): array
{
    $maxPapers = $plan['max_papers'] ?? null;

    return [
        'id' => (int)($plan['id'] ?? 0),

        'code' => (string)(
            $plan['code'] ?? ''
        ),

        'name' => (string)(
            $plan['name'] ?? ''
        ),

        'description' => $plan['description'] !== null
            ? (string)$plan['description']
            : null,

        /*
         * NULL means unlimited.
         */
        'max_papers' => $maxPapers === null
            ? null
            : (int)$maxPapers,

        'unlimited' => $maxPapers === null,

        'price_paise' => $plan['price_paise'] === null
            ? null
            : (int)$plan['price_paise'],

        'currency' => (string)(
            $plan['currency'] ?? 'INR'
        ),

        'billing_interval' => (string)(
            $plan['billing_interval'] ?? 'month'
        ),

        'is_active' => (bool)(
            $plan['is_active'] ?? false
        ),

        'display_order' => (int)(
            $plan['display_order'] ?? 0
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| Get Free Plan
|--------------------------------------------------------------------------
*/

function subscriptionFreePlan(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            code,
            name,
            description,
            max_papers,
            price_paise,
            currency,
            billing_interval,
            is_active,
            display_order
        FROM subscription_plans
        WHERE code = 'free'
        LIMIT 1
    ");

    $stmt->execute();

    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        throw new RuntimeException(
            'Free subscription plan is not configured.'
        );
    }

    return subscriptionNormalizePlan($plan);
}


/*
|--------------------------------------------------------------------------
| Find Current Subscription
|--------------------------------------------------------------------------
*/

function subscriptionFindCurrent(
    PDO $pdo,
    int $userId
): ?array {

    $stmt = $pdo->prepare("
        SELECT

            s.id AS subscription_id,
            s.user_id,
            s.plan_id,
            s.status,

            s.provider,
            s.provider_customer_id,
            s.provider_subscription_id,

            s.starts_at,
            s.ends_at,

            s.current_period_start,
            s.current_period_end,

            s.cancel_at_period_end,
            s.cancelled_at,

            s.created_at,
            s.updated_at,

            p.code AS plan_code,
            p.name AS plan_name,
            p.description AS plan_description,

            p.max_papers,
            p.price_paise,
            p.currency,
            p.billing_interval,

            p.is_active AS plan_is_active,
            p.display_order

        FROM subscriptions s

        INNER JOIN subscription_plans p
            ON p.id = s.plan_id

        WHERE s.user_id = :user_id

          AND s.status IN (
              'trialing',
              'active'
          )

          AND s.starts_at <= CURRENT_TIMESTAMP

          AND (
              s.ends_at IS NULL
              OR s.ends_at > CURRENT_TIMESTAMP
          )

          AND p.is_active = 1

        ORDER BY

            CASE
                WHEN s.status = 'active'
                THEN 0
                ELSE 1
            END,

            COALESCE(
                s.current_period_end,
                s.ends_at,
                '9999-12-31 23:59:59'
            ) DESC,

            s.id DESC

        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return subscriptionNormalizeCurrent($row);
}


/*
|--------------------------------------------------------------------------
| Ensure Free Subscription
|--------------------------------------------------------------------------
|
| If the user has no active subscription,
| automatically create Free subscription.
|
| Existing paid subscriptions are NEVER overwritten.
|--------------------------------------------------------------------------
*/

function subscriptionEnsureFree(
    PDO $pdo,
    int $userId
): array {

    $current = subscriptionFindCurrent(
        $pdo,
        $userId
    );

    if ($current !== null) {
        return $current;
    }

    $freePlan = subscriptionFreePlan(
        $pdo
    );

    $insert = $pdo->prepare("
        INSERT INTO subscriptions
        (
            user_id,
            plan_id,
            status,
            starts_at
        )
        VALUES
        (
            :user_id,
            :plan_id,
            'active',
            CURRENT_TIMESTAMP
        )
    ");

    try {

        $insert->execute([
            ':user_id' => $userId,
            ':plan_id' => $freePlan['id']
        ]);
    } catch (PDOException $e) {

        /*
         * Another request may have created
         * the subscription simultaneously.
         *
         * Re-check before returning an error.
         */

        $current = subscriptionFindCurrent(
            $pdo,
            $userId
        );

        if ($current !== null) {
            return $current;
        }

        throw $e;
    }

    $current = subscriptionFindCurrent(
        $pdo,
        $userId
    );

    if ($current === null) {

        throw new RuntimeException(
            'Unable to initialize user subscription.'
        );
    }

    return $current;
}


/*
|--------------------------------------------------------------------------
| Get Subscription Context
|--------------------------------------------------------------------------
*/

function subscriptionContext(
    PDO $pdo,
    int $userId
): array {

    $subscription = subscriptionEnsureFree(
        $pdo,
        $userId
    );

    $maxPapers =
        $subscription['plan']['max_papers'];

    return [

        'subscription_id' =>
        $subscription['subscription_id'],

        'user_id' =>
        $userId,

        'status' =>
        $subscription['status'],

        'plan' =>
        $subscription['plan'],

        'starts_at' =>
        $subscription['starts_at'],

        'ends_at' =>
        $subscription['ends_at'],

        'current_period_start' =>
        $subscription['current_period_start'],

        'current_period_end' =>
        $subscription['current_period_end'],

        'cancel_at_period_end' =>
        $subscription['cancel_at_period_end'],

        'cancelled_at' =>
        $subscription['cancelled_at'],

        'is_unlimited' =>
        $maxPapers === null
    ];
}


/*
|--------------------------------------------------------------------------
| Get All Active Plans
|--------------------------------------------------------------------------
*/

function subscriptionListPlans(
    PDO $pdo
): array {

    $stmt = $pdo->query("
        SELECT
            id,
            code,
            name,
            description,
            max_papers,
            price_paise,
            currency,
            billing_interval,
            is_active,
            display_order
        FROM subscription_plans
        WHERE is_active = 1
        ORDER BY
            display_order ASC,
            id ASC
    ");

    $plans = [];

    while (
        $row = $stmt->fetch(PDO::FETCH_ASSOC)
    ) {

        $plans[] =
            subscriptionNormalizePlan(
                $row
            );
    }

    return $plans;
}


/*
|--------------------------------------------------------------------------
| Get Upgrade Options
|--------------------------------------------------------------------------
*/

function subscriptionGetUpgradeOptions(
    PDO $pdo,
    ?int $currentLimit
): array {

    $plans =
        subscriptionListPlans(
            $pdo
        );

    $options = [];

    foreach ($plans as $plan) {

        /*
         * Free is not an upgrade option.
         */
        if ($plan['code'] === 'free') {
            continue;
        }

        $planLimit =
            $plan['max_papers'];

        /*
         * Unlimited = NULL
         */
        $isHigher =
            $currentLimit !== null
            &&
            (
                $planLimit === null
                ||
                $planLimit > $currentLimit
            );

        if (!$isHigher) {
            continue;
        }

        $options[] = [

            'code' =>
            $plan['code'],

            'name' =>
            $plan['name'],

            'max_papers' =>
            $plan['max_papers'],

            'unlimited' =>
            $plan['unlimited'],

            'price_paise' =>
            $plan['price_paise'],

            'currency' =>
            $plan['currency'],

            'billing_interval' =>
            $plan['billing_interval']
        ];
    }

    return $options;
}


/*
|--------------------------------------------------------------------------
| Check Paper Selection Limit
|--------------------------------------------------------------------------
|
| THIS is the important function.
|
| Phase 1C will call this BEFORE Gemini/Tavily.
|--------------------------------------------------------------------------
*/

function subscriptionCheckPaperLimit(
    PDO $pdo,
    int $userId,
    int $paperCount
): array {

    if ($paperCount < 0) {

        throw new InvalidArgumentException(
            'Paper count cannot be negative.'
        );
    }

    $context =
        subscriptionContext(
            $pdo,
            $userId
        );

    $limit =
        $context['plan']['max_papers'];

    $unlimited =
        $limit === null;

    $allowed =
        $unlimited
        ||
        $paperCount <= $limit;

    $remaining =
        $unlimited
        ? null
        : max(
            0,
            $limit - $paperCount
        );

    return [

        'allowed' =>
        $allowed,

        'paper_count' =>
        $paperCount,

        'limit' =>
        $limit,

        'unlimited' =>
        $unlimited,

        'remaining' =>
        $remaining,

        'plan' =>
        $context['plan'],

        'subscription_id' =>
        $context['subscription_id'],

        'subscription_status' =>
        $context['status'],

        'upgrade_required' =>
        !$allowed,

        'upgrade_options' =>
        $allowed
            ? []
            : subscriptionGetUpgradeOptions(
                $pdo,
                $limit
            )
    ];
}


/*
|--------------------------------------------------------------------------
| Normalize Current Subscription
|--------------------------------------------------------------------------
*/

function subscriptionNormalizeCurrent(
    array $row
): array {

    $plan = [

        'id' =>
        (int)$row['plan_id'],

        'code' =>
        (string)$row['plan_code'],

        'name' =>
        (string)$row['plan_name'],

        'description' =>
        $row['plan_description'] !== null
            ? (string)$row['plan_description']
            : null,

        'max_papers' =>
        $row['max_papers'] === null
            ? null
            : (int)$row['max_papers'],

        'unlimited' =>
        $row['max_papers'] === null,

        'price_paise' =>
        $row['price_paise'] === null
            ? null
            : (int)$row['price_paise'],

        'currency' =>
        (string)$row['currency'],

        'billing_interval' =>
        (string)$row['billing_interval'],

        'is_active' =>
        (bool)$row['plan_is_active'],

        'display_order' =>
        (int)$row['display_order']
    ];

    return [

        'subscription_id' =>
        (int)$row['subscription_id'],

        'user_id' =>
        (int)$row['user_id'],

        'plan_id' =>
        (int)$row['plan_id'],

        'status' =>
        (string)$row['status'],

        'provider' =>
        $row['provider'] !== null
            ? (string)$row['provider']
            : null,

        'provider_customer_id' =>
        $row['provider_customer_id'] !== null
            ? (string)$row['provider_customer_id']
            : null,

        'provider_subscription_id' =>
        $row['provider_subscription_id'] !== null
            ? (string)$row['provider_subscription_id']
            : null,

        'starts_at' =>
        $row['starts_at'],

        'ends_at' =>
        $row['ends_at'],

        'current_period_start' =>
        $row['current_period_start'],

        'current_period_end' =>
        $row['current_period_end'],

        'cancel_at_period_end' =>
        (bool)$row['cancel_at_period_end'],

        'cancelled_at' =>
        $row['cancelled_at'],

        'created_at' =>
        $row['created_at'],

        'updated_at' =>
        $row['updated_at'],

        'plan' =>
        $plan
    ];
}
