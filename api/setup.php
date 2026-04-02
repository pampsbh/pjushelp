<?php
require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // ── Tabelas ──────────────────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS services (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            department_id INT NOT NULL,
            name          VARCHAR(100) NOT NULL,
            sla_hours     INT NOT NULL DEFAULT 24,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES departments(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS priority_rules (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            name            VARCHAR(100) NOT NULL,
            description     TEXT,
            condition_type  ENUM('keyword','department','service','manual') NOT NULL,
            condition_value TEXT,
            is_active       TINYINT(1) DEFAULT 1,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            name          VARCHAR(100) NOT NULL,
            email         VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role          ENUM('user','admin') DEFAULT 'user',
            department_id INT,
            unit          VARCHAR(100),
            phone_ext     VARCHAR(20),
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES departments(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS tickets (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT NOT NULL,
            department_id   INT NOT NULL,
            service_id      INT NOT NULL,
            description     TEXT NOT NULL,
            status          ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
            is_priority     TINYINT(1) DEFAULT 0,
            priority_reason VARCHAR(255),
            sla_hours       INT,
            sla_deadline    TIMESTAMP NULL,
            sla_breached    TINYINT(1) DEFAULT 0,
            resolved_at     TIMESTAMP NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id)       REFERENCES users(id),
            FOREIGN KEY (department_id) REFERENCES departments(id),
            FOREIGN KEY (service_id)    REFERENCES services(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS ticket_attachments (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id  INT NOT NULL,
            file_name  VARCHAR(255),
            file_path  VARCHAR(500),
            mime_type  VARCHAR(100),
            size_bytes INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS ticket_history (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id     INT NOT NULL,
            changed_by    INT NOT NULL,
            field_changed VARCHAR(100),
            old_value     TEXT,
            new_value     TEXT,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id)  REFERENCES tickets(id),
            FOREIGN KEY (changed_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // ── Seed (apenas se ainda não existir) ───────────────────────────────────
    $deptCount = (int)$db->query("SELECT COUNT(*) FROM departments")->fetchColumn();

    if ($deptCount === 0) {
        // Departamentos
        $db->exec("INSERT INTO departments (name) VALUES ('Facilities'),('Gente e Gestão'),('Tecnologia')");

        // Serviços
        $db->exec("
            INSERT INTO services (department_id, name, sla_hours) VALUES
            (1,'Manutenção predial',48),
            (1,'Limpeza e conservação',24),
            (1,'Controle de acesso',8),
            (2,'Férias e afastamentos',72),
            (2,'Benefícios',48),
            (2,'Ponto e jornada',24),
            (3,'Acesso e senha',4),
            (3,'Equipamento',24),
            (3,'Software e licença',48),
            (3,'Rede e conectividade',8)
        ");

        // Regras de prioridade
        $db->exec("
            INSERT INTO priority_rules (name, description, condition_type, condition_value, is_active) VALUES
            ('Palavra urgente no motivo','Chamado que contém a palavra urgente na descrição','keyword','urgente',1),
            ('TI – Acesso e senha','Serviço de acesso e senha sempre é prioritário','service','7',1),
            ('Qualquer chamado de Facilities','Todos os chamados de Facilities são prioritários','department','1',0)
        ");

        // Admin
        $hashAdmin = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'admin')")
           ->execute(['Administrador', 'admin@pjus.com.br', $hashAdmin]);

        // Usuário demo
        $hashUser = password_hash('user123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (name,email,password_hash,role,department_id,unit) VALUES (?,?,?,'user',1,'Sede')")
           ->execute(['Usuário Demo', 'usuario@pjus.com.br', $hashUser]);
    }

    // Cria pasta de uploads se não existir
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
        file_put_contents(UPLOAD_DIR . '.htaccess',
            "Options -Indexes\n<FilesMatch \"\\.php\$\">\n  Deny from all\n</FilesMatch>\n");
    }

    respond([
        'success' => true,
        'message' => 'Setup concluído!',
        'seed'    => $deptCount === 0 ? 'Seed executado' : 'Seed já existia — ignorado',
    ]);

} catch (Throwable $e) {
    fail('Erro no setup: ' . $e->getMessage(), 500);
}
