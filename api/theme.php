<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// Garante tabela settings
$db->exec("
    CREATE TABLE IF NOT EXISTS settings (
        `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
        `value`    TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed padrão se vazia
if ((int)$db->query("SELECT COUNT(*) FROM settings")->fetchColumn() === 0) {
    $db->exec("
        INSERT INTO settings (`key`, `value`) VALUES
        ('company_name',     'PJUS'),
        ('company_subtitle', 'Service Desk'),
        ('logo_url',         ''),
        ('color_primary',    '#0061D6'),
        ('color_sidebar',    '#2d3748'),
        ('color_success',    '#0E8557'),
        ('color_warning',    '#FEC33C'),
        ('color_danger',     '#FF4D4F'),
        ('font_family',      'Lato'),
        ('font_url',         'https://fonts.googleapis.com/css2?family=Lato:wght@400;600;700;800&display=swap')
    ");
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $rows = $db->query("SELECT `key`, `value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        respond((object)$rows);

    case 'POST':
        fullAdmin();
        $b       = body();
        $allowed = [
            'company_name','company_subtitle','logo_url',
            'color_primary','color_sidebar','color_success','color_warning','color_danger',
            'font_family','font_url',
        ];
        $stmt = $db->prepare(
            "INSERT INTO settings (`key`, `value`) VALUES (?,?)
             ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()"
        );
        foreach ($allowed as $key) {
            if (array_key_exists($key, $b)) {
                $stmt->execute([$key, $b[$key], $b[$key]]);
            }
        }
        respond(['ok' => true]);

    default:
        fail('Método não permitido', 405);
}
