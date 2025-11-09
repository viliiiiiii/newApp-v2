<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

$results = [];

function run_check(string $label, callable $callback): void
{
    global $results;
    try {
        $status = $callback();
        $results[] = [$label, $status === true, $status === true ? 'ok' : (string)$status];
    } catch (Throwable $e) {
        $results[] = [$label, false, $e->getMessage()];
    }
}

run_check('Database connection', function () {
    $pdo = get_pdo();
    $pdo->query('SELECT 1');
    return true;
});

run_check('Permissions tables', function () {
    $pdo = get_pdo('core');
    $tables = ['roles', 'permissions', 'role_permissions', 'user_roles'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table');
        $stmt->execute([
            ':schema' => DB_NAME,
            ':table'  => $table,
        ]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $table . ' missing';
        }
    }
    return true;
});

run_check('Config mail.php', function () {
    return file_exists(MAIL_CONFIG_PATH) ? true : 'config/mail.php missing';
});

run_check('Config push.php', function () {
    return file_exists(PUSH_CONFIG_PATH) ? true : 'config/push.php missing';
});

run_check('Essential routes', function () {
    $required = ['index.php', 'inventory.php', 'tasks.php', 'notes/index.php'];
    foreach ($required as $file) {
        if (!file_exists(__DIR__ . '/../' . $file)) {
            return $file . ' missing';
        }
    }
    return true;
});

$allPassed = true;
foreach ($results as $row) {
    [$label, $ok, $message] = $row;
    $status = $ok ? '[OK]' : '[FAIL]';
    if (!$ok) {
        $allPassed = false;
    }
    echo $status . ' ' . $label . ': ' . $message . PHP_EOL;
}

if (!$allPassed) {
    exit(1);
}
