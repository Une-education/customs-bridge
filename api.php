<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$startTime = microtime(true);
$dbPath = __DIR__ . '/taric.sqlite';

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Base TARIC introuvable.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion base de données.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$endpoint = $_GET['endpoint'] ?? 'ping';

// 1. Healthcheck
if ($endpoint === 'ping' || $endpoint === 'v1/health') {
    $count = (int)$pdo->query("SELECT count(*) FROM goods_nomenclature")->fetchColumn();
    $latency = round((microtime(true) - $startTime) * 1000, 2);
    echo json_encode([
        'status' => 'operational',
        'node' => 'customs.une.education',
        'service' => 'AtoA Customs & Tariff Engine',
        'total_taric_codes' => $count,
        'latency_ms' => $latency
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Recherche plein texte FTS5
if ($endpoint === 'v1/search') {
    $query = trim($_GET['q'] ?? '');
    $lang  = strtolower(trim($_GET['lang'] ?? 'fr'));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

    if ($query === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Paramètre obligatoire "q" manquant.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Nettoyage de la requête pour FTS5
    $cleanQuery = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);
    $cleanQuery = trim((string)preg_replace('/\s+/', ' ', $cleanQuery));

    if ($cleanQuery === '') {
        echo json_encode(['results' => [], 'count' => 0]);
        exit;
    }

    // Tokenisation par préfixe pour FTS5 (ex: "moteur" -> "moteur*")
    $tokens = explode(' ', $cleanQuery);
    $ftsExpr = implode(' ', array_map(fn($t) => '"' . $t . '"*', $tokens));

    $sql = "
        SELECT 
            n.code_taric,
            n.suffix,
            n.sh6,
            n.chapter,
            n.hier_pos,
            n.indent,
            n.desc_fr,
            n.desc_en,
            bm25(goods_fts) AS rank
        FROM goods_fts f
        JOIN goods_nomenclature n ON f.rowid = n.rowid
        WHERE goods_fts MATCH :expr
        ORDER BY rank
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':expr', $ftsExpr, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();

    $latency = round((microtime(true) - $startTime) * 1000, 2);

    echo json_encode([
        'query' => $query,
        'count' => count($results),
        'latency_ms' => $latency,
        'results' => $results
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 3. Résolution directe par code (SH6, NC8, TARIC 10)
if ($endpoint === 'v1/resolve') {
    $codeRaw = trim($_GET['code'] ?? '');
    $code = preg_replace('/[^0-9]/', '', $codeRaw);

    if (strlen($code) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Fournir au moins 2 chiffres pour le code.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Si code 6 chiffres (SH6) : chercher tous les sous-codes enfants
    if (strlen($code) === 6) {
        $stmt = $pdo->prepare("
            SELECT code_taric, suffix, hier_pos, indent, desc_fr, desc_en 
            FROM goods_nomenclature 
            WHERE sh6 = :sh6 
            ORDER BY code_taric, indent
        ");
        $stmt->execute([':sh6' => $code]);
        $rows = $stmt->fetchAll();
    } else {
        // Recherche préfixe ou exacte
        $stmt = $pdo->prepare("
            SELECT code_taric, suffix, sh6, chapter, hier_pos, indent, desc_fr, desc_en 
            FROM goods_nomenclature 
            WHERE code_taric LIKE :prefix 
            ORDER BY code_taric, indent 
            LIMIT 20
        ");
        $stmt->execute([':prefix' => $code . '%']);
        $rows = $stmt->fetchAll();
    }

    $latency = round((microtime(true) - $startTime) * 1000, 2);

    echo json_encode([
        'code_queried' => $codeRaw,
        'normalized' => $code,
        'matches_found' => count($rows),
        'latency_ms' => $latency,
        'tree' => $rows
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
echo json_encode(['error' => "Endpoint '{$endpoint}' non reconnu."], JSON_UNESCAPED_UNICODE);
