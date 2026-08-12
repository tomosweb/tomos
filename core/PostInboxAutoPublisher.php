<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'PostInbox' => 'PostInbox.php',
    'PostUpload' => 'PostUpload.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostInboxAutoPublisher
{
    private PostInbox $inbox;
    private PostUpload $upload;

    public function __construct(PostInbox $inbox, PostUpload $upload)
    {
        $this->inbox = $inbox;
        $this->upload = $upload;
    }

    /**
     * @return array{messages: string[], warnings: string[]}
     */
    public function process(?string $sessionId, string $submissionId): array
    {
        $messages = [];
        $warnings = [];

        foreach ($this->inbox->list() as $item) {
            $read = $this->inbox->read($item->path);
            if (!$read->ok) {
                $warnings[] = '「' . $item->fileName . '」は自動公開できなかったため受信箱に残しています。';
                continue;
            }
            if ($this->inbox->isDraft($read->content, $read->fileName)) {
                continue;
            }

            $result = $this->upload->handleContent(
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
                $this->upload->cancelTemp($result->tempId, $sessionId);
            }
            $warnings[] = '「' . $item->fileName . '」は自動公開できなかったため受信箱に残しています。';
        }

        return ['messages' => $messages, 'warnings' => $warnings];
    }
}
