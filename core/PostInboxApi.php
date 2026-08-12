<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'PostInbox' => 'PostInbox.php',
    'PostPassword' => 'PostPassword.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostInboxApiResponse
{
    public int $status;
    /** @var array<string,mixed> */
    public array $payload;

    /** @param array<string,mixed> $payload */
    public function __construct(int $status, array $payload)
    {
        $this->status = $status;
        $this->payload = $payload;
    }
}

final class PostInboxApi
{
    private PostInbox $inbox;
    private string $tokenHash;

    public function __construct(PostInbox $inbox, array $config)
    {
        $this->inbox = $inbox;
        $this->tokenHash = (string) (($config['security']['inbox_api_token_hash'] ?? '') ?: '');
    }

    /** @param array<string,string> $headers @param array<string,mixed> $server */
    public function handle(string $method, array $headers, string $body, array $server): PostInboxApiResponse
    {
        if (!$this->isHttps($server)) {
            return $this->response(400, 'HTTPSで接続してください。');
        }

        $token = $this->tokenFromHeaders($headers);
        if ($token === '' || !PostPassword::verify($token, $this->tokenHash)) {
            return $this->response(401, '投稿用トークンが正しくありません。');
        }

        if (strtoupper($method) === 'GET') {
            return new PostInboxApiResponse(200, ['ok' => true, 'message' => 'Tomos Inbox APIに接続できます。']);
        }
        if (strtoupper($method) !== 'POST') {
            return $this->response(405, 'POSTで送信してください。');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->response(400, '送信データが正しくありません。');
        }
        $fileName = $payload['filename'] ?? null;
        $content = $payload['content'] ?? null;
        if (!is_string($fileName) || !is_string($content)) {
            return $this->response(400, 'ファイル名とMarkdown本文が必要です。');
        }

        $result = $this->inbox->receive($fileName, $content);
        return new PostInboxApiResponse($result->status, [
            'ok' => $result->ok,
            'message' => $result->message,
        ]);
    }

    /** @param array<string,string> $headers */
    private function tokenFromHeaders(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-tomos-token') {
                return trim($value);
            }
            if (strtolower($name) === 'authorization' && preg_match('/\ABearer\s+(.+)\z/i', trim($value), $matches) === 1) {
                return trim($matches[1]);
            }
        }
        return '';
    }

    /** @param array<string,mixed> $server */
    private function isHttps(array $server): bool
    {
        if (strtolower((string) ($server['HTTPS'] ?? '')) === 'on' || (string) ($server['HTTPS'] ?? '') === '1') {
            return true;
        }
        if ((int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0])) === 'https';
    }

    private function response(int $status, string $message): PostInboxApiResponse
    {
        return new PostInboxApiResponse($status, ['ok' => false, 'message' => $message]);
    }
}
