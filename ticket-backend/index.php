<?php

require 'vendor/autoload.php';

use Gus\MyFlightApp\AuthService;
use Gus\MyFlightApp\Database;
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

// ─── CORS ────────────────────────────────────────────────────────────

Flight::before('start', function () {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

    Flight::json(['ticket' => $ticket], 201);
});

// Listar tickets del usuario autenticado
Flight::route('GET /api/tickets', function () {
    $user = getAuthUser();
    if (!$user) {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $list    = $tickets->findByUserId($user['id']);

    Flight::json(['tickets' => $list]);
});

Flight::start();
