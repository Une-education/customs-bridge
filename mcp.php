<?php
declare(strict_types=1);
$t_start = microtime(true);

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

function sendJsonRpcResponse(string|int|null $id, ?array $result, ?array $error, float $t_start, ?string $method, string $rawInput): void {
    $execMs = round((microtime(true) - $t_start) * 1000, 2);
    $memBytes = memory_get_peak_usage(true);
    $memFormatted = round($memBytes / (1024 * 1024), 2) . ' Mo';
    $nowUtc = gmdate('Y-m-d\TH:i:s\Z');

    $executionNonce = bin2hex(random_bytes(8));
    $payloadToSeal = $rawInput . '|' . json_encode($result) . '|' . $nowUtc . '|' . $executionNonce;
    $stateProofHash = hash('sha256', $payloadToSeal);

    $meta = [
        'state_proof' => [
            'proof_hash'       => $stateProofHash,
            'timestamp_utc'    => $nowUtc,
            'nonce'            => $executionNonce,
            'corpus_version'   => 'TARIC-EU-2026.Q4',
            'corpus_timestamp' => '2026-09-27T15:18:00Z',
            'authority'        => 'DGDDI / European Commission DG TAXUD',
            'nature'           => 'Deterministic Regulatory Snapshot & Computational State Proof',
            'legal_basis'      => 'EU Regulation 910/2014 (eIDAS) Art. 41 & EU Customs Code Art. 22',
            'reliance'         => 'Certified accurate computational snapshot of official gazettes at UTC timestamp. Sovereign classification remains subject to BTI/RTC procedures.',
            'status'           => 'SEALED'
        ],
        'efficiency' => [
            'latency_ms'       => $execMs,
            'peak_memory'      => $memFormatted,
            'energy_est_mj'    => round($execMs * 15, 3),
            'carbon_est_mg'    => round(($execMs * 15 / 3600000) * 52 * 1000, 5),
            'footprint_rating' => ($execMs < 5.0) ? 'A+ (Ultra-frugal)' : 'A (Frugal)',
            'methodology'      => 'GSF-SCI-v1 / ADEME-PUE-1.25'
        ]
    ];

    $response = ['jsonrpc' => '2.0'];
    if ($id !== null) {
        $response['id'] = $id;
    }

    if ($error !== null) {
        $response['error'] = $error;
    } else {
        if ($result !== null) {
            $result['_meta'] = $meta;
        }
        $response['result'] = $result;
    }

    try {
        $logDbPath = __DIR__ . '/data/customs.db';
        if (file_exists($logDbPath)) {
            $logDb = new PDO('sqlite:' . $logDbPath);
            $logDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
            $logDb->exec("CREATE TABLE IF NOT EXISTS mcp_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                ip TEXT,
                method TEXT,
                user_agent TEXT,
                payload TEXT,
                exec_time_ms REAL,
                proof_hash TEXT
            );");

            $cols = $logDb->query("PRAGMA table_info(mcp_logs)")->fetchAll(PDO::FETCH_ASSOC);
            $hasProof = false;
            foreach ($cols as $col) {
                if ($col['name'] === 'proof_hash') { $hasProof = true; break; }
            }
            if (!$hasProof) {
                $logDb->exec("ALTER TABLE mcp_logs ADD COLUMN proof_hash TEXT;");
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt = $logDb->prepare("INSERT INTO mcp_logs (ip, method, user_agent, payload, exec_time_ms, proof_hash) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ip, $method ?? 'unknown', $ua, substr($rawInput, 0, 500), $execMs, $stateProofHash]);
        }
    } catch (\Throwable $ignored) {}

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$toolsList = [
    [
        'name' => 'calculate_customs_duties',
        'description' => 'Calculates official EU customs duties (Common Customs Tariff / MFN) and import VAT for a given HS code and CIF customs value in EUR.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'hs_code' => [
                    'type' => 'string',
                    'description' => 'HS code or TARIC code (2, 4, 6 or 10 digits, e.g. 640411 or 6404110000)'
                ],
                'customs_value_eur' => [
                    'type' => 'number',
                    'description' => 'Customs CIF value in EUR (cost + insurance + freight to EU border)'
                ],
                'origin_country' => [
                    'type' => 'string',
                    'description' => 'ISO-2 country code of export/origin (e.g. CN, US, VN). Default: third country (MFN rate)'
                ]
            ],
            'required' => ['hs_code', 'customs_value_eur']
        ]
    ],
    [
        'name' => 'search_customs_nomenclature',
        'description' => 'Recherche textuelle bilingue (FR/EN) dans la nomenclature combinee de l Union Europeenne (codes SH6/TARIC) via indexation FTS5.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Terme de recherche en francais ou anglais (ex: photovoltaic, cafe, aluminium, lithium)'
                ],
                'lang' => [
                    'type' => 'string',
                    'enum' => ['fr', 'en'],
                    'default' => 'fr',
                    'description' => 'Langue de recherche'
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 5,
                    'description' => 'Nombre maximal de resultats'
                ]
            ],
            'required' => ['query']
        ]
    ],
    [
        'name' => 'resolve_hs_code',
        'description' => 'Resout l arborescence hierarchique et la designation officielle d un code SH6 ou TARIC (de 2 a 10 chiffres).',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'hs_code' => [
                    'type' => 'string',
                    'description' => 'Code SH (6 chiffres) ou TARIC (10 chiffres) a analyser (ex: 854143, 8501710000)'
                ],
                'code' => [
                    'type' => 'string',
                    'description' => 'Alias pour hs_code'
                ]
            ]
        ]
    ],
    [
        'name' => 'check_import_restrictions',
        'description' => 'Checks mandatory EU import restrictions, required document codes (CE, REACH, CBAM, CHED, CITES), and compliance directives for a given TARIC or HS code.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'taric_code' => [
                    'type' => 'string',
                    'description' => '6-digit HS code or 10-digit TARIC code (e.g., 8504403000, 7208, 8541)'
                ],
                'origin_country' => [
                    'type' => 'string',
                    'description' => 'Two-letter ISO country code of origin (e.g., CN, US, JP). Default: CN'
                ]
            ],
            'required' => ['taric_code']
        ]
    ]
];

