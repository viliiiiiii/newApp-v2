<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$timestamp = date('Ymd_His');
$logFile = $logDir . '/migrate_' . $timestamp . '.log';

$logger = function (string $message) use ($logFile): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
};

$logger('--- ABRM migration started ---');

$serverDsn = sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET);
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $rootPdo = new PDO($serverDsn, DB_USER, DB_PASS, $options);
} catch (Throwable $e) {
    $logger('ERROR: Unable to connect to MySQL server - ' . $e->getMessage());
    exit(1);
}

try {
    $rootPdo->exec('CREATE DATABASE IF NOT EXISTS `abrm` CHARACTER SET ' . DB_CHARSET . ' COLLATE utf8mb4_unicode_ci');
    $logger('Database `abrm` ensured.');
} catch (Throwable $e) {
    $logger('ERROR: Unable to create database `abrm` - ' . $e->getMessage());
    exit(1);
}

$rootPdo->exec('USE `abrm`');

$files = [
    __DIR__ . '/core_db.sql',
    __DIR__ . '/punchlist.sql',
    __DIR__ . '/abrm_permissions.sql',
];

$renameMap = [];
$identicalTables = [];

foreach ($files as $file) {
    if (!file_exists($file)) {
        $logger('WARNING: Missing file ' . basename($file) . ' - skipping.');
        continue;
    }
    $logger('Processing ' . basename($file));
    $sql = file_get_contents($file);
    if ($sql === false) {
        $logger('WARNING: Unable to read file, skipping.');
        continue;
    }
    foreach (split_sql_statements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        if (preg_match('/^CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $statement, $matches)) {
            $tableName = $matches[1];
            $normalizedExisting = existing_table_definition($rootPdo, $tableName);
            if ($normalizedExisting !== null) {
                $normalizedIncoming = normalize_sql($statement);
                if ($normalizedExisting === $normalizedIncoming) {
                    $identicalTables[$tableName] = true;
                    $logger("Table {$tableName} already present with identical schema. Skipping create.");
                    continue;
                }

                $suffix = '_v2';
                $candidate = $tableName . $suffix;
                while (table_exists($rootPdo, $candidate)) {
                    $suffix = '_' . uniqid('v');
                    $candidate = $tableName . $suffix;
                }
                $logger("Table {$tableName} exists with different schema. Renaming import to {$candidate}.");
                $renameMap[$tableName] = $candidate;
                $statement = preg_replace('/^CREATE\s+TABLE\s+`?' . preg_quote($tableName, '/') . '`?/i', 'CREATE TABLE `' . $candidate . '`', $statement, 1);
            }
        } elseif (preg_match('/^(INSERT|REPLACE)\s+INTO\s+`?([A-Za-z0-9_]+)`?/i', $statement, $matches)) {
            $targetTable = $matches[2];
            if (isset($renameMap[$targetTable])) {
                $newTable = $renameMap[$targetTable];
                $statement = preg_replace('/^(INSERT|REPLACE)(\s+INTO\s+)`?' . preg_quote($targetTable, '/') . '`?/i', '$1$2`' . $newTable . '`', $statement, 1);
            } elseif (isset($identicalTables[$targetTable])) {
                $statement = preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $statement, 1);
            }
        } elseif (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $statement, $matches)) {
            $targetTable = $matches[1];
            if (isset($renameMap[$targetTable])) {
                $newTable = $renameMap[$targetTable];
                $statement = preg_replace('/^ALTER\s+TABLE\s+`?' . preg_quote($targetTable, '/') . '`?/i', 'ALTER TABLE `' . $newTable . '`', $statement, 1);
            } elseif (isset($identicalTables[$targetTable])) {
                $logger('Skipping ALTER for table ' . $targetTable . ' because schema already present.');
                continue;
            }
        }

        try {
            $rootPdo->exec($statement);
        } catch (Throwable $e) {
            $logger('ERROR executing statement: ' . substr($statement, 0, 120) . '... - ' . $e->getMessage());
        }
    }
}

$logger('--- ABRM migration completed ---');

function split_sql_statements(string $sql): array
{
    $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql);
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(--|#)/', $line)) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $clean = implode("\n", $cleanLines);

    $statements = [];
    $buffer = '';
    $length = strlen($clean);
    $inString = false;
    $stringChar = '';

    for ($i = 0; $i < $length; $i++) {
        $char = $clean[$i];
        if ($inString) {
            if ($char === $stringChar) {
                $prev = $clean[$i - 1] ?? '';
                if ($prev !== '\\') {
                    $inString = false;
                    $stringChar = '';
                }
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '\'' || $char === '"') {
            $inString = true;
            $stringChar = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statements[] = $buffer;
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }

    return $statements;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table');
    $stmt->execute([
        ':schema' => DB_NAME,
        ':table'  => $table,
    ]);
    return (bool)$stmt->fetchColumn();
}

function existing_table_definition(PDO $pdo, string $table): ?string
{
    if (!table_exists($pdo, $table)) {
        return null;
    }
    $stmt = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $createSql = $row['Create Table'] ?? array_values($row)[1] ?? '';
    return normalize_sql($createSql);
}

function normalize_sql(string $sql): string
{
    $sql = strtolower(trim($sql));
    $sql = preg_replace('/\s+/', ' ', $sql);
    return $sql;
}
