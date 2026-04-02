<?php
require_once dirname(__DIR__) . '/config.php';

admin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

switch ($method) {

    case 'GET':
        respond($db->query("
            SELECT s.*, d.name AS department_name
            FROM services s JOIN departments d ON d.id=s.department_id
            ORDER BY d.name, s.name
        ")->fetchAll());

    case 'POST':
        $b      = body();
        $deptId = (int)($b['department_id'] ?? 0);
        $name   = trim($b['name'] ?? '');
        $sla    = (int)($b['sla_hours'] ?? 24);

        if (!$deptId || !$name || $sla < 1) fail('Dados inválidos');

        $ins = $db->prepare("INSERT INTO services (department_id,name,sla_hours) VALUES (?,?,?)");
        $ins->execute([$deptId, $name, $sla]);
        $newId = $db->lastInsertId();

        $s = $db->prepare("SELECT s.*,d.name AS department_name FROM services s JOIN departments d ON d.id=s.department_id WHERE s.id=?");
        $s->execute([$newId]);
        respond($s->fetch(), 201);

    case 'PATCH':
        if (!$id) fail('ID não informado');
        $b = body(); $sets = []; $params = [];

        foreach (['name','sla_hours','department_id'] as $field) {
            if (array_key_exists($field, $b)) { $sets[] = "$field=?"; $params[] = $b[$field]; }
        }
        if ($sets) { $params[] = $id; $db->prepare("UPDATE services SET ".implode(',',$sets)." WHERE id=?")->execute($params); }

        $s = $db->prepare("SELECT s.*,d.name AS department_name FROM services s JOIN departments d ON d.id=s.department_id WHERE s.id=?");
        $s->execute([$id]);
        respond($s->fetch());

    default:
        fail('Método não permitido', 405);
}