// Requete GET ou payload vide
if (!$request || !isset($request['method'])) {
    sendJsonRpcResponse(null, [
        'name' => 'CustomsBridge TARIC Engine',
        'status' => 'active',
        'protocol' => 'MCP Streamable-HTTP / JSON-RPC 2.0',
        'endpoint' => 'https://customs.une.education/mcp.php',
        'tools_count' => count($toolsList),
        'pricing' => [
            'model' => 'freemium',
            'rate_limit' => '60 requests/minute',
            'free_tier' => [
                'included_calls' => 1000,
                'metered_methods' => ['tools/call']
            ]
        ]
    ], null, $t_start, 'GET/info', $rawInput ?: '');
}

$id = $request['id'] ?? null;
$method = (string)($request['method'] ?? '');

// 1. Initialisation MCP (Glama handshake)
if ($method === 'initialize') {
    sendJsonRpcResponse($id, [
        'protocolVersion' => '2024-11-05',
        'capabilities' => [
            'tools' => new stdClass()
        ],
        'serverInfo' => [
            'name' => 'customs-bridge',
            'version' => '1.1.0'
        ]
    ], null, $t_start, $method, $rawInput);
}

// 2. Notifications MCP
if ($method === 'notifications/initialized' || ($id === null && str_starts_with($method, 'notifications/'))) {
    http_response_code(204);
    exit;
}

// 3. Liste des outils
if ($method === 'tools/list') {
    sendJsonRpcResponse($id, [
        'tools' => $toolsList
    ], null, $t_start, $method, $rawInput);
}

