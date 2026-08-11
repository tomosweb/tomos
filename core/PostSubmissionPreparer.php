<?php

declare(strict_types=1);

namespace Tomos;

final class PreparedPostSubmission
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    public string $content;
    public string $originalFileName;
    public string $chosenFileName;
    public string $safeFileName;
    public string $folder;
    public string $basicPageType;
    public bool $editable;
    /** @var array<string,mixed> */
    public array $editableInfo;

    /** @param string[] $errors @param array<string,mixed> $editableInfo */
    public function __construct(
        bool $ok,
        array $errors = [],
        string $content = '',
        string $originalFileName = '',
        string $chosenFileName = '',
        string $safeFileName = '',
        string $folder = '',
        string $basicPageType = '',
        bool $editable = false,
        array $editableInfo = []
    ) {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->content = $content;
        $this->originalFileName = $originalFileName;
        $this->chosenFileName = $chosenFileName;
        $this->safeFileName = $safeFileName;
        $this->folder = $folder;
        $this->basicPageType = $basicPageType;
        $this->editable = $editable;
        $this->editableInfo = $editableInfo;
    }
}

final class PostSubmissionPreparer
{
    /** @var string[] */
    private const ACCEPTED_EXTENSIONS = ['md', 'markdown', 'txt'];
    /** @var string[] */
    private const DANGEROUS_EXTENSION_SEGMENTS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'html', 'htm', 'js', 'css', 'svg'];
    /** @var array<string,string> */
    private const JAPANESE_NFC_FALLBACK = [
        'ゔ' => 'ゔ',
        'が' => 'が', 'ぎ' => 'ぎ', 'ぐ' => 'ぐ', 'げ' => 'げ', 'ご' => 'ご',
        'ざ' => 'ざ', 'じ' => 'じ', 'ず' => 'ず', 'ぜ' => 'ぜ', 'ぞ' => 'ぞ',
        'だ' => 'だ', 'ぢ' => 'ぢ', 'づ' => 'づ', 'で' => 'で', 'ど' => 'ど',
        'ば' => 'ば', 'び' => 'び', 'ぶ' => 'ぶ', 'べ' => 'べ', 'ぼ' => 'ぼ',
        'ぱ' => 'ぱ', 'ぴ' => 'ぴ', 'ぷ' => 'ぷ', 'ぺ' => 'ぺ', 'ぽ' => 'ぽ',
        'ゞ' => 'ゞ', 'ヴ' => 'ヴ',
        'ガ' => 'ガ', 'ギ' => 'ギ', 'グ' => 'グ', 'ゲ' => 'ゲ', 'ゴ' => 'ゴ',
        'ザ' => 'ザ', 'ジ' => 'ジ', 'ズ' => 'ズ', 'ゼ' => 'ゼ', 'ゾ' => 'ゾ',
        'ダ' => 'ダ', 'ヂ' => 'ヂ', 'ヅ' => 'ヅ', 'デ' => 'デ', 'ド' => 'ド',
        'バ' => 'バ', 'ビ' => 'ビ', 'ブ' => 'ブ', 'ベ' => 'ベ', 'ボ' => 'ボ',
        'パ' => 'パ', 'ピ' => 'ピ', 'プ' => 'プ', 'ペ' => 'ペ', 'ポ' => 'ポ',
        'ヷ' => 'ヷ', 'ヸ' => 'ヸ', 'ヹ' => 'ヹ', 'ヺ' => 'ヺ', 'ヾ' => 'ヾ',
    ];

    private PostEditableMarkdown $editableMarkdown;

    public function __construct(PostEditableMarkdown $editableMarkdown)
    {
        $this->editableMarkdown = $editableMarkdown;
    }

    public function prepare(string $content, string $originalFileName, string $folderInput, string $fileNameInput): PreparedPostSubmission
    {
        $errors = [];
        $chosenName = trim($fileNameInput) !== '' ? trim($fileNameInput) : $originalFileName;
        $basicPageType = PostBasicPage::typeFromFileName($chosenName);
        $safeFileName = $this->normalizeFileName($chosenName, $errors);
        // Basic pages always belong directly below content/, regardless of UI or frontmatter input.
        $folder = $basicPageType !== '' ? '' : $this->normalizeFolder($folderInput, $errors);
        if ($basicPageType === '' && PostBasicPage::isProtectedContentPath($safeFileName)) {
            $errors[] = 'トップページは index.md、Aboutページは about.md の正式なファイル名で投稿してください。';
        }
        if ($this->looksBinary($content)) {
            $errors[] = 'テキストファイルとして読み込めない内容が含まれています。';
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            $errors[] = '文字コードはUTF-8のファイルを投稿してください。';
        }
        if ($errors !== []) {
            return $this->result(false, $errors, $content, $originalFileName, $chosenName, $safeFileName, $folder, $basicPageType);
        }

        $editable = $this->editableMarkdown->inspectReupload($content);
        if (empty($editable['ok'])) {
            return $this->result(false, [(string) ($editable['error'] ?? '編集元の情報を確認できませんでした。')], $content, $originalFileName, $chosenName, $safeFileName, $folder, $basicPageType, false, $editable);
        }

        $isEditable = !empty($editable['editable']);
        if ($isEditable) {
            $content = (string) ($editable['markdown'] ?? '');
            $sourcePath = (string) ($editable['source_path'] ?? '');
            $sourceBasicPageType = PostBasicPage::isProtectedContentPath($sourcePath)
                ? PostBasicPage::typeFromFileName(basename($sourcePath))
                : '';
            if ($sourceBasicPageType !== '') {
                $basicPageType = $sourceBasicPageType;
                $folder = '';
            } elseif ($basicPageType !== '') {
                // A folder index.md/about.md is an ordinary article, not a root basic page.
                $basicPageType = '';
                $folder = $this->normalizeFolder($folderInput, $errors);
            }
            if ($errors !== []) {
                return $this->result(false, $errors, $content, $originalFileName, $chosenName, $safeFileName, $folder, $basicPageType, true, $editable);
            }
        }

        return $this->result(true, [], $content, $originalFileName, $chosenName, $safeFileName, $folder, $basicPageType, $isEditable, $editable);
    }

    /** @param string[] $errors */
    public function normalizeFolder(string $folder, array &$errors): string
    {
        $rawFolder = trim($folder);
        if ($rawFolder !== '' && ($rawFolder[0] === '/' || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\//', $rawFolder) === 1 || preg_match('/\A[A-Za-z]:/', $rawFolder) === 1)) {
            $errors[] = '保存先フォルダに危険なパス指定が含まれています。';
            return '';
        }
        $folder = trim(str_replace('\\', '/', $rawFolder));
        $folder = preg_replace('#/+#', '/', $folder) ?? $folder;
        $folder = trim($folder, '/');
        if ($folder === '') return '';
        if (!$this->isSafePathText($folder) || preg_match('#(^|/)\.\.?(/|$)#', $folder) === 1) {
            $errors[] = '保存先フォルダに危険なパス指定が含まれています。';
            return '';
        }
        $segments = explode('/', $folder);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $errors[] = '保存先フォルダに危険なパス指定が含まれています。';
                return '';
            }
        }
        return implode('/', $segments);
    }

    /** @param string[] $errors */
    public function normalizeFileName(string $fileName, array &$errors): string
    {
        $fileName = $this->normalizeUnicodeNfc($fileName);
        $fileName = trim($this->removeControlCharacters($fileName));
        $fileName = str_replace(['/', '\\'], '-', $fileName);
        if ($fileName === '') {
            $errors[] = 'ファイル名が正しくありません。';
            return '';
        }
        $parts = explode('.', $fileName);
        if (count($parts) < 2) {
            $errors[] = '拡張子 .md / .markdown / .txt のファイルを指定してください。';
            return '';
        }
        $extension = strtolower((string) array_pop($parts));
        $stem = implode('.', $parts);
        if (!in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            $errors[] = '投稿できるファイルは .md / .markdown / .txt です。';
            return '';
        }
        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::DANGEROUS_EXTENSION_SEGMENTS, true)) {
                $errors[] = '危険な拡張子を含むファイル名は投稿できません。';
                return '';
            }
        }
        $stem = $this->sanitizeFileNameStem($stem);
        if ($stem === '') $stem = 'untitled-' . date('Ymd-His');
        return $stem . '.md';
    }

    public function normalizeUnicodeNfc(string $value): string
    {
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) return $normalized;
        }
        return strtr($value, self::JAPANESE_NFC_FALLBACK);
    }

    /** @param string[] $errors @param array<string,mixed> $editableInfo */
    private function result(bool $ok, array $errors, string $content, string $originalFileName, string $chosenFileName, string $safeFileName, string $folder, string $basicPageType, bool $editable = false, array $editableInfo = []): PreparedPostSubmission
    {
        return new PreparedPostSubmission($ok, $errors, $content, $originalFileName, $chosenFileName, $safeFileName, $folder, $basicPageType, $editable, $editableInfo);
    }

    private function sanitizeFileNameStem(string $stem): string
    {
        $stem = $this->removeControlCharacters($stem);
        $stem = str_replace(['/', '\\'], '-', $stem);
        $stem = preg_replace('/[:*?"<>|#%&+=;]+/u', '-', $stem) ?? $stem;
        $stem = preg_replace('/-+/u', '-', $stem) ?? $stem;
        $stem = preg_replace('/\s*-\s*/u', '-', $stem) ?? $stem;
        while (strpos($stem, '..') !== false) $stem = str_replace('..', '.', $stem);
        $stem = trim($stem);
        return trim($stem, ".- \t\n\r\0\x0B");
    }

    private function removeControlCharacters(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }

    private function isSafePathText(string $value): bool
    {
        return strpos($value, "\0") === false && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 && strpos($value, ':') === false && strpos($value, '\\') === false;
    }

    private function looksBinary(string $content): bool
    {
        if (strpos($content, "\0") !== false) return true;
        return preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1;
    }
}
