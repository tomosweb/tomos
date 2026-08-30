<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'PostInbox' => 'PostInbox.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostInboxAutoPublisher
{
    private PostInbox $inbox;
    private array $config;
    private string $rootDir;
    private ?PostUpload $upload = null;

    public function __construct(PostInbox $inbox, array $config, string $rootDir)
    {
        $this->inbox = $inbox;
        $this->config = $config;
        $this->rootDir = $rootDir;
    }

    /**
     * @return array{messages: string[], warnings: string[], pending: array<int,array{temp_id:string,path:string}>}
     */
    public function process(?string $sessionId, string $submissionId): array
    {
        $messages = [];
        $warnings = [];
        $pending = [];
        $lockHandle = @fopen($this->inbox->autoPublishLockPath(), 'c');
        if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                @fclose($lockHandle);
            }
            return ['messages' => [], 'warnings' => [], 'pending' => []];
        }

        try {
        foreach ($this->inbox->list() as $item) {
            $read = $this->inbox->read($item->path);
            if (!$read->ok) {
                $warnings[] = '「' . $item->fileName . '」は自動公開できなかったため受信箱に残しています。';
                continue;
            }
            if ($this->inbox->isDraft($read->content, $read->fileName)) {
                continue;
            }

            $result = $this->upload()->handleContent(
                $read->content,
                $read->fileName,
                $this->inbox->folderFromMarkdown($read->content),
                '',
                $sessionId,
                [],
                [],
                false,
                $submissionId
            );
            if ($result->ok) {
                if ($this->inbox->delete($read->path)) {
                    $messages[] = '受信箱から「' . $item->fileName . '」を自動公開しました。';
                } else {
                    $warnings[] = '「' . $item->fileName . '」は公開されましたが、受信箱から削除できませんでした。';
                }
                continue;
            }

            if ($result->conflict && $result->tempId !== '') {
                $temp = $this->upload()->loadTemp($result->tempId, $sessionId);
                if ($temp !== null && $this->upload()->isPublishedContentEquivalent($result->contentPath, $temp->markdown)) {
                    $this->upload()->cancelTemp($result->tempId, $sessionId);
                    if ($this->inbox->delete($read->path)) {
                        $messages[] = '「' . $item->fileName . '」はすでに公開済みのため、重複原稿を整理しました。';
                    } else {
                        $warnings[] = '「' . $item->fileName . '」はすでに公開済みですが、受信箱から削除できませんでした。';
                    }
                    continue;
                } elseif ($sessionId !== null && $temp !== null) {
                    $pending[] = ['temp_id' => $result->tempId, 'path' => $read->path];
                    continue;
                }
                $this->upload()->cancelTemp($result->tempId, $sessionId);
            }
            $warnings[] = '「' . $item->fileName . '」は自動公開できなかったため受信箱に残しています。';
        }
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        return ['messages' => $messages, 'warnings' => $warnings, 'pending' => $pending];
    }

    private function upload(): PostUpload
    {
        if ($this->upload === null) {
            if (!class_exists(PostUpload::class)) {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'PostUpload.php';
            }
            $this->upload = new PostUpload($this->config, $this->rootDir);
        }
        return $this->upload;
    }
}
