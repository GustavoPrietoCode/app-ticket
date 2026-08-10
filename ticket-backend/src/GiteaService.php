<?php

namespace Gus\MyFlightApp;

class GiteaService
{
    private string $baseUrl;
    private string $token;
    private string $owner;
    private string $repo;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->token   = $config['token'];
        $this->owner   = $config['owner'];
        $this->repo    = $config['repo'];
    }

    /**
     * Crea un issue en Gitea y devuelve número + url, o null si falla.
     */
    public function createIssue(string $title, string $description, string $reporterName, string $reporterEmail): ?array
    {
        $url = "{$this->baseUrl}/api/v1/repos/{$this->owner}/{$this->repo}/issues";

        $body = "**Reportado por:** {$reporterName} <{$reporterEmail}>\n\n{$description}";

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            // Entorno local con certificado self-signed o similar
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 || !$response) {
            return null;
        }

        $issue = json_decode($response, true);

        return [
            'number' => $issue['number'] ?? null,
            'url'    => $issue['html_url'] ?? null,
        ];
    }
}
