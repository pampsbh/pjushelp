<?php
require_once dirname(__DIR__) . '/config.php';

admin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;

switch ($method) {

    case 'GET':
        respond($db->query("SELECT * FROM priority_rules ORDER BY is_active DESC, id ASC")->fetchAll());

    case 'POST':
        $b    = body();
        $name = trim($b['name'] ?? '');
        $type = $b['condition_type'] ?? '';
        $val  = trim($b['condition_value'] ?? '');

        if (!$name || !in_array($type, ['keyword','department','service','manual'])) fail('Dados inválidos');

        $ins = $db->prepare("INSERT INTO priority_rules (name,description,condition_type,condition_value,is_active) VALUES (?,?,?,?,1)");
        $ins->execute([$name, $b['description'] ?? null, $type, $val ?: null]);
        $newId = $db->lastInsertId();

        $s = $db->prepare("SELECT * FROM priority_rules WHERE id=?"); $s->execute([$newId]);
        respond($s->fetch(), 201);

    case 'PATCH':
        if (!$id) fail('ID não informado');
        $b = body();
        $sets = []; $params = [];

        foreach (['name','description','condition_type','condition_value','is_active'] as $field) {
            if (array_key_exists($field, $b)) { $sets[] = "$field=?"; $params[] = $b[$field]; }
        }
        if ($sets) { $params[] = $id; $db->prepare("UPDATE priority_rules SET ".implode(',',$sets)." WHERE id=?")->execute($params); }

        $s = $db->prepare("SELECT * FROM priority_rules WHERE id=?"); $s->execute([$id]);
        respond($s->fetch());

    case 'DELETE':
        if (!$id) fail('ID não informado');
        $db->prepare("DELETE FROM priority_rules WHERE id=?")->execute([$id]);
        respond(['ok' => true]);

    default:
        fail('Método não permitido', 405);
}
