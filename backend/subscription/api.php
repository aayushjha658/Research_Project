<?php

declare(strict_types=1);

/**
 * Research AI
 * Subscription API
 *
 * Phase 1B
 *
 * GET:
 *   ?action=plans
 *   ?action=current
 *   ?action=check-limit&paper_count=8
 *
 * POST:
 *   action=check-limit
 *   {
 *       "paper_count": 20
 *   }
 *
 * Payment/Razorpay activation is NOT handled here.
 */

require_once __DIR__ . '/service.php';


header(
    'Content-Type: application/json; charset=utf-8'
);


try {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    $userId = requireAuth();

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    $pdo = db();


    /*
    |--------------------------------------------------------------------------
    | Determine Action
    |--------------------------------------------------------------------------
    */

    $action = safeText(
        $_GET['action']
            ??
            $_POST['action']
            ??
            '',
        50
    );


    /*
     * Default action
     */
    if ($action === '') {
        $action = 'current';
    }


    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */

    if ($action === 'plans') {

        methodOnly('GET');

        $plans =
            subscriptionListPlans(
                $pdo
            );

        jsonResponse([

            'success' =>
            true,

            'plans' =>
            $plans

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Current Subscription
    |--------------------------------------------------------------------------
    */

    if ($action === 'current') {

        methodOnly('GET');

        $subscription =
            subscriptionContext(
                $pdo,
                $userId
            );

        jsonResponse([

            'success' =>
            true,

            'subscription' =>
            $subscription

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Check Paper Limit
    |--------------------------------------------------------------------------
    */

    if ($action === 'check-limit') {

        methodOnly(
            'GET',
            'POST'
        );


        /*
        |--------------------------------------------------------------------------
        | Read paper count
        |--------------------------------------------------------------------------
        */

        if (
            $_SERVER['REQUEST_METHOD']
            ===
            'GET'
        ) {

            $rawCount =
                $_GET['paper_count']
                ??
                null;
        } else {

            $data =
                requestData();

            $rawCount =
                $data['paper_count']
                ??
                null;
        }


        /*
        |--------------------------------------------------------------------------
        | Validate paper count
        |--------------------------------------------------------------------------
        */

        if (
            $rawCount === null
            ||
            filter_var(
                $rawCount,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 0
                    ]
                ]
            ) === false
        ) {

            jsonResponse([

                'success' =>
                false,

                'code' =>
                'INVALID_PAPER_COUNT',

                'message' =>
                'A valid non-negative paper_count is required.'

            ], 422);
        }


        $paperCount =
            (int)$rawCount;


        /*
        |--------------------------------------------------------------------------
        | Check Entitlement
        |--------------------------------------------------------------------------
        */

        $result =
            subscriptionCheckPaperLimit(
                $pdo,
                $userId,
                $paperCount
            );


        jsonResponse([

            'success' =>
            true,

            'entitlement' =>
            $result

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Unknown Action
    |--------------------------------------------------------------------------
    */

    jsonResponse([

        'success' =>
        false,

        'code' =>
        'UNKNOWN_SUBSCRIPTION_ACTION',

        'message' =>
        'Unknown subscription action.'

    ], 404);
} catch (InvalidArgumentException $e) {

    jsonResponse([

        'success' =>
        false,

        'code' =>
        'INVALID_SUBSCRIPTION_REQUEST',

        'message' =>
        $e->getMessage()

    ], 422);
} catch (PDOException $e) {

    error_log(
        'Research AI Subscription API DB Error: '
            .
            $e->getMessage()
    );

    jsonResponse([

        'success' =>
        false,

        'code' =>
        'SUBSCRIPTION_DATABASE_ERROR',

        'message' =>
        'Unable to load subscription information.'

    ], 500);
} catch (Throwable $e) {

    error_log(
        'Research AI Subscription API Error: '
            .
            $e->getMessage()
    );

    jsonResponse([

        'success' =>
        false,

        'code' =>
        'SUBSCRIPTION_API_ERROR',

        'message' =>
        'Unable to process the subscription request.'

    ], 500);
}
