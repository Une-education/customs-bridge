<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);

if (!$request || !isset($request['method'])) {
    // Si accédé en GET, donner un aperçu d'information
    echo json_encode([
        'name' => 'customs-bridge',
        'version' => '1.0.0',
        'protocol' => 'mcp-jsonrpc-2.0',
        'status' => 'listening'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $request['method'];
$id = $request['id'] ?? null;
$dbPath = __DIR__ . '/taric.sqlite';

function getDb(): PDO {
    global $dbPath;
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

// 1. Initialisation MCP
if ($method === 'initialize') {
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => new stdClass()],
            'serverInfo' => [
                'name' => 'customs-bridge',
                'version' => '1.0.0'
            ]
        ]
    ]);
    exit;
}

// 2. Liste des Outils MCP
if ($method === 'tools/list') {
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'tools' => [
                [
                    'name' => 'search_customs_nomenclature',
                    'description' => 'Recherche déterministe de codes TARIC / Nomenclature Combinée par mots-clés bilingues (FR/EN) via FTS5.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Mots-clés décrivant la marchandise (ex: "moteurs électriques", "lithium cells", "drones")'
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Nombre maximal de résultats (défaut: 5)'
                            ]
                        ],
                        'required' => ['query']
                    ]
                ],
                [
                    'name' => 'resolve_hs_code',
                    'description' => 'Résout l arborescence douanière à partir d un code SH6 universel (Chine/UE) ou d un préfixe TARIC à 8/10 chiffres.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'description' => 'Code numérique à résoudre (ex: "850440" pour convertisseurs statiques, "841290")'
                            ]
                        ],
                        'required' => ['code']
                    ]
                ]
            ]
        ]
    ]);
    exit;
}

// 3. Exécution d'un outil
if ($method === 'tools/call') {
    $params = $request['params'] ?? [];
    $toolName = $params['name'] ?? '';
    $args = $params['arguments'] ?? [];
    $pdo = getDb();

    if ($toolName === 'search_customs_nomenclature') {
        $q = trim((string)($args['query'] ?? ''));
        $limit = min(20, max(1, (int)($args['limit'] ?? 5)));

        $tokens = explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q));
        $tokens = array_filter($tokens);
        $ftsExpr = implode(' ', array_map(fn($t) => '"' . $t . '"*', $tokens));

        $stmt = $pdo->prepare("
            SELECT n.code_taric, n.sh6, n.chapter, n.hier_pos, n.desc_fr, n.desc_en 
            FROM goods_fts f 
            JOIN goods_nomenclature n ON f.rowid = n.rowid 
            WHERE goods_fts MATCH :expr 
            ORDER BY bm25(goods_fts) 
            LIMIT :limit
        ");
        $stmt->bindValue(':expr', $ftsExpr);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]
                ]
            ]
        ]);
        exit;
    }

    if ($toolName === 'resolve_hs_code') {
        $code = preg_replace('/[^0-9]/', '', (string)($args['code'] ?? ''));
        $stmt = $pdo->prepare("
            SELECT code_taric, suffix, hier_pos, indent, desc_fr, desc_en 
            FROM goods_nomenclature 
            WHERE code_taric LIKE :prefix 
            ORDER BY code_taric, indent 
            LIMIT 25
        ");
        $stmt->execute([':prefix' => $code . '%']);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]
                ]
            ]
        ]);
        exit;
    }
}

// Erreur méthode inconnue
echo json_encode([
    'jsonrpc' => '2.0',
    'id' => $id,
    'error' => ['code' => -32601, 'message' => 'Method not found']
]);
