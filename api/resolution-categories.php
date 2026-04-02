<?php
require_once __DIR__ . '/config.php';

me(); // qualquer usuário logado

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método não permitido', 405);

$rows = getDB()->query(
    "SELECT id, slug, label, description FROM resolution_categories WHERE is_active = 1 ORDER BY id"
)->fetchAll();

respond($rows);
