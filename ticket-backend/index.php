<?php

require 'vendor/autoload.php';

use Gus\MyFlightApp\AuthService;
use Gus\MyFlightApp\Database;
use Gus\MyFlightApp\EmailService;
use Gus\MyFlightApp\GiteaService;
use Gus\MyFlightApp\OrganizationService;
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
Flight::set('organizations', new OrganizationService($pdo));

// ─── CORS ────────────────────────────────────────────────────────────

Flight::before('start', function () {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
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

/**
 * Busca una etiqueta del repo de Gitea por su id.
 * Devuelve ['id', 'name', 'color'] o null si no existe o Gitea no responde.
 */
function findGiteaLabel(int $labelId): ?array
{
    /** @var GiteaService $gitea */
    $gitea = Flight::get('gitea');

    foreach ($gitea->listLabels() as $label) {
        if ((int) $label['id'] === $labelId) {
            return $label;
        }
    }

    return null;
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

    // Etiqueta de Gitea según la organización del usuario
    /** @var OrganizationService $organizations */
    $organizations = Flight::get('organizations');
    $labelIds      = [];

    if (!empty($user['organization_id'])) {
        $organization = $organizations->findById((int) $user['organization_id']);
        if ($organization && $organization['gitea_label_id']) {
            $labelIds[] = (int) $organization['gitea_label_id'];
        }
    }

    // Crear issue en Gitea
    /** @var GiteaService $gitea */
    $gitea  = Flight::get('gitea');
    $issue  = $gitea->createIssue(
        $ticket['subject'],
        $ticket['description'],
        $user['name'],
        $user['email'],
        $labelIds
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
    /** @var AuthService $auth */
    $auth   = Flight::get('auth');
    /** @var EmailService $email */
    $email  = Flight::get('email');
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
        $statusChanged = false;
        if ($giteaState === 'closed' && $ticket['status'] !== 'closed') {
            $tickets->updateStatus((int) $ticket['id'], 'closed');
            $ticket['status'] = 'closed';
            $statusChanged = true;
        } elseif ($giteaState === 'open' && $ticket['status'] === 'closed') {
            $tickets->updateStatus((int) $ticket['id'], 'open');
            $ticket['status'] = 'open';
            $statusChanged = true;
        }

        // Email si el estado cambió por sincronización desde Gitea
        if ($statusChanged) {
            try {
                $owner = $auth->findById((int) $ticket['user_id']);
                if ($owner && ($owner['email'] ?? null)) {
                    $email->sendStatusChanged($owner['email'], $owner['name'] ?? 'Usuario', $ticket, $ticket['status']);
                }
            } catch (\Throwable $e) {
                // ignorar
            }
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

// Admin: cambiar rol y/o organización de un usuario
Flight::route('PATCH /api/admin/users/@id', function (string $id) {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    /** @var AuthService $auth */
    $auth = Flight::get('auth');

    if (isset($body['role_id'])) {
        $roleId = (int) $body['role_id'];

        if ($roleId <= 0) {
            Flight::json(['error' => 'role_id inválido.'], 422);
            return;
        }

        $auth->updateRole((int) $id, $roleId);
    }

    if (array_key_exists('organization_id', $body)) {
        $organizationId = $body['organization_id'] !== null ? (int) $body['organization_id'] : null;

        if ($organizationId !== null) {
            /** @var OrganizationService $organizations */
            $organizations = Flight::get('organizations');
            if (!$organizations->findById($organizationId)) {
                Flight::json(['error' => 'Organización no encontrada.'], 422);
                return;
            }
        }

        $auth->updateUser((int) $id, ['organization_id' => $organizationId]);
    }

    Flight::json(['ok' => true]);
});

// Admin: crear un usuario (con rol y organización opcionales)
Flight::route('POST /api/admin/users', function () {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        Flight::json(['error' => 'Cuerpo JSON no válido.'], 400);
        return;
    }

    /** @var AuthService $auth */
    $auth = Flight::get('auth');
    $newUser = $auth->createUser([
        'name'            => $body['name'] ?? '',
        'email'           => $body['email'] ?? '',
        'password'        => $body['password'] ?? '',
        'role_id'         => isset($body['role_id']) && $body['role_id'] !== null ? (int) $body['role_id'] : null,
        'organization_id' => isset($body['organization_id']) && $body['organization_id'] !== null ? (int) $body['organization_id'] : null,
    ]);

    if ($newUser === null) {
        Flight::json(['error' => 'Error al crear el usuario', 'messages' => $auth->getErrors()], 422);
        return;
    }

    Flight::json(['user' => $newUser], 201);
});

// Admin: etiquetas del repo de Gitea (para el alta/edición de organizaciones)
Flight::route('GET /api/admin/gitea-labels', function () {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var GiteaService $gitea */
    $gitea = Flight::get('gitea');

    Flight::json(['labels' => $gitea->listLabels()]);
});

// Admin: listar organizaciones
Flight::route('GET /api/admin/organizations', function () {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var OrganizationService $organizations */
    $organizations = Flight::get('organizations');

    Flight::json(['organizations' => $organizations->findAll()]);
});

// Admin: crear organización (con etiqueta de Gitea opcional)
Flight::route('POST /api/admin/organizations', function () {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        Flight::json(['error' => 'Cuerpo JSON no válido.'], 400);
        return;
    }

    $data = [
        'name'           => $body['name'] ?? '',
        'gitea_label_id' => isset($body['gitea_label_id']) && $body['gitea_label_id'] !== null ? (int) $body['gitea_label_id'] : null,
    ];

    // Resolver la etiqueta elegida contra Gitea para guardar su nombre y color
    if ($data['gitea_label_id'] !== null) {
        $label = findGiteaLabel($data['gitea_label_id']);
        if ($label === null) {
            Flight::json(['error' => 'Etiqueta no válida. Actualiza las etiquetas de Gitea e inténtalo de nuevo.'], 422);
            return;
        }
        $data['gitea_label_name']  = $label['name'];
        $data['gitea_label_color'] = $label['color'];
    }

    /** @var OrganizationService $organizations */
    $organizations = Flight::get('organizations');
    $organization  = $organizations->create($data);

    if ($organization === null) {
        Flight::json(['error' => 'Validación fallida', 'messages' => $organizations->getErrors()], 422);
        return;
    }

    Flight::json(['organization' => $organization], 201);
});

// Admin: editar organización (nombre y/o etiqueta de Gitea)
Flight::route('PATCH /api/admin/organizations/@id', function (string $id) {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $data = [];

    if (isset($body['name'])) {
        $data['name'] = $body['name'];
    }

    if (array_key_exists('gitea_label_id', $body)) {
        $data['gitea_label_id'] = $body['gitea_label_id'] !== null ? (int) $body['gitea_label_id'] : null;

        if ($data['gitea_label_id'] !== null) {
            $label = findGiteaLabel($data['gitea_label_id']);
            if ($label === null) {
                Flight::json(['error' => 'Etiqueta no válida. Actualiza las etiquetas de Gitea e inténtalo de nuevo.'], 422);
                return;
            }
            $data['gitea_label_name']  = $label['name'];
            $data['gitea_label_color'] = $label['color'];
        } else {
            $data['gitea_label_name']  = null;
            $data['gitea_label_color'] = null;
        }
    }

    /** @var OrganizationService $organizations */
    $organizations = Flight::get('organizations');
    $organization  = $organizations->update((int) $id, $data);

    if ($organization === null) {
        Flight::json(['error' => 'Validación fallida', 'messages' => $organizations->getErrors()], 422);
        return;
    }

    Flight::json(['organization' => $organization]);
});

// Admin: borrar organización
Flight::route('DELETE /api/admin/organizations/@id', function (string $id) {
    $user = getAuthUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        Flight::json(['error' => 'No autorizado.'], 401);
        return;
    }

    /** @var OrganizationService $organizations */
    $organizations = Flight::get('organizations');
    $organizations->delete((int) $id);

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
