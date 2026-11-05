<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
methodOnly('GET', 'POST', 'DELETE');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $s = $pdo->prepare("SELECT * FROM research_ideas WHERE user_id=:uid ORDER BY created_at DESC LIMIT {$limit}");
    $s->execute([':uid' => $userId]);
    jsonResponse(['success' => true, 'ideas' => $s->fetchAll()]);
}
requireCsrf();
$d = requestData();
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $s = $pdo->prepare('DELETE FROM research_ideas WHERE id=:id AND user_id=:uid');
    $s->execute([':id' => $id, ':uid' => $userId]);
    jsonResponse(['success' => true, 'message' => 'Idea deleted.']);
}
$domain = safeText($d['domain'] ?? 'General Research', 120);
$topic = safeText($d['topic'] ?? '', 500);
$difficulty = safeText($d['difficulty'] ?? 'intermediate', 20);
if ($topic === '') jsonResponse(['success' => false, 'message' => 'Research topic is required.'], 422);
if (!in_array($difficulty, ['beginner', 'intermediate', 'advanced', 'expert'], true)) $difficulty = 'intermediate';
$ideas = [
    ['title' => "AI-assisted $topic for practical research decision support", 'objectives' => "Develop a baseline system; evaluate it on a suitable dataset; compare at least two models; analyze limitations.", 'outcomes' => "A reproducible prototype, evaluation results, and a documented set of limitations and future work.", 'dataset' => "Use a public dataset relevant to $domain and document its license, provenance, and preprocessing.", 'models' => "Start with Logistic Regression/Random Forest as baselines, then compare a suitable deep-learning or transformer model."],
    ['title' => "Explainable machine learning for $topic", 'objectives' => "Build predictive models; add explainability; measure performance and interpretability; validate findings.", 'outcomes' => "Performance benchmark plus feature-level explanations and reproducibility documentation.", 'dataset' => "Select a representative public dataset for $topic with sufficient labels and metadata.", 'models' => "Random Forest/XGBoost baseline with SHAP-style explanations and a neural model for comparison."],
    ['title' => "Comparative study of deep learning approaches for $topic", 'objectives' => "Compare architectures under a common protocol; tune hyperparameters; report confidence intervals where possible.", 'outcomes' => "A fair architecture comparison, error analysis, and recommendations for future experiments.", 'dataset' => "Use one primary dataset and, if available, an external validation dataset.", 'models' => "CNN/Transformer family appropriate to the data modality, with a classical baseline."]
];
$pick = $ideas[array_rand($ideas)];
$s = $pdo->prepare('INSERT INTO research_ideas(user_id,domain,topic,difficulty,title,objectives,expected_outcomes,suggested_dataset,suggested_models) VALUES(:uid,:d,:t,:diff,:title,:o,:e,:ds,:m)');
$s->execute([':uid' => $userId, ':d' => $domain, ':t' => $topic, ':diff' => $difficulty, ':title' => $pick['title'], ':o' => $pick['objectives'], ':e' => $pick['outcomes'], ':ds' => $pick['dataset'], ':m' => $pick['models']]);
$id = (int)$pdo->lastInsertId();
jsonResponse(['success' => true, 'message' => 'Research idea generated.', 'idea' => ['id' => $id, 'domain' => $domain, 'topic' => $topic, 'difficulty' => $difficulty] + $pick], 201);
