<?php
require_once __DIR__ . '/config.php';

admin(); // atendentes e full admins

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método não permitido', 405);

$q         = trim($_GET['q']         ?? '');
$excludeId = (int)($_GET['excludeId'] ?? 0);

if (mb_strlen($q) < 1) respond([]);

$numericQ = is_numeric($q) ? (int)$q : 0;
$like     = "%$q%";
$excl     = $excludeId ? " AND t.id != :excl" : '';

$sql = "
    SELECT t.id, t.status, t.description,
           sv.name AS service_name,
           u.name  AS user_name
    FROM tickets t
    JOIN services sv ON sv.id = t.service_id
    JOIN users    u  ON u.id  = t.user_id
    WHERE (t.id = :num OR t.description LIKE :like)
      $excl
    ORDER BY t.id DESC
    LIMIT 10
";

$s = getDB()->prepare($sql);
$s->bindValue(':num',  $numericQ, PDO::PARAM_INT);
$s->bindValue(':like', $like);
if ($excludeId) $s->bindValue(':excl', $excludeId, PDO::PARAM_INT);
$s->execute();

respond($s->fetchAll());
