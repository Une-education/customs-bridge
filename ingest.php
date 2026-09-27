<?php
declare(strict_types=1);

$fileFr = $argv[1] ?? __DIR__ . '/data/Nomenclature_FR.csv';
$fileEn = $argv[2] ?? __DIR__ . '/data/Nomenclature_EN.csv';
$dbPath = __DIR__ . '/taric.sqlite';

if (!file_exists($fileFr) || !file_exists($fileEn)) {
    fwrite(STDERR, "Erreur : Les fichiers CSV FR ou EN sont manquants dans data/.\n");
    fwrite(STDERR, "Attendu : $fileFr et $fileEn\n");
    exit(1);
}

$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Appliquer le schéma
$schema = file_get_contents(__DIR__ . '/schema.sql');
$pdo->exec($schema);

function cleanTaricCode(string $raw): array {
    $raw = trim($raw);
    $parts = preg_split('/\s+/', $raw);
    $code = preg_replace('/[^0-9]/', '', $parts[0] ?? '');
    $suffix = preg_replace('/[^0-9]/', '', $parts[1] ?? '80');
    return [str_pad($code, 10, '0'), $suffix ?: '80'];
}

function detectDelimiter(string $filePath): string {
    $h = fopen($filePath, 'r');
    $line = fgets($h);
    fclose($h);
    if (strpos($line, "\t") !== false) return "\t";
    if (strpos($line, ";") !== false) return ";";
    return ",";
}

echo "=== Ingestion Nomenclature Douanière (TARIC) ===\n";

// 1. Ingestion FR
$delimFr = detectDelimiter($fileFr);
echo "--> Ingestion FR depuis $fileFr (séparateur: " . ($delimFr === "\t" ? "TAB" : $delimFr) . ")...\n";

$handleFr = fopen($fileFr, 'r');
$header = fgetcsv($handleFr, 0, $delimFr);

$stmtInsert = $pdo->prepare("
    INSERT OR REPLACE INTO goods_nomenclature 
    (code_taric, suffix, hier_pos, indent, desc_fr, valid_from, valid_to)
    VALUES (:code, :suffix, :hier, :indent, :desc_fr, :valid_from, :valid_to)
");

$pdo->beginTransaction();
$countFr = 0;
while (($row = fgetcsv($handleFr, 0, $delimFr)) !== false) {
    if (empty($row[0])) continue;
    [$code, $suffix] = cleanTaricCode($row[0]);
    $validFrom = trim($row[1] ?? '');
    $validTo   = trim($row[2] ?? '');
    $hier      = (int)($row[4] ?? 0);
    $indent    = (int)($row[5] ?? 0);
    $descFr    = trim($row[6] ?? '');

    $stmtInsert->execute([
        ':code'       => $code,
        ':suffix'     => $suffix,
        ':hier'       => $hier,
        ':indent'     => $indent,
        ':desc_fr'    => $descFr,
        ':valid_from' => $validFrom,
        ':valid_to'   => $validTo !== '' ? $validTo : null
    ]);
    $countFr++;
}
$pdo->commit();
fclose($handleFr);
echo "OK : $countFr lignes insérées pour le français.\n";

// 2. Fusion EN
$delimEn = detectDelimiter($fileEn);
echo "--> Enrichissement EN depuis $fileEn...\n";

$handleEn = fopen($fileEn, 'r');
$header = fgetcsv($handleEn, 0, $delimEn);

$stmtUpdate = $pdo->prepare("
    UPDATE goods_nomenclature 
    SET desc_en = :desc_en 
    WHERE code_taric = :code AND suffix = :suffix
");

$pdo->beginTransaction();
$countEn = 0;
while (($row = fgetcsv($handleEn, 0, $delimEn)) !== false) {
    if (empty($row[0])) continue;
    [$code, $suffix] = cleanTaricCode($row[0]);
    $descEn = trim($row[6] ?? '');

    $stmtUpdate->execute([
        ':desc_en' => $descEn,
        ':code'    => $code,
        ':suffix'  => $suffix
    ]);
    $countEn++;
}
$pdo->commit();
fclose($handleEn);
echo "OK : $countEn libellés anglais fusionnés.\n";

// Optimisation FTS5
echo "--> Optimisation de l'index FTS5...\n";
$pdo->exec("INSERT INTO goods_fts(goods_fts) VALUES('optimize');");

echo "=== Base taric.sqlite créée avec succès ! ===\n";
