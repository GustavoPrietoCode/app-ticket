<?php

require 'vendor/autoload.php';

use Gus\MyFlightApp\AuthService;
use Gus\MyFlightApp\Database;
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

    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        Flight::json(['error' => 'Cuerpo JSON no válido.'], 400);
        return;
    }

    // Asociar al usuario autenticado
    $body['user_id'] = $user['id'];

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->create($body);

    if ($ticket === null) {
        Flight::json(['error' => 'Validación fallida', 'messages' => $tickets->getErrors()], 422);
        return;
    }

    // Crear issue en Gitea (no bloquea la respuesta si falla)
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
    }

    Flight::json(['ticket' => $ticket], 201);
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
    $list    = $tickets->findByUserId($user['id']);

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

    if (!$ticket || (int) $ticket['user_id'] !== $user['id']) {
        Flight::json(['error' => 'Ticket no encontrado.'], 404);
        return;
    }

    $body   = json_decode(file_get_contents('php://input'), true);
    $status = $body['status'] ?? '';

    if (!in_array($status, ['open', 'in_progress', 'closed'], true)) {
        Flight::json(['error' => 'Estado no válido. Usa open, in_progress o closed.'], 422);
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

    if (!$ticket || (int) $ticket['user_id'] !== $user['id']) {
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
});

Flight::start();
