<?php
require_once __DIR__ . '/config.php';
startSession();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'POST': // login
        $b        = body();
        $email    = trim($b['email']    ?? '');
        $password = $b['password'] ?? '';

        if (!$email || !$password) fail('E-mail e senha são obrigatórios');

        $stmt = getDB()->prepare("
            SELECT u.*, ap.name AS attendant_profile_name
            FROM users u
            LEFT JOIN attendant_profiles ap ON ap.id = u.attendant_profile_id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            fail('Credenciais inválidas', 401);
        }

        unset($user['password_hash']);
        $_SESSION['u'] = $user;
        respond($user);

    case 'DELETE': // logout
        session_destroy();
        respond(['ok' => true]);

    case 'GET': // sessão atual
        if (empty($_SESSION['u'])) fail('Não autenticado', 401);
        respond($_SESSION['u']);

    default:
        fail('Método não permitido', 405);
}
