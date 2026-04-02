<?php
require_once __DIR__ . '/config.php';

$user   = admin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id']        ?? 0);
$relId  = (int)($_GET['relatedId'] ?? 0);

if (!$id) fail('ID não informado');

switch ($method) {

    case 'GET':
        $s = $db->prepare("
            SELECT tr.id, tr.relation_type, tr.created_at,
                   t2.id     AS related_id,
                   t2.status AS related_status,
                   sv.name   AS service_name,
                   u.name    AS user_name,
                   ub.name   AS created_by_name
            FROM ticket_relations tr
            JOIN tickets  t2  ON t2.id  = tr.related_id
            JOIN services sv  ON sv.id  = t2.service_id
            JOIN users    u   ON u.id   = t2.user_id
            JOIN users    ub  ON ub.id  = tr.created_by
            WHERE tr.ticket_id = ?
            ORDER BY tr.created_at DESC
        ");
        $s->execute([$id]);
        respond($s->fetchAll());

    case 'POST':
        $b            = body();
        $relatedId    = (int)($b['relatedId']    ?? 0);
        $relationType = $b['relationType']        ?? '';

        if (!$relatedId) fail('relatedId é obrigatório');
        if ($relatedId === $id) fail('Não é possível vincular um chamado a si mesmo');
        if (!in_array($relationType, ['related','duplicate'])) fail('relationType inválido');

        $check = $db->prepare("SELECT COUNT(*) FROM tickets WHERE id IN (?,?)");
        $check->execute([$id, $relatedId]);
        if ((int)$check->fetchColumn() < 2) fail('Um ou ambos os chamados não encontrados');

        try {
            $db->beginTransaction();

            $ins = $db->prepare(
                "INSERT INTO ticket_relations (ticket_id, related_id, relation_type, created_by) VALUES (?,?,?,?)"
            );
            $ins->execute([$id, $relatedId, $relationType, $user['id']]);
            $ins->execute([$relatedId, $id, $relationType, $user['id']]);

            if ($relationType === 'duplicate') {
                $db->prepare("UPDATE tickets SET duplicate_of = ? WHERE id = ?")
                   ->execute([$relatedId, $id]);
            }

            $hist = $db->prepare(
                "INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,'relations',NULL,?)"
            );
            $hist->execute([$id,        $user['id'], "Vinculado a #$relatedId ($relationType)"]);
            $hist->execute([$relatedId, $user['id'], "Vinculado a #$id ($relationType)"]);

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() == 23000) fail('Vínculo já existe entre estes chamados');
            throw $e;
        }

        respond(['ok' => true]);

    case 'DELETE':
        if (!$relId) fail('relatedId não informado');

        $db->beginTransaction();
        try {
            $db->prepare("
                DELETE FROM ticket_relations
                WHERE (ticket_id = ? AND related_id = ?) OR (ticket_id = ? AND related_id = ?)
            ")->execute([$id, $relId, $relId, $id]);

            $db->prepare("UPDATE tickets SET duplicate_of = NULL WHERE id = ? AND duplicate_of = ?")
               ->execute([$id,    $relId]);
            $db->prepare("UPDATE tickets SET duplicate_of = NULL WHERE id = ? AND duplicate_of = ?")
               ->execute([$relId, $id]);

            $hist = $db->prepare(
                "INSERT INTO ticket_history (ticket_id,changed_by,field_changed,old_value,new_value) VALUES (?,?,'relations',?,NULL)"
            );
            $hist->execute([$id,    $user['id'], "Vínculo com #$relId removido"]);
            $hist->execute([$relId, $user['id'], "Vínculo com #$id removido"]);

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            throw $e;
        }

        respond(['ok' => true]);

    default:
        fail('Método não permitido', 405);
}
