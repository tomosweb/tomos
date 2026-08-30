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
            return new PostInboxApiResponse(200, ['ok' => true, 'message' => 'Tomos投稿APIに接続できます。']);
        }
        if (strtoupper($method) !== 'POST') {
            return $this->response(405, 'POSTで送信してください。');
        }

        if (strtolower($this->header($headers, 'x-tomos-action')) === 'image') {
            return $this->resultResponse($this->inbox->receiveImage(
                $this->header($headers, 'x-tomos-upload-id'),
                $this->header($headers, 'x-tomos-image-name'),
                $body,
                $this->integerHeader($headers, 'x-tomos-chunk-index', 0),
                $this->integerHeader($headers, 'x-tomos-chunk-count', 1),
                $this->integerHeader($headers, 'x-tomos-total-size', strlen($body))
            ));
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->response(400, '送信データが正しくありません。');
        }
        $action = strtolower(trim(is_string($payload['action'] ?? null) ? $payload['action'] : ''));
        if ($action === 'start') {
            $fileName = $payload['filename'] ?? null;
            $content = $payload['content'] ?? null;
            $images = $payload['images'] ?? null;
            if (!is_string($fileName) || !is_string($content) || !is_array($images)) return $this->response(400, 'ファイル名、Markdown本文、画像一覧が必要です。');
            return $this->resultResponse($this->inbox->beginImageReceive($fileName, $content, $images));
        }
        if ($action === 'finalize') {
            $uploadId = $payload['upload_id'] ?? null;
            return is_string($uploadId) ? $this->resultResponse($this->inbox->finalizeImageReceive($uploadId)) : $this->response(400, '画像の受信情報が必要です。');
        }
        if ($action === 'cancel') {
            $uploadId = $payload['upload_id'] ?? null;
            if (!is_string($uploadId)) return $this->response(400, '画像の受信情報が必要です。');
            $deleted = $this->inbox->cancelImageReceive($uploadId);
            return new PostInboxApiResponse($deleted ? 200 : 404, ['ok' => $deleted, 'message' => $deleted ? '画像の送信を中止しました。' : '画像の受信情報が見つかりません。']);
        }

        $fileName = $payload['filename'] ?? null;
        $content = $payload['content'] ?? null;
        if (!is_string($fileName) || !is_string($content)) {
            return $this->response(400, 'ファイル名とMarkdown本文が必要です。');
        }

        return $this->resultResponse($this->inbox->receive($fileName, $content));
    }

    /** @param array<string,string> $headers */
    private function tokenFromHeaders(array $headers): string
    {
        $token = $this->header($headers, 'x-tomos-token');
        if ($token !== '') return $token;
        $authorization = $this->header($headers, 'authorization');
        return preg_match('/\ABearer\s+(.+)\z/i', $authorization, $matches) === 1 ? trim($matches[1]) : '';
    }

    /** @param array<string,string> $headers */
    private function header(array $headers, string $wanted): string
    {
        foreach ($headers as $name => $value) if (strtolower($name) === strtolower($wanted)) return trim($value);
        return '';
    }

    /** @param array<string,string> $headers */
    private function integerHeader(array $headers, string $wanted, int $default): int
    {
        $value = $this->header($headers, $wanted);
        return $value !== '' && preg_match('/\A\d+\z/', $value) === 1 ? (int) $value : $default;
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

    private function resultResponse(PostInboxReceiveResult $result): PostInboxApiResponse
    {
        $payload = ['ok' => $result->ok, 'message' => $result->message];
        if ($result->uploadId !== '') $payload['upload_id'] = $result->uploadId;
        return new PostInboxApiResponse($result->status, $payload);
    }
}
