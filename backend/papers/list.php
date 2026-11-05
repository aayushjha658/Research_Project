<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Research Papers - List API
|--------------------------------------------------------------------------
| File:
| backend/papers/list.php
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../common/api.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


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

try {

    $pdo = db();


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $page = isset($_GET['page'])
        ? (int) $_GET['page']
        : 1;

    $limit = isset($_GET['limit'])
        ? (int) $_GET['limit']
        : 10;


    if ($page < 1) {
        $page = 1;
    }

    if ($limit < 1) {
        $limit = 10;
    }

    if ($limit > 50) {
        $limit = 50;
    }


    $offset = ($page - 1) * $limit;


    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    $search = isset($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';


    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    $year = isset($_GET['year'])
        ? (int) $_GET['year']
        : 0;

    $domain = isset($_GET['domain'])
        ? trim((string) $_GET['domain'])
        : '';

    $type = isset($_GET['type'])
        ? trim((string) $_GET['type'])
        : '';

    $access = isset($_GET['access'])
        ? trim((string) $_GET['access'])
        : '';


    /*
    |--------------------------------------------------------------------------
    | Sorting
    |--------------------------------------------------------------------------
    */

    $sort = isset($_GET['sort'])
        ? trim((string) $_GET['sort'])
        : 'newest';


    /*
    |--------------------------------------------------------------------------
    | WHERE Conditions
    |--------------------------------------------------------------------------
    */

    $where = [];

    $params = [];


    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {

        $where[] = "
            (
                rp.title LIKE :search
                OR rp.authors LIKE :search
                OR rp.journal LIKE :search
                OR rp.abstract LIKE :search
                OR rp.doi LIKE :search
                OR rp.domain LIKE :search
            )
        ";

        $params[':search'] = '%' . $search . '%';
    }


    /*
    |--------------------------------------------------------------------------
    | Year Filter
    |--------------------------------------------------------------------------
    */

    if ($year > 0) {

        $where[] = "
            rp.publication_year = :publication_year
        ";

        $params[':publication_year'] = $year;
    }


    /*
    |--------------------------------------------------------------------------
    | Domain Filter
    |--------------------------------------------------------------------------
    */

    if ($domain !== '') {

        $where[] = "
            rp.domain = :domain
        ";

        $params[':domain'] = $domain;
    }


    /*
    |--------------------------------------------------------------------------
    | Paper Type Filter
    |--------------------------------------------------------------------------
    */

    if (
        in_array(
            $type,
            ['research', 'review', 'conference'],
            true
        )
    ) {

        $where[] = "
            rp.paper_type = :paper_type
        ";

        $params[':paper_type'] = $type;
    }


    /*
    |--------------------------------------------------------------------------
    | Access Filter
    |--------------------------------------------------------------------------
    */

    if (
        in_array(
            $access,
            ['open', 'paid'],
            true
        )
    ) {

        $where[] = "
            rp.access_type = :access_type
        ";

        $params[':access_type'] = $access;
    }


    /*
    |--------------------------------------------------------------------------
    | Build WHERE
    |--------------------------------------------------------------------------
    */

    $whereSql = '';

    if (!empty($where)) {

        $whereSql =
            ' WHERE ' .
            implode(
                ' AND ',
                $where
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Sorting
    |--------------------------------------------------------------------------
    |
    | Whitelist is important because ORDER BY cannot safely use a normal
    | prepared parameter.
    |
    */

    $orderBy = match ($sort) {

        'oldest' =>
            'rp.publication_year ASC, rp.id ASC',

        'citations' =>
            'rp.citation_count DESC, rp.id DESC',

        'views' =>
            'rp.view_count DESC, rp.id DESC',

        'title' =>
            'rp.title ASC',

        default =>
            'rp.publication_year DESC, rp.id DESC'
    };


    /*
    |--------------------------------------------------------------------------
    | Count Total
    |--------------------------------------------------------------------------
    */

    $countSql = "
        SELECT COUNT(*)
        FROM research_papers rp
        {$whereSql}
    ";


    $countStatement =
        $pdo->prepare($countSql);


    foreach ($params as $key => $value) {

        if (
            $key === ':publication_year'
        ) {

            $countStatement->bindValue(
                $key,
                $value,
                PDO::PARAM_INT
            );

        } else {

            $countStatement->bindValue(
                $key,
                $value,
                PDO::PARAM_STR
            );
        }
    }


    $countStatement->execute();


    $total =
        (int) $countStatement->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | Fetch Papers
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT

            rp.id,
            rp.title,
            rp.authors,
            rp.abstract,
            rp.journal,
            rp.domain,
            rp.paper_type,
            rp.access_type,
            rp.publication_year,
            rp.doi,
            rp.url,
            rp.pdf_path,
            rp.citation_count,
            rp.view_count,
            rp.created_at,
            rp.updated_at,

            CASE
                WHEN b.id IS NULL THEN 0
                ELSE 1
            END AS is_bookmarked

        FROM research_papers rp

        LEFT JOIN bookmarks b
            ON b.paper_id = rp.id
            AND b.user_id = :bookmark_user_id

        {$whereSql}

        ORDER BY {$orderBy}

        LIMIT :limit
        OFFSET :offset
    ";


    $statement =
        $pdo->prepare($sql);


    /*
    |--------------------------------------------------------------------------
    | Bookmark User
    |--------------------------------------------------------------------------
    */

    $statement->bindValue(
        ':bookmark_user_id',
        $userId,
        PDO::PARAM_INT
    );


    /*
    |--------------------------------------------------------------------------
    | Search / Filter Parameters
    |--------------------------------------------------------------------------
    */

    foreach ($params as $key => $value) {

        if (
            $key === ':publication_year'
        ) {

            $statement->bindValue(
                $key,
                $value,
                PDO::PARAM_INT
            );

        } else {

            $statement->bindValue(
                $key,
                $value,
                PDO::PARAM_STR
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Pagination Parameters
    |--------------------------------------------------------------------------
    */

    $statement->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $statement->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );


    /*
    |--------------------------------------------------------------------------
    | Execute
    |--------------------------------------------------------------------------
    */

    $statement->execute();


    $papers =
        $statement->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
    |--------------------------------------------------------------------------
    | Normalize Data
    |--------------------------------------------------------------------------
    */

    foreach ($papers as &$paper) {

        $paper['id'] =
            (int) $paper['id'];

        $paper['publication_year'] =
            $paper['publication_year'] !== null
                ? (int) $paper['publication_year']
                : null;

        $paper['citation_count'] =
            (int) $paper['citation_count'];

        $paper['view_count'] =
            (int) $paper['view_count'];

        $paper['is_bookmarked'] =
            (bool) $paper['is_bookmarked'];

        /*
        |--------------------------------------------------------------
        | PDF Download Endpoint
        |--------------------------------------------------------------
        */

        if (
            !empty($paper['pdf_path'])
        ) {

            $paper['download_url'] =
                'backend/papers/download.php?id=' .
                (int) $paper['id'];

        } else {

            $paper['download_url'] = null;
        }

        /*
        |--------------------------------------------------------------
        | Paper Details Endpoint
        |--------------------------------------------------------------
        */

        $paper['view_url'] =
            'paper-details.html?id=' .
            (int) $paper['id'];

        /*
        |--------------------------------------------------------------
        | AI Analysis Endpoint
        |--------------------------------------------------------------
        */

        $paper['analysis_url'] =
            'ai-analysis.html?id=' .
            (int) $paper['id'];
    }

    unset($paper);


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $totalPages =
        $total > 0
            ? (int) ceil(
                $total / $limit
            )
            : 0;


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        [
            'success' => true,

            'data' => [

                'papers' => $papers,

                'pagination' => [

                    'page' =>
                        $page,

                    'limit' =>
                        $limit,

                    'total' =>
                        $total,

                    'total_pages' =>
                        $totalPages

                ]

            ]

        ]
    );


} catch (PDOException $error) {

    error_log(
        'Research Papers List Error: ' .
        $error->getMessage()
    );


    jsonResponse(
        [
            'success' => false,
            'message' =>
                'Unable to load research papers.'
        ],
        500
    );
}