<?php

namespace Gus\MyFlightApp;

use PDO;

class TicketService
{
    private PDO $pdo;

    /** Errores de validación acumulados */
    private array $errors = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Crea un ticket nuevo. Devuelve el ticket insertado o null si hay errores.
     */
    public function create(array $data): ?array
    {
        $this->errors = [];

        $nombre      = $this->sanitize($data['nombre'] ?? '');
        $email       = $this->sanitize($data['email'] ?? '');
        $asunto      = $this->sanitize($data['asunto'] ?? '');
        $descripcion = $this->sanitize($data['descripcion'] ?? '');

        // Validaciones
        $this->required('nombre', $nombre);
        $this->required('email', $email);
        $this->required('asunto', $asunto);
        $this->required('descripcion', $descripcion);

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'El campo email no es válido.';
        }

        if (!empty($this->errors)) {
            return null;
        }

        // Insertar
        $sql = 'INSERT INTO tickets (nombre, email, asunto, descripcion, estado, created_at, updated_at)
                VALUES (:nombre, :email, :asunto, :descripcion, :estado, NOW(), NOW())';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':nombre'      => $nombre,
            ':email'       => $email,
            ':asunto'      => $asunto,
            ':descripcion' => $descripcion,
            ':estado'      => 'abierto',
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id);
    }

    /**
     * Busca un ticket por ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch();

        return $ticket ?: null;
    }

    /**
     * Devuelve los errores de validación de la última llamada a create().
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    // ─── Helpers internos ────────────────────────────────────────────

    private function required(string $field, string $value): void
    {
        if ($value === '') {
            $this->errors[] = "El campo {$field} es obligatorio.";
        }
    }

    private function sanitize(string $value): string
    {
        return trim(strip_tags($value));
    }
}
