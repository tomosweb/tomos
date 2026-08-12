<?php

declare(strict_types=1);

namespace Tomos;

final class MarkdownParser
{
    private bool $allowRawHtml;
    private string $publicBasePath;

    public function __construct(bool $allowRawHtml = false, string $publicBasePath = '')
    {
        $this->allowRawHtml = $allowRawHtml;
        $this->publicBasePath = $publicBasePath;
    }

    public function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $html = [];
        $paragraph = [];
        $inCodeBlock = false;
        $codeLines = [];
        $listType = null;
        $blockquote = [];

        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*```/', $line) === 1) {
                if ($inCodeBlock) {
                    $html[] = '<pre><code>' . $this->escape(implode("\n", $codeLines)) . '</code></pre>';
                    $codeLines = [];
                    $inCodeBlock = false;
                } else {
                    $this->flushParagraph($html, $paragraph);
                    $this->flushList($html, $listType);
                    $this->flushBlockquote($html, $blockquote);
                    $inCodeBlock = true;
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeLines[] = $line;
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                $this->flushParagraph($html, $paragraph);
                $this->flushList($html, $listType);
                $this->flushBlockquote($html, $blockquote);
                continue;
            }

            if ($i + 1 < $lineCount && $this->isTableHeader($line, $lines[$i + 1])) {
                $this->flushParagraph($html, $paragraph);
                $this->flushList($html, $listType);
                $this->flushBlockquote($html, $blockquote);

                $tableLines = [$line, $lines[$i + 1]];
                $i += 2;
                while ($i < $lineCount && $this->isTableBodyLine($lines[$i])) {
                    $tableLines[] = $lines[$i];
                    $i++;
                }
                $i--;

                $html[] = $this->tableHtml($tableLines);
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->flushParagraph($html, $paragraph);
                $this->flushList($html, $listType);
                $this->flushBlockquote($html, $blockquote);
                $level = strlen($matches[1]);
                $html[] = '<h' . $level . '>' . $this->inline($matches[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
                $this->flushParagraph($html, $paragraph);
                $this->flushList($html, $listType);
                $this->flushBlockquote($html, $blockquote);
                $html[] = '<hr>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $matches) === 1) {
                $this->flushParagraph($html, $paragraph);
                $this->flushList($html, $listType);
                $blockquote[] = $matches[1];
                continue;
            }

            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $matches) === 1) {
                $this->flushParagraph($html, $paragraph);
                $this->flushBlockquote($html, $blockquote);
                $this->ensureList($html, $listType, 'ul');
                $html[] = '<li>' . $this->inline($matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $matches) === 1) {
                $this->flushParagraph($html, $paragraph);
                $this->flushBlockquote($html, $blockquote);
                $this->ensureList($html, $listType, 'ol');
                $html[] = '<li>' . $this->inline($matches[1]) . '</li>';
                continue;
            }

            $this->flushList($html, $listType);
            $this->flushBlockquote($html, $blockquote);
            $paragraph[] = $trimmed;
        }

        if ($inCodeBlock) {
            $html[] = '<pre><code>' . $this->escape(implode("\n", $codeLines)) . '</code></pre>';
        }

        $this->flushParagraph($html, $paragraph);
        $this->flushList($html, $listType);
        $this->flushBlockquote($html, $blockquote);

        return implode("\n", $html);
    }

    private function inline(string $text): string
    {
        $codeParts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($codeParts === false) {
            return $this->allowRawHtml ? $text : $this->escape($text);
        }

        $output = '';
        foreach ($codeParts as $part) {
            if (strlen($part) >= 2 && $part[0] === '`' && substr($part, -1) === '`') {
                $output .= '<code>' . $this->escape(substr($part, 1, -1)) . '</code>';
                continue;
            }

            $part = $this->allowRawHtml ? $part : $this->escape($part);

            $part = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $matches): string {
                $label = $matches[1];
                $url = Security::safeHref($matches[2]);
                if (strpos($url, '/') === 0) {
                    $url = Security::publicUrl($url, $this->publicBasePath);
                }
                return '<a href="' . $this->escape($url) . '">' . $label . '</a>';
            }, $part) ?? $part;

            $part = preg_replace('/(\*\*|__)(.+?)\1/s', '<strong>$2</strong>', $part) ?? $part;
            $part = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $part) ?? $part;
            $part = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $part) ?? $part;

            $output .= $part;
        }

        return $output;
    }

    private function flushParagraph(array &$html, array &$paragraph): void
    {
        if ($paragraph === []) {
            return;
        }

        $lines = [];
        foreach ($paragraph as $line) {
            $lines[] = $this->inline($line);
        }
        $html[] = '<p>' . implode('<br>', $lines) . '</p>';
        $paragraph = [];
    }

    private function ensureList(array &$html, ?string &$listType, string $type): void
    {
        if ($listType === $type) {
            return;
        }

        $this->flushList($html, $listType);
        $listType = $type;
        $html[] = '<' . $type . '>';
    }

    private function flushList(array &$html, ?string &$listType): void
    {
        if ($listType === null) {
            return;
        }

        $html[] = '</' . $listType . '>';
        $listType = null;
    }

    private function flushBlockquote(array &$html, array &$blockquote): void
    {
        if ($blockquote === []) {
            return;
        }

        $html[] = '<blockquote><p>' . $this->inline(implode(' ', $blockquote)) . '</p></blockquote>';
        $blockquote = [];
    }

    private function isTableHeader(string $headerLine, string $separatorLine): bool
    {
        $headerCells = $this->splitTableRow($headerLine);
        $separatorCells = $this->splitTableRow($separatorLine);

        if (count($headerCells) < 2 || count($headerCells) !== count($separatorCells)) {
            return false;
        }

        foreach ($separatorCells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', trim($cell)) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isTableBodyLine(string $line): bool
    {
        if (trim($line) === '') {
            return false;
        }

        return strpos($line, '|') !== false;
    }

    private function tableHtml(array $lines): string
    {
        $headers = $this->splitTableRow($lines[0]);
        $alignments = $this->tableAlignments($this->splitTableRow($lines[1]));
        $columnCount = count($headers);

        $html = [];
        $html[] = '<div class="table-scroll">';
        $html[] = '<table>';
        $html[] = '<thead>';
        $html[] = '<tr>';
        foreach ($headers as $index => $header) {
            $html[] = '<th' . $this->alignmentAttribute($alignments[$index] ?? '') . '>' . $this->inline(trim($header)) . '</th>';
        }
        $html[] = '</tr>';
        $html[] = '</thead>';
        $html[] = '<tbody>';

        for ($i = 2, $count = count($lines); $i < $count; $i++) {
            $cells = $this->normalizeTableCells($this->splitTableRow($lines[$i]), $columnCount);
            $html[] = '<tr>';
            foreach ($cells as $index => $cell) {
                $html[] = '<td' . $this->alignmentAttribute($alignments[$index] ?? '') . '>' . $this->inline(trim($cell)) . '</td>';
            }
            $html[] = '</tr>';
        }

        $html[] = '</tbody>';
        $html[] = '</table>';
        $html[] = '</div>';

        return implode("\n", $html);
    }

    private function splitTableRow(string $line): array
    {
        $line = trim($line);
        if (strpos($line, '|') === 0) {
            $line = substr($line, 1);
        }
        if (substr($line, -1) === '|') {
            $line = substr($line, 0, -1);
        }

        $cells = preg_split('/(?<!\\\\)\|/', $line);
        if ($cells === false) {
            return [];
        }

        return array_map(static function (string $cell): string {
            return str_replace('\\|', '|', $cell);
        }, $cells);
    }

    private function tableAlignments(array $separatorCells): array
    {
        $alignments = [];
        foreach ($separatorCells as $cell) {
            $cell = trim($cell);
            $left = strpos($cell, ':') === 0;
            $right = substr($cell, -1) === ':';
            if ($left && $right) {
                $alignments[] = 'center';
            } elseif ($right) {
                $alignments[] = 'right';
            } elseif ($left) {
                $alignments[] = 'left';
            } else {
                $alignments[] = '';
            }
        }

        return $alignments;
    }

    private function normalizeTableCells(array $cells, int $columnCount): array
    {
        if (count($cells) > $columnCount) {
            return array_slice($cells, 0, $columnCount);
        }

        while (count($cells) < $columnCount) {
            $cells[] = '';
        }

        return $cells;
    }

    private function alignmentAttribute(string $alignment): string
    {
        if (!in_array($alignment, ['left', 'center', 'right'], true)) {
            return '';
        }

        return ' class="align-' . $alignment . '"';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
