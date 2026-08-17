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
     * $labelIds opcional: IDs de etiquetas del repo a aplicar al issue.
     */
    public function createIssue(string $title, string $description, string $reporterName, string $reporterEmail, array $labelIds = []): ?array
    {
        $body = "**Reportado por:** {$reporterName} <{$reporterEmail}>\n\n{$description}";

        $payload = [
            'title' => $title,
            'body'  => $body,
        ];

        if (!empty($labelIds)) {
            $payload['labels'] = array_values($labelIds);
        }

        $result = $this->request('POST', '/issues', $payload);

        if (!$result || $result['http'] !== 201) {
            return null;
        }

        $issue = $result['data'];

        return [
            'number' => $issue['number'] ?? null,
            'url'    => $issue['html_url'] ?? null,
        ];
    }

    /**
     * Obtiene el estado actual de un issue desde Gitea.
     */
    public function getIssue(int $number): ?array
    {
        $result = $this->request('GET', "/issues/{$number}");

        if (!$result || $result['http'] !== 200) {
            return null;
        }

        $issue = $result['data'];

        return [
            'state' => $issue['state'] ?? null,       // 'open' | 'closed'
            'title' => $issue['title'] ?? '',
        ];
    }

    /**
     * Abre o cierra un issue en Gitea.
     */
    public function updateIssueStatus(int $number, string $state): bool
    {
        $result = $this->request('PATCH', "/issues/{$number}", [
            'state' => $state,
        ]);

        return $result !== null && $result['http'] === 200;
    }

    /**
     * Obtiene los comentarios de un issue desde Gitea.
     */
    public function getComments(int $number): array
    {
        $result = $this->request('GET', "/issues/{$number}/comments");

        if (!$result || $result['http'] !== 200) {
            return [];
        }

        $comments = $result['data'] ?? [];

        return array_map(function (array $c) {
            return [
                'id'         => $c['id'] ?? null,
                'body'       => $c['body'] ?? '',
                'author'     => $c['user']['login'] ?? 'desconocido',
                'created_at' => $c['created_at'] ?? '',
            ];
        }, $comments);
    }

    /**
     * Obtiene las etiquetas del repositorio desde Gitea.
     */
    public function listLabels(): array
    {
        $result = $this->request('GET', '/labels');

        if (!$result || $result['http'] !== 200) {
            return [];
        }

        $labels = $result['data'] ?? [];

        return array_map(function (array $l) {
            return [
                'id'    => $l['id'] ?? null,
                'name'  => $l['name'] ?? '',
                'color' => $l['color'] ?? '',
            ];
        }, $labels);
    }

    /**
     * Añade un comentario a un issue en Gitea.
     */
    public function addComment(int $number, string $body): bool
    {
        $result = $this->request('POST', "/issues/{$number}/comments", [
            'body' => $body,
        ]);

        return $result !== null && $result['http'] === 201;
    }

    /**
     * Sube un archivo adjunto a un issue. Devuelve la URL del adjunto o null.
     */
    public function uploadAsset(int $number, string $filePath, string $fileName): ?string
    {
        $url = "{$this->baseUrl}/api/v1/repos/{$this->owner}/{$this->repo}/issues/{$number}/assets";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'attachment' => new \CURLFile($filePath, mime_content_type($filePath), $fileName),
            ],
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 || !$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (empty($data['uuid'])) {
            return null;
        }

        // Construir URL pública del adjunto
        return "{$this->baseUrl}/attachments/{$data['uuid']}";
    }

    // ─── Helper HTTP ──────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $payload = null): ?array
    {
        $url  = "{$this->baseUrl}/api/v1/repos/{$this->owner}/{$this->repo}{$path}";
        $json = $payload ? json_encode($payload) : null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Authorization: token ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json ?? ''),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        return [
            'http' => $httpCode,
            'data' => json_decode($response, true),
        ];
    }
}
