<?php

require 'vendor/autoload.php';

use Gus\MyFlightApp\AuthService;
use Gus\MyFlightApp\Database;
use Gus\MyFlightApp\EmailService;
use Gus\MyFlightApp\GiteaService;
use Gus\MyFlightApp\TicketService;

// Cargar configuración
$config = require __DIR__ . '/config.php';

// Inicializar base de datos
$db = new Database($config['db']);
$pdo = $db->getPdo();

// Registrar servicios en Flight
Flight::set('db', $pdo);
Flight::set('tickets', new TicketService($pdo));
Flight::set('auth', new AuthService($pdo));
Flight::set('gitea', new GiteaService($config['gitea']));
Flight::set('email', new EmailService($config['email'] ?? []));

// ─── CORS ────────────────────────────────────────────────────────────

Flight::before('start', function () {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization');
    header('Access-Control-Max-Age: 86400');

    if (Flight::request()->method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
});

// ─── Helper: obtener usuario autenticado ─────────────────────────────

function getAuthUser(): ?array
{
    $header = Flight::request()->getHeader('Authorization');
    $token  = null;

    if ($header && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        $token = $m[1];
    }

    /** @var AuthService $auth */
    $auth = Flight::get('auth');

    return $auth->findByToken($token);
}

// ═══════════════════════════════════════════════════════════════════════
// RUTAS PÚBLICAS (sin autenticación)
// ═══════════════════════════════════════════════════════════════════════

// Health check
Flight::route('GET /api/health', function () {
    try {
        Flight::get('db')->query('SELECT 1');
        Flight::json(['status' => 'ok', 'message' => 'Conectado a MySQL']);
    } catch (\PDOException $e) {
        Flight::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});

// Registro
Flight::route('POST /api/register', function () {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        Flight::json(['error' => 'Cuerpo JSON no válido.'], 400);
        return;
    }

    /** @var AuthService $auth */
    $auth = Flight::get('auth');
    $user = $auth->register($body);

    if ($user === null) {
        Flight::json(['error' => 'Error de registro', 'messages' => $auth->getErrors()], 422);
        return;
    }

    Flight::json(['user' => $user], 201);
});

// Login
Flight::route('POST /api/login', function () {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        Flight::json(['error' => 'Cuerpo JSON no válido.'], 400);
        return;
    }

    /** @var AuthService $auth */
    $auth = Flight::get('auth');
    $user = $auth->login($body);

    if ($user === null) {
        Flight::json(['error' => 'Login fallido', 'messages' => $auth->getErrors()], 401);
        return;
    }

    Flight::json(['user' => $user]);
});

// ═══════════════════════════════════════════════════════════════════════
// RUTAS PROTEGIDAS (requieren autenticación)
// ═══════════════════════════════════════════════════════════════════════

