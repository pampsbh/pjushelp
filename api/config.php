<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u665104129_poc');
define('DB_USER', 'u665104129_poc');
define('DB_PASS', '/1nOu&1eMY');
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL', '/pjushelp/uploads/');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5 MB

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('HELPDESK');
        session_start();
    }
}

function getDB(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function respond(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never {
    respond(['error' => $msg], $code);
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function me(): array {
    startSession();
    if (empty($_SESSION['u'])) fail('Não autenticado', 401);
    return $_SESSION['u'];
}

function admin(): array {
    $u = me();
    if ($u['role'] !== 'admin') fail('Acesso negado', 403);
    return $u;
}

function fullAdmin(): array {
    $u = admin();
    if (!empty($u['attendant_profile_id'])) fail('Acesso restrito ao administrador', 403);
    return $u;
}

function refreshSla(): void {
    getDB()->exec(
        "UPDATE tickets SET sla_breached = 1
         WHERE sla_breached = 0
           AND sla_deadline IS NOT NULL
           AND NOW() > sla_deadline
           AND status NOT IN ('resolved','closed')"
    );
}

// ── Atribuição automática ─────────────────────────────────────────────────────

function autoAssign(PDO $db, int $ticketId, int $serviceId, int $openedBy): void {
    // 1. Busca regra para o serviço
    $rStmt = $db->prepare(
        "SELECT attendant_profile_id FROM assignment_rules WHERE service_id = ? AND is_active = 1 LIMIT 1"
    );
    $rStmt->execute([$serviceId]);
    $rule      = $rStmt->fetch();
    $profileId = $rule ? (int)$rule['attendant_profile_id'] : null;

    // 2. Tenta perfil da regra
    $assignee = pickAttendant($db, $profileId);

    // 3. Fallback: perfil Gerente
    if (!$assignee && $profileId !== null) {
        $gRow = $db->query(
            "SELECT id FROM attendant_profiles WHERE name LIKE '%Gerente%' LIMIT 1"
        )->fetch();
        if ($gRow && (int)$gRow['id'] !== $profileId) {
            $assignee = pickAttendant($db, (int)$gRow['id']);
        }
    }

    // 4. Fallback final: qualquer atendente disponível
    if (!$assignee) {
        $assignee = pickAttendant($db, null);
    }

    if (!$assignee) return; // ninguém disponível — fica sem responsável

    // 5. Atribui
    $db->prepare(
        "UPDATE tickets SET assigned_to = ?, assigned_at = NOW(), assignment_type = 'auto' WHERE id = ?"
    )->execute([$assignee['id'], $ticketId]);

    // 6. Histórico
    $db->prepare(
        "INSERT INTO ticket_history (ticket_id, changed_by, field_changed, old_value, new_value)
         VALUES (?, ?, 'assigned_to', NULL, ?)"
    )->execute([$ticketId, $openedBy, $assignee['name'] . ' (auto)']);
}

function pickAttendant(PDO $db, ?int $profileId): ?array {
    $profileClause = $profileId !== null ? "AND u.attendant_profile_id = $profileId" : '';
    $row = $db->query("
        SELECT u.id, u.name,
               COALESCE((
                   SELECT COUNT(*) FROM tickets
                   WHERE assigned_to = u.id AND status IN ('open','in_progress')
               ), 0) AS open_count,
               aa.max_open_tickets,
               (SELECT MAX(assigned_at) FROM tickets WHERE assigned_to = u.id) AS last_assigned
        FROM users u
        JOIN attendant_availability aa ON aa.user_id = u.id AND aa.is_available = 1
        WHERE u.role = 'admin'
          AND u.attendant_profile_id IS NOT NULL
          $profileClause
        HAVING open_count < aa.max_open_tickets
        ORDER BY open_count ASC,
                 ISNULL(last_assigned) DESC,
                 last_assigned ASC
        LIMIT 1
    ")->fetch();
    return $row ?: null;
}
