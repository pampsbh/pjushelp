<?php
require_once __DIR__ . '/config.php';

$db  = getDB();
$log = [];

// ── 1. Novas tabelas ──────────────────────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS attendant_profiles (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        description TEXT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS assignment_rules (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        service_id           INT NOT NULL,
        attendant_profile_id INT NOT NULL,
        is_active            TINYINT(1) DEFAULT 1,
        created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (service_id)           REFERENCES services(id),
        FOREIGN KEY (attendant_profile_id) REFERENCES attendant_profiles(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS attendant_availability (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL UNIQUE,
        is_available     TINYINT(1) DEFAULT 1,
        max_open_tickets INT DEFAULT 10,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$log[] = 'Novas tabelas criadas (ou já existiam)';

// ── 2. Altera tabelas existentes ─────────────────────────────────────────────
$alters = [
    "ALTER TABLE users    ADD COLUMN attendant_profile_id INT NULL AFTER phone_ext",
    "ALTER TABLE users    ADD FOREIGN KEY fk_usr_profile (attendant_profile_id) REFERENCES attendant_profiles(id)",
    "ALTER TABLE tickets  ADD COLUMN assigned_to     INT NULL",
    "ALTER TABLE tickets  ADD COLUMN assigned_at     TIMESTAMP NULL",
    "ALTER TABLE tickets  ADD COLUMN assignment_type ENUM('auto','manual') NULL",
    "ALTER TABLE tickets  ADD FOREIGN KEY fk_tkt_assigned (assigned_to) REFERENCES users(id)",
];

foreach ($alters as $sql) {
    try {
        $db->exec($sql);
        $log[] = "OK: $sql";
    } catch (PDOException $e) {
        $log[] = "SKIP: " . $e->getMessage();
    }
}

// ── 3. Seed ───────────────────────────────────────────────────────────────────
$profCount = (int)$db->query("SELECT COUNT(*) FROM attendant_profiles")->fetchColumn();

if ($profCount === 0) {
    // Perfis
    $db->exec("
        INSERT INTO attendant_profiles (name, description) VALUES
        ('Gerente de T.I', 'Responsável por demandas complexas e estratégicas'),
        ('Estagiário',     'Responsável por demandas operacionais e rotineiras')
    ");
    $log[] = 'Perfis de atendente inseridos';

    // Atendentes de exemplo
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $users = [
        ['Carlos Mendes', 'carlos@empresa.com', 1, 5],
        ['Ana Souza',     'ana@empresa.com',    2, 10],
        ['Bruno Lima',    'bruno@empresa.com',  2, 10],
    ];
    $insUser = $db->prepare("
        INSERT IGNORE INTO users (name, email, password_hash, role, attendant_profile_id)
        VALUES (?, ?, ?, 'admin', ?)
    ");
    foreach ($users as [$name, $email, $profileId, $maxTickets]) {
        $insUser->execute([$name, $email, $hash, $profileId]);
        $uid = $db->lastInsertId();
        if ($uid) {
            $db->prepare("INSERT INTO attendant_availability (user_id, is_available, max_open_tickets) VALUES (?,1,?)")
               ->execute([$uid, $maxTickets]);
        }
    }
    $log[] = 'Atendentes de exemplo inseridos (senha: admin123)';

    // Regras de atribuição
    $db->exec("
        INSERT INTO assignment_rules (service_id, attendant_profile_id) VALUES
        (7,  2),(8,  1),(9,  2),(10, 2),
        (1,  2),(2,  2),(3,  1),
        (4,  1),(5,  2),(6,  2)
    ");
    $log[] = 'Regras de atribuição inseridas';
} else {
    $log[] = 'Seed já existia — ignorado';
}

respond(['success' => true, 'log' => $log]);
