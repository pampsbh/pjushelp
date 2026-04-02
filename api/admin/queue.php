<?php
require_once dirname(__DIR__) . '/config.php';

admin();
$db = getDB();
refreshSla();

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];

if (!empty($_GET['status']))        { $where[] = 't.status = ?';        $params[] = $_GET['status']; }
if (!empty($_GET['department_id'])) { $where[] = 't.department_id = ?'; $params[] = $_GET['department_id']; }
if (!empty($_GET['assigned_to']))   { $where[] = 't.assigned_to = ?';   $params[] = $_GET['assigned_to']; }
if (!empty($_GET['is_priority']))   { $where[] = 't.is_priority = 1'; }
if (!empty($_GET['sla_breached']))  { $where[] = 't.sla_breached = 1'; }
if (!empty($_GET['unassigned']))    { $where[] = 't.assigned_to IS NULL'; }

$w = implode(' AND ', $where);

$cntStmt = $db->prepare("SELECT COUNT(*) FROM tickets t WHERE $w");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$listStmt = $db->prepare("
    SELECT t.id, t.status, t.is_priority, t.sla_deadline, t.sla_breached,
           t.created_at, t.updated_at, t.priority_reason, t.sla_hours,
           t.assigned_to, t.assigned_at, t.assignment_type, t.reopen_count,
           u.name   AS user_name,
           d.name   AS department_name,
           sv.name  AS service_name,
           att.name AS attendant_name,
           ap.name  AS attendant_profile
    FROM tickets t
    JOIN users       u   ON u.id   = t.user_id
    JOIN departments d   ON d.id   = t.department_id
    JOIN services    sv  ON sv.id  = t.service_id
    LEFT JOIN users           att ON att.id = t.assigned_to
    LEFT JOIN attendant_profiles ap ON ap.id = att.attendant_profile_id
    WHERE $w
    ORDER BY t.sla_breached DESC, t.is_priority DESC, t.created_at DESC
    LIMIT ? OFFSET ?
");
$listStmt->execute(array_merge($params, [$limit, $offset]));

respond([
    'data'  => $listStmt->fetchAll(),
    'total' => $total,
    'page'  => $page,
    'limit' => $limit,
    'pages' => (int)ceil($total / $limit),
]);
