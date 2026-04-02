<?php
require_once __DIR__ . '/config.php';

$db  = getDB();
$log = [];

// ── 1. Novas tabelas ──────────────────────────────────────────────────────────
$newTables = [
    "resolution_categories" => "
        CREATE TABLE IF NOT EXISTS resolution_categories (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            slug        ENUM('resolved','not_reproducible','duplicate','cancelled_by_user') NOT NULL,
            label       VARCHAR(80) NOT NULL,
            description TEXT,
            is_active   TINYINT(1) DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "reopen_settings" => "
        CREATE TABLE IF NOT EXISTS reopen_settings (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            max_days_to_reopen INT DEFAULT 7,
            updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by         INT NULL,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "ticket_relations" => "
        CREATE TABLE IF NOT EXISTS ticket_relations (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id     INT NOT NULL,
            related_id    INT NOT NULL,
            relation_type ENUM('related','duplicate') NOT NULL,
            created_by    INT NOT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_relation (ticket_id, related_id),
            FOREIGN KEY (ticket_id)  REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (related_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($newTables as $name => $sql) {
    try { $db->exec($sql); $log[] = "Tabela $name OK"; }
    catch (PDOException $e) { $log[] = "SKIP $name: " . $e->getMessage(); }
}

// ── 2. Altera tickets ─────────────────────────────────────────────────────────
$alters = [
    "ALTER TABLE tickets ADD COLUMN resolution_category_id INT NULL",
    "ALTER TABLE tickets ADD FOREIGN KEY fk_tkt_rescat (resolution_category_id) REFERENCES resolution_categories(id)",
    "ALTER TABLE tickets ADD COLUMN resolution_notes TEXT NULL",
    "ALTER TABLE tickets ADD COLUMN duplicate_of INT NULL",
    "ALTER TABLE tickets ADD FOREIGN KEY fk_tkt_dupof (duplicate_of) REFERENCES tickets(id)",
    "ALTER TABLE tickets ADD COLUMN reopen_count INT DEFAULT 0",
    "ALTER TABLE tickets ADD COLUMN last_closed_at TIMESTAMP NULL",
    "ALTER TABLE tickets ADD COLUMN reopen_deadline TIMESTAMP NULL",
];

foreach ($alters as $sql) {
    try { $db->exec($sql); $log[] = "OK: $sql"; }
    catch (PDOException $e) { $log[] = "SKIP: " . $e->getMessage(); }
}

// ── 3. Seeds ──────────────────────────────────────────────────────────────────
if ((int)$db->query("SELECT COUNT(*) FROM resolution_categories")->fetchColumn() === 0) {
    $db->exec("
        INSERT INTO resolution_categories (slug, label, description) VALUES
        ('resolved',           'Resolvido',              'Problema identificado e corrigido com sucesso.'),
        ('not_reproducible',   'Não reproduzível',       'Não foi possível reproduzir o problema relatado.'),
        ('duplicate',          'Duplicado',              'Já existe outro chamado aberto para o mesmo problema.'),
        ('cancelled_by_user',  'Cancelado pelo usuário', 'O próprio usuário solicitou o encerramento do chamado.')
    ");
    $log[] = 'Categorias de resolução inseridas';
} else {
    $log[] = 'resolution_categories: seed já existia';
}

if ((int)$db->query("SELECT COUNT(*) FROM reopen_settings")->fetchColumn() === 0) {
    $db->exec("INSERT INTO reopen_settings (max_days_to_reopen) VALUES (7)");
    $log[] = 'reopen_settings inserido (prazo padrão: 7 dias)';
} else {
    $log[] = 'reopen_settings: seed já existia';
}

respond(['success' => true, 'log' => $log]);
