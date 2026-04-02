<?php
require_once __DIR__ . '/config.php';
me(); // requer autenticação

$db = getDB();
$id = $_GET['id'] ?? null;

if ($id && isset($_GET['services'])) {
    $s = $db->prepare("SELECT * FROM services WHERE department_id = ? ORDER BY name");
    $s->execute([$id]);
    respond($s->fetchAll());
} else {
    respond($db->query("SELECT * FROM departments ORDER BY name")->fetchAll());
}
