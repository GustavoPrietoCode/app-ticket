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

        $subject     = $this->sanitize($data['subject'] ?? '');
        $description = $this->sanitize($data['description'] ?? '');

        // Validaciones
        $this->required('subject', $subject);
        $this->required('description', $description);

        if (!empty($this->errors)) {
            return null;
        }

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;

        // Insertar
        $sql = 'INSERT INTO tickets (user_id, subject, description, status, created_at, updated_at)
                VALUES (:user_id, :subject, :description, :status, NOW(), NOW())';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id'     => $userId,
            ':subject'     => $subject,
            ':description' => $description,
            ':status'      => 'open',
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
     * Devuelve todos los tickets de un usuario.
     */
    public function findByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tickets WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Actualiza el ID del issue de Gitea en un ticket.
     */
    public function setGiteaIssueId(int $ticketId, int $issueId): void
    {
        $stmt = $this->pdo->prepare('UPDATE tickets SET gitea_issue_id = :issue_id WHERE id = :id');
        $stmt->execute([':issue_id' => $issueId, ':id' => $ticketId]);
    }

    /**
     * Actualiza el estado de un ticket.
     */
    public function updateStatus(int $ticketId, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tickets SET status = :status, updated_at = NOW() WHERE id = :id'
        );
        return $stmt->execute([':status' => $status, ':id' => $ticketId]);
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
