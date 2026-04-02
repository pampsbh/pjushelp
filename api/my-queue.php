<?php
require_once __DIR__ . '/config.php';

$user = me();
if ($user['role'] !== 'admin' || empty($user['attendant_profile_id'])) {
    fail('Acesso restrito a atendentes', 403);
}

$db = getDB();
refreshSla();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método não permitido', 405);

$stmt = $db->prepare("
    SELECT t.id, t.status, t.is_priority, t.sla_deadline, t.sla_breached,
           t.created_at, t.priority_reason, t.sla_hours, t.assignment_type,
           u.name   AS user_name,
           d.name   AS department_name,
           sv.name  AS service_name
    FROM tickets t
    JOIN users       u  ON u.id  = t.user_id
    JOIN departments d  ON d.id  = t.department_id
    JOIN services    sv ON sv.id = t.service_id
    WHERE t.assigned_to = ?
      AND t.status IN ('open','in_progress')
    ORDER BY t.sla_breached DESC, t.is_priority DESC, t.sla_deadline ASC
");
$stmt->execute([$user['id']]);
$tickets = $stmt->fetchAll();

respond(['data' => $tickets, 'total' => count($tickets)]);
