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

// Réponse JSON-RPC + logging dans data/customs.db
//function sendJsonRpcResponse(?int $id, ?array $result, ?array $error, float $t_start, ?string $method, string $rawInput): void {
function sendJsonRpcResponse(string|int|null $id, ?array $result, ?array $error, float $t_start, ?string $method, string $rawInput): void  {
  $response = ['jsonrpc' => '2.0'];
    if ($id !== null) {
        $response['id'] = $id;
    }
    if ($error !== null) {
        $response['error'] = $error;
    } else {
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
                exec_time_ms REAL
            );");
            $execMs = round((microtime(true) - $t_start) * 1000, 2);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt = $logDb->prepare("INSERT INTO mcp_logs (ip, method, user_agent, payload, exec_time_ms) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$ip, $method ?? 'unknown', $ua, substr($rawInput, 0, 500), $execMs]);
        }
    } catch (\Throwable $ignored) {}

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Requête GET ou payload vide
if (!$request || !isset($request['method'])) {
    sendJsonRpcResponse(null, [
        'name' => 'CustomsBridge TARIC Engine',
        'status' => 'active',
        'protocol' => 'MCP Streamable-HTTP / JSON-RPC 2.0',
        'endpoint' => 'https://customs.une.education/mcp.php',
        'pricing' => [
            'model' => 'freemium',
            'rate_limit' => '60 requests/minute',
            'free_tier' => [
                'included_calls' => 1000,
                'metered_methods' => ['tools/call']
            ],
            'plans' => [
                [
                    'id' => 'dev_50k',
                    'name' => 'Developer Access Pack',
                    'credits' => 50000,
                    'price_eur' => 29.00,
                    'checkout_url' => 'https://legal.une.education/checkout.php?pack=dev_50k'
                ],
                [
                    'id' => 'pro_200k',
                    'name' => 'Enterprise Access Pack',
                    'credits' => 200000,
                    'price_eur' => 89.00,
                    'checkout_url' => 'https://legal.une.education/checkout.php?pack=pro_200k'
                ]
            ]
        ]
    ], null, $t_start, 'GET/info', $rawInput ?: '');
}

$id = $request['id'] ?? null;
$method = $request['method'];

// 1. Initialisation MCP
if ($method === 'initialize') {
    sendJsonRpcResponse($id, [
        'protocolVersion' => '2024-11-05',
        'capabilities' => [
            'tools' => [
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
                'listChanged' => false
            ]
        ],
        'serverInfo' => [
            'name' => 'customs-bridge',
            'version' => '1.0.0'
        ]
    ], null, $t_start, $method, $rawInput);
}

// 2. Notifications MCP
if ($method === 'notifications/initialized') {
    exit;
}

// 3. Liste des outils
if ($method === 'tools/list') {
    sendJsonRpcResponse($id, [
        'tools' => [
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
                'description' => 'Recherche textuelle bilingue (FR/EN) dans la nomenclature combinée de l\'Union Européenne (codes SH6/TARIC) via indexation FTS5.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Terme de recherche en français ou anglais (ex: "photovoltaic", "café", "aluminium", "lithium")'
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
                            'description' => 'Nombre maximal de résultats'
                        ]
                    ],
                    'required' => ['query']
                ]
            ],
            [
                'name' => 'resolve_hs_code',
                'description' => 'Résout l\'arborescence hiérarchique et la désignation officielle d\'un code SH6 ou TARIC (de 2 à 10 chiffres).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'hs_code' => [
                            'type' => 'string',
                            'description' => 'Code SH (6 chiffres) ou TARIC (10 chiffres) à analyser (ex: "854143", "8501710000")'
                        ],
                        'code' => [
                            'type' => 'string',
                            'description' => 'Alias pour hs_code'
                        ]
                    ]
                ]
            ]
        ]
    ], null, $t_start, $method, $rawInput);
}

// 4. Appel des outils
if ($method === 'tools/call') {
    $toolName = $request['params']['name'] ?? '';
    $args = $request['params']['arguments'] ?? [];

    $dbPath = __DIR__ . '/taric.sqlite';

    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($toolName === 'search_customs_nomenclature') {
            $query = trim($args['query'] ?? '');
            $lang = ($args['lang'] ?? 'fr') === 'en' ? 'en' : 'fr';
            $limit = min((int)($args['limit'] ?? 5), 20);

            $ftsCol = ($lang === 'en') ? 'desc_en' : 'desc_fr';
            
            // Requête FTS5 sur goods_fts joint à goods_nomenclature
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
                    'message' => 'Paramètre code ou hs_code manquant ou invalide'
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
                    'message' => 'Invalid parameters: hs_code and positive customs_value_eur are required'
                ], $t_start, 'tools/call:calculate_customs_duties', $rawInput);
            }

            // Recherche du taux le plus spécifique (SH6 -> SH4 -> SH2)
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

            // Taux par défaut si non répertorié dans les tables spécifiques
            $dutyRate = $dutyData ? (float)$dutyData['standard_rate'] : 3.5;
            $vatRate = $dutyData ? (float)$dutyData['vat_rate'] : 20.0;
            $dutyDesc = $dutyData['description'] ?? 'General commercial goods';
            $legalBasis = $dutyData['legal_basis'] ?? 'EU Common Customs Tariff standard average';

            // Recherche du libellé officiel dans la nomenclature si présent
            $stmtNom = $db->prepare("
                SELECT desc_fr, desc_en, code_taric
                FROM goods_nomenclature
                WHERE code_taric LIKE ?
                ORDER BY hier_pos DESC
                LIMIT 1
            ");
            $stmtNom->execute([substr($rawHs, 0, min(strlen($rawHs), 10)) . '%']);
            $nomData = $stmtNom->fetch(PDO::FETCH_ASSOC);

            // Calculs liquidatifs
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
                    'total_import_vat' => $vatAmount,
                    'total_import_taxes' => $totalPayable,
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
            ], null, $t_start, 'tools/call:calculate_customs_duties', $rawInput);
        }
        sendJsonRpcResponse($id, null, [
            'code' => -32601,
            'message' => 'Tool non trouvé: ' . $toolName
        ], $t_start, $method, $rawInput);

    } catch (\Throwable $e) {
        sendJsonRpcResponse($id, null, [
            'code' => -32603,
            'message' => 'Erreur base de données: ' . $e->getMessage()
        ], $t_start, $method, $rawInput);
    }
}

// Méthode inconnue
sendJsonRpcResponse($id, null, [
    'code' => -32601,
    'message' => 'Méthode JSON-RPC non supportée: ' . htmlspecialchars((string)$method)
], $t_start, $method, $rawInput);
