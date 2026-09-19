<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'PostInbox' => 'PostInbox.php',
    'PublisherStatusStore' => 'PublisherStatusStore.php',
    'PublisherArticleStore' => 'PublisherArticleStore.php',
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
    private PublisherStatusStore $statusStore;
    private PublisherArticleStore $articleStore;

    public function __construct(PostInbox $inbox, array $config, string $rootDir)
    {
        $this->inbox = $inbox;
        $this->config = $config;
        $this->rootDir = $rootDir;
        $this->statusStore = new PublisherStatusStore($config, $rootDir);
        $this->articleStore = new PublisherArticleStore($config, $rootDir);
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
            $requestId = $this->inbox->publisherRequestId($item->fileName);
            $read = $this->inbox->read($item->path);
            if (!$read->ok) {
                $warnings[] = '「' . $item->fileName . '」を下書きとして保存できませんでした。';
                $this->saveStatus($requestId, [
                    'state' => 'error',
                    'filename' => $item->fileName,
                    'message' => 'Markdownを読み込めませんでした。',
                    'article_url' => '',
                    'social' => null,
                ]);
                continue;
            }
            if ($this->inbox->isDraft($read->content, $read->fileName)) {
                $this->saveStatus($requestId, [
                    'state' => 'draft',
                    'filename' => $item->fileName,
                    'message' => '下書きとして保存しました。',
                    'article_url' => '',
                    'social' => null,
                ]);
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
                if ($requestId !== '' && $result->contentPath !== '') {
                    $this->articleStore->markManaged($result->contentPath, $requestId);
                }
                $this->saveStatus($requestId, [
                    'state' => 'published',
                    'filename' => $item->fileName,
                    'message' => '記事を公開しました。',
                    'article_url' => $result->absoluteUrl,
                    'social' => $result->socialResult instanceof SocialPublishResult
                        ? $result->socialResult->toArray()
                        : null,
                ]);
                if ($this->inbox->delete($read->path)) {
                    $messages[] = '「' . $item->fileName . '」を自動公開しました。';
                } else {
                    $warnings[] = '「' . $item->fileName . '」は公開されましたが、原稿を整理できませんでした。';
                }
                continue;
            }

            if ($result->conflict && $result->tempId !== '') {
                $temp = $this->upload()->loadTemp($result->tempId, $sessionId);
                if ($temp !== null && $this->upload()->isPublishedContentEquivalent($result->contentPath, $temp->markdown)) {
                    $this->upload()->cancelTemp($result->tempId, $sessionId);
                    $this->saveStatus($requestId, [
                        'state' => 'already_published',
                        'filename' => $item->fileName,
                        'message' => '同じ内容の記事はすでに公開済みです。',
                        'article_url' => $result->absoluteUrl,
                        'social' => null,
                    ]);
                    if ($this->inbox->delete($read->path)) {
                        $messages[] = '「' . $item->fileName . '」はすでに公開済みのため、重複原稿を整理しました。';
                    } else {
                        $warnings[] = '「' . $item->fileName . '」はすでに公開済みですが、重複原稿を整理できませんでした。';
                    }
                    continue;
                } elseif (
                    $requestId !== ''
                    && $temp !== null
                    && $result->contentPath !== ''
                    && $this->articleStore->isManaged($result->contentPath)
                ) {
                    $updated = $this->upload()->updateFromTemp($result->tempId, $sessionId, $submissionId);
                    if ($updated->ok) {
                        $this->articleStore->markManaged($updated->contentPath, $requestId);
                        $this->saveStatus($requestId, [
                            'state' => 'published',
                            'filename' => $item->fileName,
                            'message' => '記事を更新しました。',
                            'article_url' => $updated->absoluteUrl,
                            'social' => $updated->socialResult instanceof SocialPublishResult
                                ? $updated->socialResult->toArray()
                                : null,
                        ]);
                        if ($this->inbox->delete($read->path)) {
                            $messages[] = '「' . $item->fileName . '」を自動更新しました。';
                        } else {
                            $warnings[] = '「' . $item->fileName . '」は更新されましたが、原稿を整理できませんでした。';
                        }
                    } else {
                        $this->saveStatus($requestId, [
                            'state' => 'needs_attention',
                            'filename' => $item->fileName,
                            'message' => '記事の更新時に競合を検出しました。Tomos Postで確認してください。',
                            'article_url' => $result->absoluteUrl,
                            'social' => null,
                        ]);
                        $warnings[] = '「' . $item->fileName . '」は安全に自動更新できませんでした。Tomos Postで確認してください。';
                    }
                    continue;
                } elseif ($sessionId !== null && $temp !== null) {
                    $this->saveStatus($requestId, [
                        'state' => 'needs_attention',
                        'filename' => $item->fileName,
                        'message' => '同名の記事があるため、Tomos Postで確認が必要です。',
                        'article_url' => '',
                        'social' => null,
                    ]);
                    $pending[] = ['temp_id' => $result->tempId, 'path' => $read->path];
                    continue;
                }
                $this->upload()->cancelTemp($result->tempId, $sessionId);
            }
            if ($this->inbox->markPublisherAutoPublishFailure($read->path)) {
                $this->saveStatus($requestId, [
                    'state' => 'needs_attention',
                    'filename' => $item->fileName,
                    'message' => '自動公開できなかったため、下書きとして保存しました。Tomos Postで確認してください。',
                    'article_url' => '',
                    'social' => null,
                ]);
                $messages[] = '「' . $item->fileName . '」は自動公開できなかったため、下書きとして保存しました。Tomos Postの「下書き」から確認できます。';
            } else {
                $this->saveStatus($requestId, [
                    'state' => 'error',
                    'filename' => $item->fileName,
                    'message' => '自動公開に失敗し、下書きとしても保存できませんでした。',
                    'article_url' => '',
                    'social' => null,
                ]);
                $warnings[] = '「' . $item->fileName . '」を下書きとして保存できませんでした。';
            }
        }
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        return ['messages' => $messages, 'warnings' => $warnings, 'pending' => $pending];
    }

    /** @param array<string,mixed> $status */
    private function saveStatus(string $requestId, array $status): void
    {
        if ($requestId === '') {
            return;
        }
        $this->statusStore->save($requestId, $status);
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
