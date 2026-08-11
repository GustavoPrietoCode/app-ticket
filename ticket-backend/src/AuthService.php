<?php

namespace Gus\MyFlightApp;

use PDO;

class AuthService
{
    private PDO $pdo;

    private array $errors = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Registra un nuevo usuario. Devuelve el usuario creado o null si hay errores.
     */
    public function register(array $data): ?array
    {
        $this->errors = [];

        $name     = trim(strip_tags($data['name'] ?? ''));
        $email    = trim(strip_tags($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        $this->required('name', $name);
        $this->required('email', $email);
        $this->required('password', $password);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'El email no es válido.';
        }

        if (strlen($password) < 6) {
            $this->errors[] = 'La contraseña debe tener al menos 6 caracteres.';
        }

        // Verificar email único
        if (empty($this->errors)) {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                $this->errors[] = 'Ya existe un usuario con ese email.';
            }
        }

        if (!empty($this->errors)) {
            return null;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Asignar rol 'user' por defecto
        $roleStmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'user'");
        $roleStmt->execute();
        $roleId = $roleStmt->fetchColumn() ?: null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (role_id, name, email, password, created_at, updated_at)
             VALUES (:role_id, :name, :email, :password, NOW(), NOW())'
        );
        $stmt->execute([
            ':role_id'  => $roleId,
            ':name'     => $name,
            ':email'    => $email,
            ':password' => $hash,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id);
    }

    /**
     * Inicia sesión. Devuelve el usuario + token o null si falla.
     */
    public function login(array $data): ?array
    {
        $this->errors = [];

        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($email === '' || $password === '') {
            $this->errors[] = 'Email y contraseña son obligatorios.';
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.*, r.name AS role
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $this->errors[] = 'Credenciales incorrectas.';
            return null;
        }

        // Generar token y guardarlo
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare('UPDATE users SET token = :token WHERE id = :id');
        $stmt->execute([':token' => $token, ':id' => $user['id']]);

        unset($user['password'], $user['token']);
        $user['token'] = $token;

        return $user;
    }

    /**
     * Busca un usuario por token (para el middleware de auth). Incluye rol.
     */
    public function findByToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role_id, r.name AS role, u.created_at
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.token = :token'
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Busca un usuario por ID (sin password ni token). Incluye rol.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role_id, r.name AS role, u.created_at
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Admin: actualiza el rol de un usuario.
     */
    public function updateRole(int $userId, int $roleId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET role_id = :role_id WHERE id = :id');
        return $stmt->execute([':role_id' => $roleId, ':id' => $userId]);
    }

    /**
     * Admin: devuelve todos los usuarios con su rol.
     */
    public function getAllUsers(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, r.name AS role, r.display_name AS role_display, u.created_at
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             ORDER BY u.id ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function required(string $field, string $value): void
    {
        if ($value === '') {
            $this->errors[] = "El campo {$field} es obligatorio.";
        }
    }
}