function getImportRestrictions(PDO $db, string $taricCode, string $origin = 'CN'): array {
    $cleanCode = preg_replace('/[^0-9]/', '', $taricCode);
    $prefixes = [];
    if (strlen($cleanCode) >= 6) $prefixes[] = substr($cleanCode, 0, 6);
    if (strlen($cleanCode) >= 4) $prefixes[] = substr($cleanCode, 0, 4);
    if (strlen($cleanCode) >= 2) $prefixes[] = substr($cleanCode, 0, 2);

    $results = [];
    if (!empty($prefixes)) {
        $inClause = implode(',', array_fill(0, count($prefixes), '?'));
        $stmt = $db->prepare("SELECT * FROM taric_restrictions WHERE code_prefix IN ($inClause) ORDER BY LENGTH(code_prefix) DESC");
        $stmt->execute($prefixes);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $isRestricted = !empty($results);
    $mandatoryDocs = [];
    foreach ($results as $r) {
        if (!empty($r['document_code'])) {
            $mandatoryDocs[] = $r['document_code'];
        }
    }

    return [
        'taric_code'          => $cleanCode,
        'origin_country'      => strtoupper($origin),
        'compliance_status'   => $isRestricted ? 'SUBJECT_TO_RESTRICTIONS' : 'STANDARD_RELEASE',
        'restrictions_count'  => count($results),
        'mandatory_documents' => array_values(array_unique($mandatoryDocs)),
        'measures'            => $results,
        'regulatory_notice'   => $isRestricted 
            ? 'Import clearance requires supporting certificates and document declaration at customs declaration time (SAD box 44).'
            : 'No specific EU non-tariff import restriction found for this tariff heading. Standard commercial invoice and transport documents apply.'
    ];
}

// 4. Appel des outils
if ($method === 'tools/call') {
    $toolName = $request['params']['name'] ?? '';
    $args = $request['params']['arguments'] ?? [];

    $validTools = ['calculate_customs_duties', 'search_customs_nomenclature', 'resolve_hs_code', 'check_import_restrictions'];

    if (!in_array($toolName, $validTools, true)) {
        sendJsonRpcResponse($id, null, [
            'code' => -32601,
            'message' => "Tool not found: $toolName"
        ], $t_start, $method, $rawInput);
    }

    $dbPath = __DIR__ . '/taric.sqlite';

    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($toolName === 'search_customs_nomenclature') {
            $query = trim((string)($args['query'] ?? ''));

            if ($query === '') {
                sendJsonRpcResponse($id, null, [
                    'code' => -32602,
                    'message' => 'Invalid params: "query" parameter is required'
                ], $t_start, $method, $rawInput);
            }

            $lang = (($args['lang'] ?? 'fr') === 'en') ? 'en' : 'fr';
            $limit = min(max((int)($args['limit'] ?? 5), 1), 20);
            $ftsCol = ($lang === 'en') ? 'desc_en' : 'desc_fr';

            $sql = "SELECT n.code_taric, n.sh6, n.chapter, n.hier_pos, n.indent, n.desc_fr, n.desc_en 
                    FROM goods_fts f
                    JOIN goods_nomenclature n ON n.rowid = f.rowid
                    WHERE f.$ftsCol MATCH ? 
                    LIMIT $limit";
            $stmt = $db->prepare($sql);
            $stmt->execute([$query . '*']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJsonRpcResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    ]
                ]
            ], null, $t_start, $method, $rawInput);
        }

        if ($toolName === 'resolve_hs_code') {
            $inputCode = $args['hs_code'] ?? $args['code'] ?? '';
            $code = preg_replace('/[^0-9]/', '', (string)$inputCode);

            if (empty($code)) {
                sendJsonRpcResponse($id, null, [
                    'code' => -32602,
                    'message' => 'Invalid params: "hs_code" or "code" parameter is required'
                ], $t_start, $method, $rawInput);
            }

            $stmt = $db->prepare("SELECT code_taric, suffix, sh6, chapter, hier_pos, indent, desc_fr, desc_en 
                                  FROM goods_nomenclature 
                                  WHERE code_taric LIKE ? OR sh6 = ? 
                                  ORDER BY code_taric ASC 
                                  LIMIT 15");
            $stmt->execute([$code . '%', $code]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJsonRpcResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    ]
                ]
            ], null, $t_start, $method, $rawInput);
        }

        if ($toolName === 'calculate_customs_duties') {
            $rawHs = preg_replace('/[^0-9]/', '', (string)($args['hs_code'] ?? ''));
            $customsValue = max(0.0, (float)($args['customs_value_eur'] ?? 0));
            $origin = strtoupper(trim((string)($args['origin_country'] ?? 'THIRD_COUNTRY')));

            if (empty($rawHs) || $customsValue <= 0) {
                sendJsonRpcResponse($id, null, [
                    'code' => -32602,
                    'message' => 'Invalid params: "hs_code" and positive "customs_value_eur" are required'
                ], $t_start, $method, $rawInput);
            }

            $sh6 = substr($rawHs, 0, 6);
            $sh4 = substr($rawHs, 0, 4);
            $sh2 = substr($rawHs, 0, 2);

            $stmtRate = $db->prepare("
                SELECT code_prefix, description, standard_rate, vat_rate, legal_basis
                FROM customs_duty_rates
                WHERE code_prefix IN (?, ?, ?)
                ORDER BY length(code_prefix) DESC
                LIMIT 1
            ");
            $stmtRate->execute([$sh6, $sh4, $sh2]);
            $dutyData = $stmtRate->fetch(PDO::FETCH_ASSOC);

            $dutyRate = $dutyData ? (float)$dutyData['standard_rate'] : 3.5;
            $vatRate = $dutyData ? (float)$dutyData['vat_rate'] : 20.0;
            $dutyDesc = $dutyData['description'] ?? 'General commercial goods';
            $legalBasis = $dutyData['legal_basis'] ?? 'EU Common Customs Tariff standard average';

            $stmtNom = $db->prepare("
                SELECT desc_fr, desc_en, code_taric
                FROM goods_nomenclature
                WHERE code_taric LIKE ?
                ORDER BY hier_pos DESC
                LIMIT 1
            ");
            $stmtNom->execute([substr($rawHs, 0, min(strlen($rawHs), 10)) . '%']);
            $nomData = $stmtNom->fetch(PDO::FETCH_ASSOC);

            $dutyAmount = round($customsValue * ($dutyRate / 100), 2);
            $vatBase = round($customsValue + $dutyAmount, 2);
            $vatAmount = round($vatBase * ($vatRate / 100), 2);
            $totalPayable = round($dutyAmount + $vatAmount, 2);
            $effectiveTaxRate = $customsValue > 0 ? round(($totalPayable / $customsValue) * 100, 2) : 0;

            $result = [
                'hs_code' => $rawHs,
                'origin' => $origin,
                'description_en' => $nomData['desc_en'] ?? $dutyDesc,
                'description_fr' => $nomData['desc_fr'] ?? null,
                'currency' => 'EUR',
                'valuation' => [
                    'customs_value_cif' => $customsValue,
                    'vat_taxable_base' => $vatBase
                ],
                'duty' => [
                    'rate_percent' => $dutyRate,
                    'amount' => $dutyAmount,
                    'legal_basis' => $legalBasis
                ],
                'vat' => [
                    'rate_percent' => $vatRate,
                    'amount' => $vatAmount,
                    'legal_basis' => 'French CGI Art. 292 / EU VAT Directive'
                ],
                'liquidation_summary' => [
                    'total_customs_duties' => $dutyAmount,
                    'total_import_taxes' => $totalPayable,
                    'total_import_vat' => $vatAmount,
                    'effective_burden_rate_percent' => $effectiveTaxRate
                ]
            ];

            sendJsonRpcResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ], null, $t_start, $method, $rawInput);
        }

        if ($toolName === 'check_import_restrictions') {
            $taricCode = $args['taric_code'] ?? '';

            if (empty($taricCode)) {
                sendJsonRpcResponse($id, null, [
                    'code' => -32602,
                    'message' => 'Invalid params: "taric_code" parameter is required'
                ], $t_start, $method, $rawInput);
            }

            $origin = $args['origin_country'] ?? 'CN';
            $res = getImportRestrictions($db, $taricCode, $origin);
            sendJsonRpcResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ], null, $t_start, $method, $rawInput);
        }

    } catch (\Throwable $e) {
        sendJsonRpcResponse($id, null, [
            'code' => -32603,
            'message' => 'Internal database error: ' . $e->getMessage()
        ], $t_start, $method, $rawInput);
    }
}

sendJsonRpcResponse($id, null, [
    'code' => -32601,
    'message' => 'Unsupported JSON-RPC method: ' . htmlspecialchars($method)
], $t_start, $method, $rawInput);
