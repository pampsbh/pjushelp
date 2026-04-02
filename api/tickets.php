<?php
require_once __DIR__ . '/config.php';

$user   = me();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id']     ?? null;
$action = $_GET['action'] ?? null;
$db     = getDB();

refreshSla();

switch ($method) {

    // ── GET ──────────────────────────────────────────────────────────────────
    case 'GET':
        if ($id) {
            $s = $db->prepare("
                SELECT t.*,
                       u.name   AS user_name,   u.email  AS user_email,
                       d.name   AS department_name,
                       sv.name  AS service_name,
                       att.name AS attendant_name,
                       ap.name  AS attendant_profile,
                       rc.slug  AS resolution_slug,
                       rc.label AS resolution_label
                FROM tickets t
                JOIN users       u   ON u.id   = t.user_id
                JOIN departments d   ON d.id   = t.department_id
                JOIN services    sv  ON sv.id  = t.service_id
                LEFT JOIN users               att ON att.id = t.assigned_to
                LEFT JOIN attendant_profiles  ap  ON ap.id  = att.attendant_profile_id
                LEFT JOIN resolution_categories rc ON rc.id  = t.resolution_category_id
                WHERE t.id = ?
            ");
            $s->execute([$id]);
            $t = $s->fetch();
            if (!$t) fail('Chamado não encontrado', 404);
            if ($user['role'] !== 'admin' && $t['user_id'] != $user['id']) fail('Acesso negado', 403);

            $h = $db->prepare("
                SELECT th.*, u.name AS changed_by_name
                FROM ticket_history th JOIN users u ON u.id = th.changed_by
                WHERE th.ticket_id = ? ORDER BY th.created_at ASC
            ");
            $h->execute([$id]);
            $t['history'] = $h->fetchAll();

            $a = $db->prepare("SELECT * FROM ticket_attachments WHERE ticket_id = ?");
            $a->execute([$id]);
            $t['attachments'] = $a->fetchAll();

            // Relações — apenas para admins/atendentes
            if ($user['role'] === 'admin') {
                $rel = $db->prepare("
                    SELECT tr.id, tr.relation_type, tr.created_at,
                           t2.id     AS related_id,
                           t2.status AS related_status,
                           sv2.name  AS service_name,
                           u2.name   AS user_name
                    FROM ticket_relations tr
                    JOIN tickets  t2   ON t2.id  = tr.related_id
                    JOIN services sv2  ON sv2.id = t2.service_id
                    JOIN users    u2   ON u2.id  = t2.user_id
                    WHERE tr.ticket_id = ?
                    ORDER BY tr.created_at DESC
                ");
                $rel->execute([$id]);
                $t['relations'] = $rel->fetchAll();
            }

            respond($t);
        }

        $page   = max(1, (int)($_GET['page']  ?? 1));
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $where  = ['1=1'];
        $params = [];

        if ($user['role'] !== 'admin') {
            $where[] = 't.user_id = ?'; $params[] = $user['id'];
        } else {
            if (!empty($_GET['status']))        { $where[] = 't.status = ?';        $params[] = $_GET['status']; }
            if (!empty($_GET['department_id'])) { $where[] = 't.department_id = ?'; $params[] = $_GET['department_id']; }
            if (!empty($_GET['is_priority']))   { $where[] = 't.is_priority = 1'; }
            if (!empty($_GET['sla_breached']))  { $where[] = 't.sla_breached = 1'; }
            if (!empty($_GET['from']))          { $where[] = 't.created_at >= ?';   $params[] = $_GET['from']; }
            if (!empty($_GET['to']))            { $where[] = 't.created_at <= ?';   $params[] = $_GET['to'] . ' 23:59:59'; }
        }

        $w = implode(' AND ', $where);

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM tickets t WHERE $w");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $listParams = array_merge($params, [$limit, $offset]);
        $listStmt = $db->prepare("
            SELECT t.id, t.status, t.is_priority, t.sla_deadline, t.sla_breached,
                   t.created_at, t.updated_at, t.priority_reason, t.sla_hours,
                   t.assigned_to, t.assigned_at, t.assignment_type,
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
            ORDER BY t.is_priority DESC, t.sla_breached DESC, t.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $listStmt->execute($listParams);

        respond([
            'data'  => $listStmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int)ceil($total / $limit),
        ]);

    // ── POST ─────────────────────────────────────────────────────────────────
    case 'POST':
        $b         = body();
        $deptId    = (int)($b['department_id'] ?? 0);
        $serviceId = (int)($b['service_id']    ?? 0);
        $desc      = trim($b['description']    ?? '');

        if (!$deptId || !$serviceId || mb_strlen($desc) < 20) {
            fail('Preencha todos os campos (mínimo 20 caracteres na descrição)');
        }

        $svc = $db->prepare("SELECT * FROM services WHERE id = ? AND department_id = ?");
        $svc->execute([$serviceId, $deptId]);
        $service = $svc->fetch();
        if (!$service) fail('Serviço inválido para este departamento');

        // Prioridade automática
        $isPriority = false; $priorityReason = null;
        foreach ($db->query("SELECT * FROM priority_rules WHERE is_active = 1")->fetchAll() as $rule) {
            $match = match ($rule['condition_type']) {
                'keyword'    => stripos($desc, $rule['condition_value']) !== false,
                'department' => (string)$deptId    === (string)$rule['condition_value'],
                'service'    => (string)$serviceId === (string)$rule['condition_value'],
                default      => false,
            };
            if ($match) { $isPriority = true; $priorityReason = $rule['name']; break; }
        }

        $slaHours = $service['sla_hours'];
        $ins = $db->prepare("
            INSERT INTO tickets
                (user_id, department_id, service_id, description, is_priority, priority_reason, sla_hours, sla_deadline)
            VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
        ");
        $ins->execute([$user['id'], $deptId, $serviceId, $desc, $isPriority ? 1 : 0, $priorityReason, $slaHours, $slaHours]);
        $newId = $db->lastInsertId();

        // Atribuição automática
        autoAssign($db, (int)$newId, $serviceId, $user['id']);

        $fetch = $db->prepare("
            SELECT t.*, d.name AS department_name, sv.name AS service_name,
                   att.name AS attendant_name
            FROM tickets t
            JOIN departments d  ON d.id  = t.department_id
            JOIN services    sv ON sv.id = t.service_id
            LEFT JOIN users att ON att.id = t.assigned_to
            WHERE t.id = ?
        ");
        $fetch->execute([$newId]);
        respond($fetch->fetch(), 201);

    // ── PATCH ────────────────────────────────────────────────────────────────
    case 'PATCH':
        if (!$id) fail('ID não informado');
        $b = body();

        // ── Reabertura — acessível pelo dono do chamado ou admin ──────────
        if ($action === 'reopen') {
            $currentUser = me();
            $reason      = trim($b['reason'] ?? '');
            if (mb_strlen($reason) < 20) fail('Motivo da reabertura deve ter no mínimo 20 caracteres');

            $tStmt = $db->prepare("
                SELECT t.*, rc.slug AS resolution_slug
                FROM tickets t
                LEFT JOIN resolution_categories rc ON rc.id = t.resolution_category_id
                WHERE t.id = ?
            ");
            $tStmt->execute([$id]);
            $ticket = $tStmt->fetch();
            if (!$ticket) fail('Chamado não encontrado', 404);
            if (!in_array($ticket['status'], ['resolved','closed'])) fail('Chamado não está fechado');

            $isAdminUser = $currentUser['role'] === 'admin';

            if (!$isAdminUser) {
                if ($ticket['user_id'] != $currentUser['id']) fail('Acesso negado', 403);
                if ($ticket['resolution_slug'] === 'duplicate') {
                    fail('Chamados duplicados não podem ser reabertos. Acesse o chamado original para reabrir.', 403);
                }
                if ($ticket['reopen_deadline'] && strtotime($ticket['reopen_deadline']) < time()) {
                    $exp = date('d/m/Y', strtotime($ticket['reopen_deadline']));
                    fail("O prazo para reabertura expirou em $exp. Abra um novo chamado se o problema persistir.", 403);
                }
            }

            $db->prepare("
                UPDATE tickets SET
                    status          = 'open',
                    reopen_count    = reopen_count + 1,
                    last_closed_at  = NULL,
                    reopen_deadline = NULL
                WHERE id = ?
            ")->execute([$id]);

            $db->prepare(
                "INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,'reopen',?,?)"
            )->execute([$id, $currentUser['id'], $ticket['status'], $reason]);

            respond(['ok' => true]);
        }

        $adm = admin();

        // ── Resolução / Fechamento ────────────────────────────────────────
        if ($action === 'resolve') {
            $catId   = (int)($b['resolutionCategoryId'] ?? 0);
            $notes   = trim($b['resolutionNotes']       ?? '');
            $dupOfId = (int)($b['duplicateOfId']        ?? 0);
            $status  = in_array($b['status'] ?? '', ['resolved','closed']) ? $b['status'] : 'closed';

            $tStmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
            $tStmt->execute([$id]);
            $ticket = $tStmt->fetch();
            if (!$ticket) fail('Chamado não encontrado', 404);
            if (!in_array($ticket['status'], ['open','in_progress'])) fail('Chamado não está aberto ou em andamento');

            // Atendente só pode fechar seus próprios chamados
            if (!empty($adm['attendant_profile_id']) && $ticket['assigned_to'] != $adm['id']) {
                fail('Você só pode fechar chamados atribuídos a você', 403);
            }

            $catStmt = $db->prepare("SELECT * FROM resolution_categories WHERE id = ? AND is_active = 1");
            $catStmt->execute([$catId]);
            $cat = $catStmt->fetch();
            if (!$cat) fail('Categoria de resolução inválida ou inativa');

            if (in_array($cat['slug'], ['not_reproducible','duplicate']) && mb_strlen($notes) < 20) {
                fail('Observações obrigatórias (mínimo 20 caracteres) para esta categoria');
            }

            if ($cat['slug'] === 'duplicate') {
                if (!$dupOfId) fail('Informe o chamado original (duplicateOfId)');
                if ($dupOfId == $id) fail('Um chamado não pode ser duplicado de si mesmo');
                $chk = $db->prepare("SELECT id FROM tickets WHERE id = ?");
                $chk->execute([$dupOfId]);
                if (!$chk->fetch()) fail('Chamado original não encontrado');
            }

            $maxDays = (int)($db->query("SELECT max_days_to_reopen FROM reopen_settings LIMIT 1")->fetchColumn() ?: 7);

            $db->prepare("
                UPDATE tickets SET
                    status                 = ?,
                    resolution_category_id = ?,
                    resolution_notes       = ?,
                    duplicate_of           = ?,
                    resolved_at            = NOW(),
                    last_closed_at         = NOW(),
                    reopen_deadline        = DATE_ADD(NOW(), INTERVAL ? DAY)
                WHERE id = ?
            ")->execute([
                $status,
                $catId,
                $notes ?: null,
                $cat['slug'] === 'duplicate' ? $dupOfId : null,
                $maxDays,
                $id,
            ]);

            // Cria relação de duplicado
            if ($cat['slug'] === 'duplicate' && $dupOfId) {
                try {
                    $ins = $db->prepare(
                        "INSERT INTO ticket_relations (ticket_id,related_id,relation_type,created_by) VALUES (?,?,?,?)"
                    );
                    $ins->execute([$id, $dupOfId, 'duplicate', $adm['id']]);
                    $ins->execute([$dupOfId, $id, 'duplicate', $adm['id']]);
                } catch (PDOException $e) {} // ignora se já existe
            }

            $histVal = $cat['label'] . ($notes ? ": $notes" : '');
            $db->prepare(
                "INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,'resolution',?,?)"
            )->execute([$id, $adm['id'], $ticket['status'], $histVal]);

            // Fechamento em lote de duplicados vinculados
            if (!empty($b['batchDuplicates']) && is_array($b['batchDuplicates'])) {
                $batchCatId = $db->query("SELECT id FROM resolution_categories WHERE slug='duplicate' LIMIT 1")->fetchColumn();
                foreach ($b['batchDuplicates'] as $dupTktId) {
                    $dupTktId = (int)$dupTktId;
                    if (!$dupTktId || $dupTktId === (int)$id) continue;
                    $batchNotes = "Fechado em lote junto ao chamado #$id";
                    $db->prepare("
                        UPDATE tickets SET
                            status = 'closed', resolution_category_id = ?, resolution_notes = ?,
                            duplicate_of = ?, resolved_at = NOW(), last_closed_at = NOW(),
                            reopen_deadline = DATE_ADD(NOW(), INTERVAL ? DAY)
                        WHERE id = ? AND status IN ('open','in_progress')
                    ")->execute([$batchCatId, $batchNotes, $id, $maxDays, $dupTktId]);
                    $db->prepare(
                        "INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,'resolution',?,?)"
                    )->execute([$dupTktId, $adm['id'], 'open', "Fechado em lote — duplicado de #$id"]);
                }
            }

            respond(['ok' => true]);
        }

        // ── Atribuição manual / reatribuição ──────────────────────────────
        if ($action === 'assign' || $action === 'reassign') {
            $newUserId = (int)($b['assignedTo'] ?? 0);
            $reason    = trim($b['reason'] ?? '');

            if (!$newUserId) fail('assignedTo é obrigatório');
            if ($action === 'reassign' && !$reason) fail('Motivo da reatribuição é obrigatório');

            $tickStmt = $db->prepare("SELECT assigned_to FROM tickets WHERE id = ?");
            $tickStmt->execute([$id]);
            $ticket = $tickStmt->fetch();
            if (!$ticket) fail('Chamado não encontrado', 404);

            $newUser = $db->prepare("SELECT name FROM users WHERE id = ?");
            $newUser->execute([$newUserId]);
            $newUserRow = $newUser->fetch();
            if (!$newUserRow) fail('Atendente não encontrado', 404);

            $oldValue = null;
            if ($ticket['assigned_to']) {
                $oldU = $db->prepare("SELECT name FROM users WHERE id = ?");
                $oldU->execute([$ticket['assigned_to']]);
                $oldRow   = $oldU->fetch();
                $oldValue = $oldRow ? $oldRow['name'] : (string)$ticket['assigned_to'];
            }

            $db->prepare("UPDATE tickets SET assigned_to=?, assigned_at=NOW(), assignment_type='manual' WHERE id=?")
               ->execute([$newUserId, $id]);

            $histNew = $newUserRow['name'] . ' (manual)' . ($reason ? " — $reason" : '');
            $db->prepare("INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,'assigned_to',?,?)")
               ->execute([$id, $adm['id'], $oldValue, $histNew]);

            respond(['ok' => true, 'assignee_name' => $newUserRow['name']]);
        }

        // ── Alterações gerais (status, prioridade, observação) ────────────
        $tickStmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
        $tickStmt->execute([$id]);
        $ticket = $tickStmt->fetch();
        if (!$ticket) fail('Chamado não encontrado', 404);

        // Atendente só pode alterar seus próprios chamados
        if (!empty($adm['attendant_profile_id']) && $ticket['assigned_to'] != $adm['id']) {
            fail('Você só pode alterar chamados atribuídos a você', 403);
        }

        $sets = []; $params = []; $history = [];

        if (isset($b['status'])) {
            if (!in_array($b['status'], ['open','in_progress','resolved','closed'])) fail('Status inválido');
            $history[] = ['status', $ticket['status'], $b['status']];
            $sets[]    = 'status = ?'; $params[] = $b['status'];
            if (in_array($b['status'], ['resolved','closed'])) { $sets[] = 'resolved_at = NOW()'; }
        }

        if (isset($b['is_priority'])) {
            $history[] = ['is_priority', $ticket['is_priority'], $b['is_priority'] ? 1 : 0];
            $sets[]    = 'is_priority = ?'; $params[] = $b['is_priority'] ? 1 : 0;
            if ($b['is_priority']) { $sets[] = "priority_reason = 'Marcado manualmente pelo admin'"; }
        }

        if (!empty($b['observation'])) {
            $history[] = ['observation', '', trim($b['observation'])];
        }

        if ($sets) {
            $params[] = $id;
            $db->prepare("UPDATE tickets SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }

        foreach ($history as [$field, $old, $new]) {
            $db->prepare("INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,?,?,?)")
               ->execute([$id, $adm['id'], $field, $old, $new]);
        }

        $fetch = $db->prepare("
            SELECT t.*, d.name AS department_name, sv.name AS service_name,
                   u.name AS user_name, att.name AS attendant_name
            FROM tickets t
            JOIN departments d  ON d.id  = t.department_id
            JOIN services    sv ON sv.id = t.service_id
            JOIN users       u  ON u.id  = t.user_id
            LEFT JOIN users att ON att.id = t.assigned_to
            WHERE t.id = ?
        ");
        $fetch->execute([$id]);
        respond($fetch->fetch());

    default:
        fail('Método não permitido', 405);
}
