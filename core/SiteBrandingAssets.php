<?php

declare(strict_types=1);

namespace Tomos;

final class SiteBrandingAssets
{
    private const MAX_IMAGE_BYTES = 10485760;

    /** @var array<string,string> */
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** @var array<string,string> */
    private const EXTENSION_MIMES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    private string $assetsDir;

    public function __construct(string $rootDir)
    {
        $this->assetsDir = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'theme-assets';
    }

    /** @return array{kind:string,file:string,path:string,mime:string,bytes:int,width:int,height:int,hash:string}|null */
    public function asset(string $kind): ?array
    {
        if (!$this->validKind($kind)) {
            return null;
        }

        foreach (self::EXTENSION_MIMES as $extension => $expectedMime) {
            $file = $kind . '.' . $extension;
            $path = $this->assetsDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }

            $info = ImageProcessingSupport::inspect($path);
            if ($info['mime'] !== $expectedMime || $info['width'] <= 0 || $info['height'] <= 0) {
                continue;
            }

            $hash = hash_file('sha256', $path);
            return [
                'kind' => $kind,
                'file' => $file,
                'path' => $path,
                'mime' => $expectedMime,
                'bytes' => $info['bytes'],
                'width' => $info['width'],
                'height' => $info['height'],
                'hash' => is_string($hash) ? $hash : '',
            ];
        }

        return null;
    }

    public function publicUrl(string $kind, string $publicBasePath): string
    {
        $asset = $this->asset($kind);
        if ($asset === null) {
            return '';
        }

        $url = Security::publicUrl('/theme-assets/' . rawurlencode($asset['file']), $publicBasePath);
        return $asset['hash'] !== '' ? $url . '?v=' . rawurlencode($asset['hash']) : $url;
    }

    public function absoluteUrl(string $kind, string $siteUrl, string $publicBasePath): string
    {
        $asset = $this->asset($kind);
        if ($asset === null) {
            return '';
        }

        $url = Security::absolutePublicUrl(
            $siteUrl,
            '/theme-assets/' . rawurlencode($asset['file']),
            $publicBasePath
        );
        return $asset['hash'] !== '' ? $url . '?v=' . rawurlencode($asset['hash']) : $url;
    }

    /** @return array{ok:bool,message:string} */
    public function saveUploaded(string $kind, array $file): array
    {
        if (!$this->validKind($kind)) {
            return ['ok' => false, 'message' => '変更対象の画像が正しくありません。'];
        }

        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => $this->uploadErrorMessage($error)];
        }

        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($tmpName === '' || !is_file($tmpName)) {
            return ['ok' => false, 'message' => 'アップロードした画像を確認できませんでした。'];
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'message' => 'アップロードした画像を確認できませんでした。'];
        }

        $info = ImageProcessingSupport::inspect($tmpName);
        if ($info['bytes'] <= 0) {
            return ['ok' => false, 'message' => '空の画像ファイルは使用できません。'];
        }
        if ($info['bytes'] > self::MAX_IMAGE_BYTES) {
            return ['ok' => false, 'message' => '画像は10MB以下にしてください。'];
        }

        $extension = self::MIME_EXTENSIONS[$info['mime']] ?? '';
        if ($extension === '' || $info['width'] <= 0 || $info['height'] <= 0) {
            return ['ok' => false, 'message' => 'PNG、JPEG、WebPの画像を選んでください。'];
        }

        if (!$this->ensureAssetsDir()) {
            return ['ok' => false, 'message' => 'サイト画像の保存先を作成できませんでした。theme-assets の書き込み権限を確認してください。'];
        }

        try {
            $temporaryPath = $this->assetsDir . DIRECTORY_SEPARATOR . '.tomos-' . $kind . '-' . bin2hex(random_bytes(8)) . '.tmp';
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => '画像の保存準備に失敗しました。'];
        }

        $moved = PHP_SAPI === 'cli'
            ? @copy($tmpName, $temporaryPath)
            : @move_uploaded_file($tmpName, $temporaryPath);
        if (!$moved || !is_file($temporaryPath)) {
            @unlink($temporaryPath);
            return ['ok' => false, 'message' => '画像を保存できませんでした。theme-assets の書き込み権限を確認してください。'];
        }

        @chmod($temporaryPath, 0644);
        $destination = $this->assetsDir . DIRECTORY_SEPARATOR . $kind . '.' . $extension;
        if (!@rename($temporaryPath, $destination)) {
            @unlink($temporaryPath);
            return ['ok' => false, 'message' => '画像を保存できませんでした。元の画像は維持されています。'];
        }
        @chmod($destination, 0644);

        foreach (array_keys(self::EXTENSION_MIMES) as $otherExtension) {
            if ($otherExtension !== $extension) {
                @unlink($this->assetsDir . DIRECTORY_SEPARATOR . $kind . '.' . $otherExtension);
            }
        }

        return [
            'ok' => true,
            'message' => $kind === 'favicon' ? 'Faviconを変更しました。' : '共通OGP画像を変更しました。',
        ];
    }

    public function remove(string $kind): bool
    {
        if (!$this->validKind($kind)) {
            return false;
        }

        $ok = true;
        foreach (array_keys(self::EXTENSION_MIMES) as $extension) {
            $path = $this->assetsDir . DIRECTORY_SEPARATOR . $kind . '.' . $extension;
            if (is_file($path) && !@unlink($path)) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function validKind(string $kind): bool
    {
        return $kind === 'favicon' || $kind === 'ogp';
    }

    private function ensureAssetsDir(): bool
    {
        if (is_dir($this->assetsDir)) {
            return is_writable($this->assetsDir);
        }

        return @mkdir($this->assetsDir, 0755, true) || is_dir($this->assetsDir);
    }

    private function uploadErrorMessage(int $error): string
    {
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '画像を選んでください。';
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return '画像の容量がサーバーの受信上限を超えています。';
        }
        if ($error === UPLOAD_ERR_PARTIAL) {
            return '画像のアップロードが途中で終了しました。もう一度選んでください。';
        }

        return '画像をアップロードできませんでした。もう一度お試しください。';
    }
}
