<?php

namespace Gus\MyFlightApp;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailService
{
    private string $fromAddress;
    private string $fromName;
    private string $smtpHost;
    private int    $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpSecure;
    private string $logPath;

    public function __construct(array $config)
    {
        $this->fromAddress = $config['from_address'] ?? 'tickets@example.com';
        $this->fromName    = $config['from_name']    ?? 'App Tickets';
        $this->smtpHost    = $config['smtp_host']    ?? '';
        $this->smtpPort    = (int) ($config['smtp_port'] ?? 587);
        $this->smtpUser    = $config['smtp_user']    ?? '';
        $this->smtpPass    = $config['smtp_pass']    ?? '';
        $this->smtpSecure  = $config['smtp_secure']  ?? 'tls';

        // Directorio de logs (relativo a ticket-backend/)
        $this->logPath = dirname(__DIR__) . '/logs/emails.log';
    }

    /**
     * Email de confirmación cuando se crea un ticket.
     */
    public function sendTicketCreated(string $to, string $userName, array $ticket): bool
    {
        $subject = "[App Tickets] Ticket #{$ticket['id']} creado";
        $body    = $this->render('ticket-created', [
            'userName' => $userName,
            'ticket'   => $ticket,
        ]);

        return $this->send($to, $subject, $body);
    }

    /**
     * Email de notificación cuando cambia el estado de un ticket.
     */
    public function sendStatusChanged(string $to, string $userName, array $ticket, string $newStatus): bool
    {
        $statusLabel = $newStatus === 'closed' ? 'Cerrado' : 'Abierto';
        $subject     = "[App Tickets] Ticket #{$ticket['id']} {$statusLabel}";
        $body        = $this->render('status-changed', [
            'userName'    => $userName,
            'ticket'      => $ticket,
            'statusLabel' => $statusLabel,
        ]);

        return $this->send($to, $subject, $body);
    }

    /**
     * Email de notificación cuando alguien comenta en tu ticket.
     */
    public function sendNewComment(string $to, string $userName, array $ticket, string $commenterName): bool
    {
        $subject = "[App Tickets] Nuevo comentario en ticket #{$ticket['id']}";
        $body    = $this->render('new-comment', [
            'userName'     => $userName,
            'ticket'       => $ticket,
            'commenterName' => $commenterName,
        ]);

        return $this->send($to, $subject, $body);
    }

    // ─── Core ───────────────────────────────────────────────────────────

    /**
     * Envía un email. Si no hay SMTP configurado, lo escribe al log.
     */
    private function send(string $to, string $subject, string $body): bool
    {
        if (empty($this->smtpHost)) {
            return $this->logToFile($to, $subject, $body);
        }

        return $this->sendViaSmtp($to, $subject, $body);
    }

    private function sendViaSmtp(string $to, string $subject, string $body): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->Port       = $this->smtpPort;
            $mail->SMTPAuth   = !empty($this->smtpUser);
            $mail->Username   = $this->smtpUser;
            $mail->Password   = $this->smtpPass;
            $mail->SMTPSecure = $this->smtpSecure ?: false;

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->isHTML(false);

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            // Fallback: escribir al log si SMTP falla
            $this->logToFile($to, $subject, "[Error SMTP: {$e->getMessage()}]\n\n{$body}");
            return false;
        }
    }

    /**
     * Escribe el email al archivo de log (desarrollo local sin SMTP).
     */
    private function logToFile(string $to, string $subject, string $body): bool
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $entry     = <<<LOG
        ═══════════════════════════════════════════════════════════════
        Fecha   : {$timestamp}
        Para    : {$to}
        Asunto  : {$subject}
        ───────────────────────────────────────────────────────────────
        {$body}
        ═══════════════════════════════════════════════════════════════
        LOG;

        return file_put_contents($this->logPath, $entry . "\n\n", FILE_APPEND | LOCK_EX) !== false;
    }

    // ─── Plantillas ────────────────────────────────────────────────────

    private function render(string $template, array $vars): string
    {
        $userName    = $vars['userName'] ?? '';
        $ticket      = $vars['ticket'] ?? [];
        $ticketId    = $ticket['gitea_issue_id'] ?? $ticket['id'] ?? '?';
        $subject     = $ticket['subject'] ?? '';
        $description = $ticket['description'] ?? '';

        return match ($template) {
            'ticket-created' => <<<HTML
            Hola {$userName},

            Tu ticket de soporte ha sido creado correctamente.

            ─────────────────────────────
            Ticket #{$ticketId}
            Asunto: {$subject}

            {$description}
            ─────────────────────────────

            Te notificaremos cuando haya novedades.

            Saludos,
            App Tickets
            HTML,

            'status-changed' => <<<HTML
            Hola {$userName},

            El estado de tu ticket ha cambiado.

            ─────────────────────────────
            Ticket #{$ticketId}
            Asunto: {$subject}
            Nuevo estado: {$vars['statusLabel']}
            ─────────────────────────────

            Saludos,
            App Tickets
            HTML,

            'new-comment' => <<<HTML
            Hola {$userName},

            {$vars['commenterName']} ha comentado en tu ticket.

            ─────────────────────────────
            Ticket #{$ticketId}
            Asunto: {$subject}
            ─────────────────────────────

            Entra en la aplicación para ver el comentario completo.

            Saludos,
            App Tickets
            HTML,
        };
    }
}
