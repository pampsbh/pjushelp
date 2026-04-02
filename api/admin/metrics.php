<?php
require_once dirname(__DIR__) . '/config.php';

admin();
$db   = getDB();
$type = $_GET['type'] ?? 'main';
refreshSla();

switch ($type) {

    case 'monthly':
        $s = $db->query("
            SELECT
                DATE_FORMAT(created_at,'%Y-%m')  AS month,
                DATE_FORMAT(created_at,'%b/%Y')  AS label,
                COUNT(*)                          AS opened,
                SUM(CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved
            FROM tickets
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at,'%Y-%m')
            ORDER BY month ASC
        ");
        respond($s->fetchAll());
        break;

    case 'by-dept':
        $s = $db->query("
            SELECT d.name AS department, COUNT(t.id) AS total
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            WHERE MONTH(t.created_at) = MONTH(NOW())
              AND YEAR(t.created_at)  = YEAR(NOW())
            GROUP BY t.department_id, d.name
        ");
        respond($s->fetchAll());
        break;

    case 'resolution-cats':
        $s = $db->query("
            SELECT COALESCE(rc.label, 'Sem categoria') AS label,
                   COALESCE(rc.slug,  'unknown')        AS slug,
                   COUNT(t.id) AS total
            FROM tickets t
            LEFT JOIN resolution_categories rc ON rc.id = t.resolution_category_id
            WHERE t.status IN ('resolved','closed')
              AND MONTH(t.last_closed_at) = MONTH(NOW())
              AND YEAR(t.last_closed_at)  = YEAR(NOW())
            GROUP BY rc.id, rc.label, rc.slug
            ORDER BY total DESC
        ");
        respond($s->fetchAll());
        break;

    case 'quality':
        $s = $db->query("
            SELECT DATE_FORMAT(t.last_closed_at,'%Y-%m')  AS month,
                   DATE_FORMAT(t.last_closed_at,'%b/%Y')  AS label,
                   COALESCE(rc.slug,  'unknown')           AS slug,
                   COALESCE(rc.label, 'Sem categoria')     AS cat_label,
                   COUNT(*) AS total
            FROM tickets t
            LEFT JOIN resolution_categories rc ON rc.id = t.resolution_category_id
            WHERE t.status IN ('resolved','closed')
              AND t.last_closed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(t.last_closed_at,'%Y-%m'), rc.id
            ORDER BY month ASC
        ");
        respond($s->fetchAll());
        break;

    default: // main
        $open     = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
        $priority = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE is_priority=1 AND status NOT IN ('resolved','closed')")->fetchColumn();
        $breached = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE sla_breached=1 AND status NOT IN ('resolved','closed')")->fetchColumn();

        $monthTotal  = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
        $monthOnTime = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND status IN('resolved','closed') AND sla_breached=0")->fetchColumn();
        $rate = $monthTotal > 0 ? round(($monthOnTime / $monthTotal) * 100, 1) : 0;

        $reopened = (int)$db->query("
            SELECT COUNT(*) FROM tickets
            WHERE reopen_count > 0 AND status IN ('resolved','closed')
        ")->fetchColumn();

        $closedMonth = (int)$db->query("
            SELECT COUNT(*) FROM tickets
            WHERE status IN ('resolved','closed')
              AND MONTH(last_closed_at) = MONTH(NOW())
              AND YEAR(last_closed_at)  = YEAR(NOW())
        ")->fetchColumn();
        $reopenedMonth = (int)$db->query("
            SELECT COUNT(*) FROM tickets
            WHERE reopen_count > 0 AND status IN ('resolved','closed')
              AND MONTH(last_closed_at) = MONTH(NOW())
              AND YEAR(last_closed_at)  = YEAR(NOW())
        ")->fetchColumn();
        $reopenRate = $closedMonth > 0 ? round(($reopenedMonth / $closedMonth) * 100, 1) : 0;

        respond([
            'open'            => $open,
            'priority'        => $priority,
            'breached'        => $breached,
            'resolution_rate' => $rate,
            'reopened'        => $reopened,
            'reopen_rate'     => $reopenRate,
        ]);
}