// Crear ticket
Flight::route('POST /api/tickets', function () {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    // Detectar si es JSON o multipart (con imágenes)
    $contentType = Flight::request()->getHeader('Content-Type') ?? '';

    if (str_contains($contentType, 'multipart/form-data')) {
        $body = [
            'subject'     => $_POST['subject'] ?? '',
            'description' => $_POST['description'] ?? '',
            'user_id'     => $user['id'],
        ];
        $images = $_FILES['images'] ?? null;
    } else {
        $body   = json_decode(file_get_contents('php://input'), true);
        $images = null;

        if (!$body) {
            Flight::json(['error' => 'Cuerpo JSON no válido.'], 400);
            return;
        }

        $body['user_id'] = $user['id'];
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->create($body);

    if ($ticket === null) {
        Flight::json(['error' => 'Validación fallida', 'messages' => $tickets->getErrors()], 422);
        return;
    }

    // Crear issue en Gitea
    /** @var GiteaService $gitea */
    $gitea  = Flight::get('gitea');
    $issue  = $gitea->createIssue(
        $ticket['subject'],
        $ticket['description'],
        $user['name'],
        $user['email']
    );

    if ($issue && $issue['number']) {
        $tickets->setGiteaIssueId((int) $ticket['id'], $issue['number']);
        $ticket['gitea_issue_id'] = $issue['number'];
        $ticket['gitea_url']      = $issue['url'];

        // Subir imágenes si las hay
        if ($images && !empty($images['name'][0])) {
            $imageUrls = [];
            $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            foreach ($images['name'] as $i => $name) {
                if ($images['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($images['size'][$i] > 5 * 1024 * 1024) continue;
                if (!in_array($images['type'][$i], $allowed, true)) continue;

                $url = $gitea->uploadAsset(
                    (int) $issue['number'],
                    $images['tmp_name'][$i],
                    $name
                );
                if ($url) {
                    $imageUrls[] = "![{$name}]({$url})";
                }
            }

            if (!empty($imageUrls)) {
                $gitea->addComment(
                    (int) $issue['number'],
                    "**{$user['name']}** adjuntó:\n\n" . implode("\n", $imageUrls)
                );
            }
        }
    }

    Flight::json(['ticket' => $ticket], 201);

    // Email de confirmación al creador
    try {
        /** @var EmailService $email */
        $email = Flight::get('email');
        $email->sendTicketCreated($user['email'], $user['name'], $ticket);
    } catch (\Throwable $e) {
        // ignorar — el ticket ya está creado
    }
});

// Listar tickets del usuario autenticado (con sincronización desde Gitea)
Flight::route('GET /api/tickets', function () {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if ($isAdmin) {
        // Admin puede filtrar por usuario: ?user_id=3
        $filterUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
        $list = $filterUserId ? $tickets->findByUserId($filterUserId) : $tickets->findAll();
    } else {
        $list = $tickets->findByUserId($user['id']);
    }

    // Sincronizar estado desde Gitea para tickets con issue asociado
    /** @var GiteaService $gitea */
    $gitea = Flight::get('gitea');
    foreach ($list as &$ticket) {
        if (!$ticket['gitea_issue_id']) {
            continue;
        }
        $issue = $gitea->getIssue((int) $ticket['gitea_issue_id']);
        if (!$issue) {
            continue;
        }
        // Mapear estado de Gitea → nuestro estado
        $giteaState = $issue['state']; // 'open' o 'closed'
        if ($giteaState === 'closed' && $ticket['status'] !== 'closed') {
            $tickets->updateStatus((int) $ticket['id'], 'closed');
            $ticket['status'] = 'closed';
        } elseif ($giteaState === 'open' && $ticket['status'] === 'closed') {
            $tickets->updateStatus((int) $ticket['id'], 'open');
            $ticket['status'] = 'open';
        }
    }
    unset($ticket);

    Flight::json(['tickets' => $list]);
});

// Admin: listar usuarios
Flight::route('GET /api/admin/users', function () {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var AuthService $auth */
    $auth  = Flight::get('auth');
    $users = $auth->getAllUsers();

    Flight::json(['users' => $users]);
});

// Admin: cambiar rol de un usuario
Flight::route('PATCH /api/admin/users/@id', function (string $id) {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    $body   = json_decode(file_get_contents('php://input'), true);
    $roleId = isset($body['role_id']) ? (int) $body['role_id'] : 0;

    if ($roleId <= 0) {
        Flight::json(['error' => 'role_id inválido.'], 422);
        return;
    }

    /** @var AuthService $auth */
    $auth = Flight::get('auth');
    $auth->updateRole((int) $id, $roleId);

    Flight::json(['ok' => true]);
});

// Cambiar estado de un ticket
Flight::route('PATCH /api/tickets/@id', function (string $id) {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->findById((int) $id);

    $isOwner = (int) $ticket['user_id'] === $user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isOwner && !$isAdmin) {
        Flight::json(['error' => 'Ticket no encontrado.'], 404);
        return;
    }

    $body   = json_decode(file_get_contents('php://input'), true);
    $status = $body['status'] ?? '';

    if (!in_array($status, ['open', 'closed'], true)) {
        Flight::json(['error' => 'Estado no válido. Usa open o closed.'], 422);
        return;
    }

    // Si tiene issue en Gitea, sincronizar cierre/apertura
    if ($ticket['gitea_issue_id'] && in_array($status, ['open', 'closed'], true)) {
        /** @var GiteaService $gitea */
        $gitea = Flight::get('gitea');
        $gitea->updateIssueStatus((int) $ticket['gitea_issue_id'], $status);
    }

    $tickets->updateStatus((int) $id, $status);

    Flight::json(['status' => $status]);

    // Email de notificación al dueño del ticket
    try {
        /** @var AuthService $auth */
        $auth   = Flight::get('auth');
        $owner  = $auth->findById((int) $ticket['user_id']);
        if ($owner && ($owner['email'] ?? null)) {
            /** @var EmailService $email */
            $email = Flight::get('email');
            $email->sendStatusChanged($owner['email'], $owner['name'] ?? 'Usuario', $ticket, $status);
        }
    } catch (\Throwable $e) {
        // ignorar
    }
});

// Obtener comentarios de un ticket
Flight::route('GET /api/tickets/@id/comments', function (string $id) {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->findById((int) $id);

    $isOwner = (int) $ticket['user_id'] === $user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isOwner && !$isAdmin) {
        Flight::json(['error' => 'Ticket no encontrado.'], 404);
        return;
    }

    // Si tiene issue en Gitea, obtener comentarios de allí
    $comments = [];
    if ($ticket['gitea_issue_id']) {
        /** @var GiteaService $gitea */
        $gitea    = Flight::get('gitea');
        $comments = $gitea->getComments((int) $ticket['gitea_issue_id']);
    }

    Flight::json(['comments' => $comments]);
});

// Subir imagen adjunta a un ticket
Flight::route('POST /api/tickets/@id/upload', function (string $id) {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->findById((int) $id);

    $isOwner = (int) $ticket['user_id'] === $user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isOwner && !$isAdmin) {
        Flight::json(['error' => 'Ticket no encontrado.'], 404);
        return;
    }

    if (!$ticket['gitea_issue_id']) {
        Flight::json(['error' => 'Este ticket no tiene un issue asociado en Gitea.'], 400);
        return;
    }

    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        Flight::json(['error' => 'No se recibió ningún archivo.'], 422);
        return;
    }

    // Validar tipo
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) {
        Flight::json(['error' => 'Solo se permiten imágenes (JPG, PNG, GIF, WebP).'], 422);
        return;
    }

    // Validar tamaño (máx 5 MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        Flight::json(['error' => 'La imagen no puede superar los 5 MB.'], 422);
        return;
    }

    /** @var GiteaService $gitea */
    $gitea = Flight::get('gitea');
    $imageUrl = $gitea->uploadAsset(
        (int) $ticket['gitea_issue_id'],
        $file['tmp_name'],
        $file['name']
    );

    if (!$imageUrl) {
        Flight::json(['error' => 'Error al subir la imagen a Gitea.'], 500);
        return;
    }

    Flight::json(['url' => $imageUrl], 201);
});

