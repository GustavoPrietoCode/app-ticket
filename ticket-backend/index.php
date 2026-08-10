<?php

require 'vendor/autoload.php';

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

// ─── CORS ────────────────────────────────────────────────────────────
// Permite peticiones desde el frontend Vue en localhost:5173

Flight::before('start', function () {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 86400');

    // Responder directamente a las peticiones preflight (OPTIONS)
    if (Flight::request()->method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
});

// ─── Rutas ───────────────────────────────────────────────────────────

// Health check
Flight::route('GET /api/health', function () {
    try {
        Flight::get('db')->query('SELECT 1');
        Flight::json(['status' => 'ok', 'message' => 'Conectado a MySQL']);
    } catch (\PDOException $e) {
        Flight::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});

// Crear ticket
Flight::route('POST /api/tickets', function () {
    // Leer body JSON
    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        Flight::json(['error' => 'El cuerpo de la petición no es JSON válido.'], 400);
        return;
    }

    /** @var TicketService $tickets */
    $tickets = Flight::get('tickets');
    $ticket  = $tickets->create($body);

    if ($ticket === null) {
        Flight::json(['error' => 'Validación fallida', 'messages' => $tickets->getErrors()], 422);
        return;
    }

    Flight::json(['ticket' => $ticket], 201);
});

Flight::start();
