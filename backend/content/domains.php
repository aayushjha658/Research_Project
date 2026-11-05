<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
requireAuth();
methodOnly('GET');
$domains = ['Healthcare', 'Computer Science', 'Agriculture', 'Mechanical', 'Civil', 'Electronics', 'Management', 'Finance', 'Education'];
jsonResponse(['success' => true, 'domains' => $domains]);
