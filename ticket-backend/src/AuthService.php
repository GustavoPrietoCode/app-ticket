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

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, email, password, created_at, updated_at)
             VALUES (:name, :email, :password, NOW(), NOW())'
        );
        $stmt->execute([
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

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
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
     * Busca un usuario por token (para el middleware de auth).
     */
    public function findByToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, name, email, created_at FROM users WHERE token = :token');
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Busca un usuario por ID (sin password ni token).
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
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
