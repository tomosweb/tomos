<?php

declare(strict_types=1);

namespace Tomos;

final class SocialPublishResult
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public string $status;
    public string $provider;
    public string $code;
    public string $message;
    public string $remoteUri;
    public string $remoteCid;

    public function __construct(
        string $status,
        string $provider,
        string $code,
        string $message,
        string $remoteUri = '',
        string $remoteCid = ''
    ) {
        $this->status = $status;
        $this->provider = $provider;
        $this->code = $code;
        $this->message = $message;
        $this->remoteUri = $remoteUri;
        $this->remoteCid = $remoteCid;
    }

    public static function skipped(string $provider, string $code, string $message): self
    {
        return new self(self::SKIPPED, $provider, $code, $message);
    }

    public static function failed(
        string $provider,
        string $code,
        string $message,
        string $remoteUri = '',
        string $remoteCid = ''
    ): self {
        return new self(self::FAILED, $provider, $code, $message, $remoteUri, $remoteCid);
    }

    public static function success(string $provider, string $message, string $remoteUri = '', string $remoteCid = ''): self
    {
        return new self(self::SUCCESS, $provider, 'success', $message, $remoteUri, $remoteCid);
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'provider' => $this->provider,
            'code' => $this->code,
            'message' => $this->message,
            'remote_uri' => $this->remoteUri,
            'remote_cid' => $this->remoteCid,
        ];
    }
}
