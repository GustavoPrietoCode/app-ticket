<?php

require 'vendor/autoload.php';

use Gus\MyFlightApp\Database;

// Cargar configuración
$config = require __DIR__ . '/config.php';

// Inicializar base de datos
$db = new Database($config['db']);

// Registrar PDO en Flight para usarlo desde cualquier ruta
Flight::set('db', $db->getPdo());

// Ruta de prueba: verificar conexión
Flight::route('GET /api/health', function () {
    try {
        $pdo = Flight::get('db');
        $pdo->query('SELECT 1');

        Flight::json(['status' => 'ok', 'message' => 'Conectado a MySQL']);
    } catch (\PDOException $e) {
        Flight::json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});

Flight::start();
