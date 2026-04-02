<?php
require_once dirname(__DIR__) . '/config.php';

admin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

switch ($method) {

    case 'GET':
        $rows = $db->query("
            SELECT u.id, u.name, u.email,
                   ap.name  AS profile_name,
                   aa.is_available, aa.max_open_tickets,
                   COALESCE((
                       SELECT COUNT(*) FROM tickets
                       WHERE assigned_to = u.id AND status = 'open'
                   ), 0) AS open_count,
                   COALESCE((
                       SELECT COUNT(*) FROM tickets
                       WHERE assigned_to = u.id AND status = 'in_progress'
                   ), 0) AS in_progress_count,
                   COALESCE((
                       SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)), 1)
                       FROM tickets
                       WHERE assigned_to = u.id
                         AND status IN ('resolved','closed')
                         AND resolved_at IS NOT NULL
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   ), 0) AS avg_resolution_hours
            FROM users u
            JOIN attendant_profiles      ap ON ap.id = u.attendant_profile_id
            JOIN attendant_availability  aa ON aa.user_id = u.id
            WHERE u.role = 'admin' AND u.attendant_profile_id IS NOT NULL
            ORDER BY ap.name, u.name
        ")->fetchAll();
        respond($rows);

    case 'PATCH':
        if (!$id) fail('ID não informado');
        $b = body();
        if (!isset($b['isAvailable'])) fail('isAvailable é obrigatório');

        $db->prepare("
            INSERT INTO attendant_availability (user_id, is_available)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE is_available = ?, updated_at = NOW()
        ")->execute([$id, $b['isAvailable'] ? 1 : 0, $b['isAvailable'] ? 1 : 0]);

        respond(['ok' => true]);

    default:
        fail('Método não permitido', 405);
}
