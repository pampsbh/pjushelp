<?php
require_once dirname(__DIR__) . '/config.php';

$user   = fullAdmin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Garante linha existente
if ((int)$db->query("SELECT COUNT(*) FROM reopen_settings")->fetchColumn() === 0) {
    $db->exec("INSERT INTO reopen_settings (max_days_to_reopen) VALUES (7)");
}

switch ($method) {

    case 'GET':
        respond($db->query("SELECT * FROM reopen_settings LIMIT 1")->fetch());

    case 'PATCH':
        $b = body();
        $days = (int)($b['maxDays'] ?? 0);
        if ($days < 1) fail('maxDays deve ser no mínimo 1');

        $db->prepare(
            "UPDATE reopen_settings SET max_days_to_reopen = ?, updated_by = ?, updated_at = NOW() LIMIT 1"
        )->execute([$days, $user['id']]);

        respond(['ok' => true, 'max_days_to_reopen' => $days]);

    default:
        fail('Método não permitido', 405);
}
