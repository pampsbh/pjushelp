<?php
require_once __DIR__ . '/config.php';

$user     = me();
$ticketId = $_GET['ticket_id'] ?? null;

if (!$ticketId)                          fail('ticket_id é obrigatório');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método não permitido', 405);

$db = getDB();
$s  = $db->prepare("SELECT * FROM tickets WHERE id = ?");
$s->execute([$ticketId]);
$ticket = $s->fetch();
if (!$ticket) fail('Chamado não encontrado', 404);
if ($user['role'] !== 'admin' && $ticket['user_id'] != $user['id']) fail('Acesso negado', 403);

if (empty($_FILES['file'])) fail('Nenhum arquivo enviado');
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK)  fail('Erro no envio do arquivo');
if ($f['size']  > MAX_UPLOAD_BYTES) fail('Arquivo muito grande (máx. 5 MB)');

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $f['tmp_name']);
finfo_close($finfo);

$allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
if (!in_array($mimeType, $allowed)) fail('Tipo não permitido — envie imagem ou PDF');

$cnt = $db->prepare("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = ?");
$cnt->execute([$ticketId]);
if ((int)$cnt->fetchColumn() >= 5) fail('Máximo de 5 anexos por chamado');

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$ext      = pathinfo($f['name'], PATHINFO_EXTENSION);
$fileName = uniqid('att_', true) . '.' . strtolower($ext);
$filePath = UPLOAD_DIR . $fileName;

if (!move_uploaded_file($f['tmp_name'], $filePath)) fail('Falha ao salvar arquivo', 500);

$ins = $db->prepare("
    INSERT INTO ticket_attachments (ticket_id, file_name, file_path, mime_type, size_bytes)
    VALUES (?, ?, ?, ?, ?)
");
$ins->execute([$ticketId, $f['name'], UPLOAD_URL . $fileName, $mimeType, $f['size']]);

respond([
    'id'         => (int)$db->lastInsertId(),
    'file_name'  => $f['name'],
    'file_path'  => UPLOAD_URL . $fileName,
    'mime_type'  => $mimeType,
    'size_bytes' => $f['size'],
], 201);
