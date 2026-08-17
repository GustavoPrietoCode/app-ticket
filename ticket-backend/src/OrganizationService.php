<?php

namespace Gus\MyFlightApp;

use PDO;

class OrganizationService
{
    private PDO $pdo;

    /** Errores de validación acumulados */
    private array $errors = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Crea una organización. Devuelve la organización insertada o null si hay errores.
     * 'gitea_label_id'/'gitea_label_name'/'gitea_label_color' opcionales (etiqueta de Gitea).
     */
    public function create(array $data): ?array
    {
        $this->errors = [];

        $name = $this->sanitize($data['name'] ?? '');
        $this->required('name', $name);

        $labelId    = isset($data['gitea_label_id']) && $data['gitea_label_id'] !== null ? (int) $data['gitea_label_id'] : null;
        $labelName  = $labelId !== null ? $this->sanitize($data['gitea_label_name'] ?? '') : null;
        $labelColor = $labelId !== null ? $this->sanitize($data['gitea_label_color'] ?? '') : null;

        // Verificar nombre único
        if (empty($this->errors)) {
            $stmt = $this->pdo->prepare('SELECT id FROM organizations WHERE name = :name');
            $stmt->execute([':name' => $name]);
            if ($stmt->fetch()) {
                $this->errors[] = 'Ya existe una organización con ese nombre.';
            }
        }

        if (!empty($this->errors)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO organizations (name, gitea_label_id, gitea_label_name, gitea_label_color, created_at, updated_at)
             VALUES (:name, :label_id, :label_name, :label_color, NOW(), NOW())'
        );
        $stmt->execute([
            ':name'        => $name,
            ':label_id'    => $labelId,
            ':label_name'  => $labelName,
            ':label_color' => $labelColor,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id);
    }

    /**
     * Devuelve todas las organizaciones con su número de usuarios.
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, COUNT(u.id) AS user_count
             FROM organizations o
             LEFT JOIN users u ON u.organization_id = o.id
             GROUP BY o.id
             ORDER BY o.name ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Busca una organización por ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM organizations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $organization = $stmt->fetch();

        return $organization ?: null;
    }

    /**
     * Actualiza una organización (name y/o etiqueta de Gitea).
     * gitea_label_id null = quitar etiqueta.
     */
    public function update(int $id, array $data): ?array
    {
        $this->errors = [];

        $organization = $this->findById($id);
        if (!$organization) {
            $this->errors[] = 'Organización no encontrada.';
            return null;
        }

        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('name', $data)) {
            $name = $this->sanitize($data['name']);
            $this->required('name', $name);

            if (empty($this->errors)) {
                $stmt = $this->pdo->prepare('SELECT id FROM organizations WHERE name = :name AND id != :id');
                $stmt->execute([':name' => $name, ':id' => $id]);
                if ($stmt->fetch()) {
                    $this->errors[] = 'Ya existe una organización con ese nombre.';
                }
            }

            $fields[]        = 'name = :name';
            $params[':name'] = $name;
        }

        if (array_key_exists('gitea_label_id', $data)) {
            $labelId    = $data['gitea_label_id'] !== null ? (int) $data['gitea_label_id'] : null;
            $labelName  = $labelId !== null ? $this->sanitize($data['gitea_label_name'] ?? '') : null;
            $labelColor = $labelId !== null ? $this->sanitize($data['gitea_label_color'] ?? '') : null;

            $fields[]              = 'gitea_label_id = :label_id';
            $fields[]              = 'gitea_label_name = :label_name';
            $fields[]              = 'gitea_label_color = :label_color';
            $params[':label_id']    = $labelId;
            $params[':label_name']  = $labelName;
            $params[':label_color'] = $labelColor;
        }

        if (!empty($this->errors)) {
            return null;
        }

        if (empty($fields)) {
            return $organization;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE organizations SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute($params);

        return $this->findById($id);
    }

    /**
     * Borra una organización. Los usuarios asociados quedan con organization_id NULL (FK ON DELETE SET NULL).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM organizations WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Devuelve los errores de validación de la última llamada a create()/update().
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
