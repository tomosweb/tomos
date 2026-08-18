<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class PostPasswordHashUpdater
{
    private string $configPath;
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->configPath = $this->rootDir . DIRECTORY_SEPARATOR . 'config.php';
    }

    public function update(string $passwordHash): void
    {
        if ($passwordHash === '' || strlen($passwordHash) > 1024) {
            throw new RuntimeException('管理用合言葉のハッシュを作成できませんでした。');
        }

        try {
            ConfigWriteLock::run($this->rootDir, function () use ($passwordHash): void {
                $this->updateUnlocked($passwordHash);
            });
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('config.php を更新できませんでした。');
        }
    }

    private function updateUnlocked(string $passwordHash): void
    {
        $source = @file_get_contents($this->configPath);
        if (!is_string($source) || $source === '') {
            throw new RuntimeException('config.php を読み込めませんでした。');
        }

        $tokens = token_get_all($source);
        $matches = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || !$this->isPostPasswordHashKey($token[1])) {
                continue;
            }

            $arrowIndex = $this->nextSignificantTokenIndex($tokens, $i + 1);
            if ($arrowIndex === null || !is_array($tokens[$arrowIndex]) || $tokens[$arrowIndex][0] !== T_DOUBLE_ARROW) {
                continue;
            }

            $valueIndex = $this->nextSignificantTokenIndex($tokens, $arrowIndex + 1);
            if ($valueIndex === null || !is_array($tokens[$valueIndex]) || $tokens[$valueIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $quote = substr($tokens[$valueIndex][1], 0, 1);
            if ($quote !== "'" && $quote !== '"') {
                continue;
            }

            $escapedHash = $quote === "'"
                ? str_replace(['\\', "'"], ['\\\\', "\\'"], $passwordHash)
                : str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $passwordHash);

            $tokens[$valueIndex][1] = $quote . $escapedHash . $quote;
            $matches++;
        }

        if ($matches !== 1) {
            throw new RuntimeException('config.php の post_password_hash を一意に確認できませんでした。');
        }

        $updated = '';
        foreach ($tokens as $token) {
            $updated .= is_array($token) ? $token[1] : $token;
        }

        if ($updated === $source) {
            throw new RuntimeException('管理用合言葉の設定を更新できませんでした。');
        }

        $dir = dirname($this->configPath);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.config.php.tmp-' . bin2hex(random_bytes(6));
        $mode = @fileperms($this->configPath);

        if (@file_put_contents($tmp, $updated, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('config.php の一時ファイルを書き込めませんでした。');
        }

        if (is_int($mode)) {
            @chmod($tmp, $mode & 0777);
        }

        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('config.php を更新できませんでした。');
        }
    }

    /** @param array<int,array|string> $tokens */
    private function nextSignificantTokenIndex(array $tokens, int $start): ?int
    {
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    private function isPostPasswordHashKey(string $tokenText): bool
    {
        if (strlen($tokenText) < 2) {
            return false;
        }
        $quote = substr($tokenText, 0, 1);
        if (($quote !== "'" && $quote !== '"') || substr($tokenText, -1) !== $quote) {
            return false;
        }
        $value = substr($tokenText, 1, -1);
        return $value === 'post_password_hash';
    }
}
