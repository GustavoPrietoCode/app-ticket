-- ═══════════════════════════════════════════════════════════════════
-- Esquema completo de la base de datos de App Tickets
-- ═══════════════════════════════════════════════════════════════════
-- Uso: ejecutar una vez contra MySQL (el nombre de la BD debe coincidir
-- con el de ticket-backend/config.php, por defecto tickets_db).
--
--   mysql -u root -p < schema.sql
--
-- El script es idempotente: no borra tablas ni datos existentes.
-- Incluye los roles de arranque (admin/usuario) que la app espera.

CREATE DATABASE IF NOT EXISTS tickets_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tickets_db;

-- ─── roles ─────────────────────────────────────────────────────────
-- (se crea antes que users: users.role_id referencia a roles.id)

CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles de arranque (los ids 1 y 2 los usa también el frontend)
INSERT IGNORE INTO `roles` (`id`, `name`, `display_name`) VALUES
  (1, 'admin', 'Administrador'),
  (2, 'user', 'Usuario');

-- ─── organizations ────────────────────────────────────────────────
-- Compañías/organizaciones. gitea_label_* es la caché local de la
-- etiqueta de Gitea elegida por el admin (se lee de la API de Gitea).

CREATE TABLE IF NOT EXISTS `organizations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `gitea_label_id` int(11) DEFAULT NULL,
  `gitea_label_name` varchar(150) DEFAULT NULL,
  `gitea_label_color` varchar(7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organizations_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── users ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) DEFAULT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `token` (`token`),
  KEY `role_id` (`role_id`),
  KEY `idx_users_organization` (`organization_id`),
  CONSTRAINT `fk_users_organization` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── tickets ──────────────────────────────────────────────────────
-- Cada ticket se refleja además como issue en Gitea (gitea_issue_id).

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `gitea_issue_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
