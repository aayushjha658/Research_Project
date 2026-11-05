<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$adminId = requireAdmin();
methodOnly('POST', 'PUT', 'DELETE');
requireCsrf();
$d = requestData();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'DELETE') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    if ($id < 1) jsonResponse(['success' => false, 'message' => 'Valid id required.'], 422);
    $s = $pdo->prepare('DELETE FROM research_papers WHERE id=:id');
    $s->execute([':id' => $id]);
    if (!$s->rowCount()) jsonResponse(['success' => false, 'message' => 'Paper not found.'], 404);
    audit($pdo, $adminId, 'paper_deleted');
    jsonResponse(['success' => true, 'message' => 'Paper deleted.']);
}
$id = (int)($d['id'] ?? 0);
$title = safeText($d['title'] ?? '', 500);
if ($title === '') jsonResponse(['success' => false, 'message' => 'Title is required.'], 422);
$fields = ['authors', 'abstract', 'journal', 'doi', 'url', 'pdf_path'];
$v = [];
foreach ($fields as $f) $v[$f] = safeText($d[$f] ?? '', 10000);
$year = isset($d['publication_year']) ? (int)$d['publication_year'] : null;
if ($year !== null && ($year < 1800 || $year > 2100)) jsonResponse(['success' => false, 'message' => 'Invalid publication year.'], 422);
if ($method === 'PUT') {
    if ($id < 1) jsonResponse(['success' => false, 'message' => 'Valid id required.'], 422);
    $s = $pdo->prepare('UPDATE research_papers SET title=:t,authors=:a,abstract=:ab,journal=:j,publication_year=:y,doi=:d,url=:u,pdf_path = :pdf WHERE id=:id');
    $s->execute([':t' => $title, ':a' => $v['authors'] ?: null, ':ab' => $v['abstract'] ?: null, ':j' => $v['journal'] ?: null, ':y' => $year, ':d' => $v['doi'] ?: null, ':u' => $v['url'] ?: null, ':pdf' => $v['pdf_path'] ?: null, ':id' => $id]);
    audit($pdo, $adminId, 'paper_updated');
    jsonResponse(['success' => true, 'message' => 'Paper updated.']);
}
$s = $pdo->prepare('INSERT INTO research_papers(title,authors,abstract,journal,publication_year,doi,url,pdf_path) VALUES(:t,:a,:ab,:j,:y,:d,:u,:pdf)');
$s->execute([':t' => $title, ':a' => $v['authors'] ?: null, ':ab' => $v['abstract'] ?: null, ':j' => $v['journal'] ?: null, ':y' => $year, ':d' => $v['doi'] ?: null, ':u' => $v['url'] ?: null, ':pdf' => $v['pdf_path'] ?: null]);
audit($pdo, $adminId, 'paper_created');
jsonResponse(['success' => true, 'message' => 'Paper created.', 'id' => (int)$pdo->lastInsertId()], 201);