// Añadir comentario a un ticket
Flight::route('POST /api/tickets/@id/comments', function (string $id) {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->findById((int) $id);

    $isOwner = (int) $ticket['user_id'] === $user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isOwner && !$isAdmin) {
        Flight::json(['error' => 'Ticket no encontrado.'], 404);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $comment = trim(strip_tags($body['comment'] ?? ''));

    if ($comment === '') {
        Flight::json(['error' => 'El comentario no puede estar vacío.'], 422);
        return;
    }

    $message = "**{$user['name']}:** {$comment}";

    // Si tiene issue en Gitea, añadir comentario allí también
    if ($ticket['gitea_issue_id']) {
        /** @var GiteaService $gitea */
        $gitea = Flight::get('gitea');
        $gitea->addComment((int) $ticket['gitea_issue_id'], $message);
    }

    Flight::json(['comment' => $message], 201);

    // Email al dueño si el comentario es de otro usuario
    if (!$isOwner) {
        try {
            /** @var AuthService $auth */
            $auth  = Flight::get('auth');
            $owner = $auth->findById((int) $ticket['user_id']);
            if ($owner && ($owner['email'] ?? null)) {
                /** @var EmailService $email */
                $email = Flight::get('email');
                $email->sendNewComment($owner['email'], $owner['name'] ?? 'Usuario', $ticket, $user['name']);
            }
        } catch (\Throwable $e) {
            // ignorar
        }
    }
});

Flight::start();
