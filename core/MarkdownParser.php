<?php

declare(strict_types=1);

namespace Tomos;

final class MarkdownParser
{
    private bool $allowRawHtml;
    private string $publicBasePath;
    private ExternalUrlResolver $externalUrlResolver;

    public function __construct(bool $allowRawHtml = false, string $publicBasePath = '', string $cacheDir = '', ?ExternalUrlResolver $externalUrlResolver = null)
    {
        $this->allowRawHtml = $allowRawHtml;
        $this->publicBasePath = $publicBasePath;
        $this->externalUrlResolver = $externalUrlResolver ?? new ExternalUrlResolver($cacheDir);
    }

    public function toHtml(string $markdown): string
    {
        return $this->toHtmlWithToc($markdown)['html'];
    }

    /**
     * @return array{html: string, toc: string}
     */
    public function toHtmlWithToc(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $headings = [];
        $usedHeadingIds = [];
        $html = implode("\n", $this->renderBlocks(explode("\n", $markdown), $headings, $usedHeadingIds));

        return [
            'html' => $html,
            'toc' => $this->renderToc($headings),
        ];
    }

    private function renderBlocks(array $lines, array &$headings, array &$usedHeadingIds): array
    {
        $html = [];
        $paragraph = [];
        $lineCount = count($lines);
        $index = 0;

        while ($index < $lineCount) {
            $line = $lines[$index];
            $trimmed = trim($line);

            if ($trimmed === '') {
                $this->flushParagraph($html, $paragraph);
                $index++;
                continue;
            }

            if (preg_match('/^\s*```([A-Za-z0-9_-]+)?\s*$/', $line, $openingFence) === 1) {
                $this->flushParagraph($html, $paragraph);
                $language = $openingFence[1] ?? '';
                $index++;
                $codeLines = [];
                while ($index < $lineCount && preg_match('/^\s*```\s*$/', $lines[$index]) !== 1) {
                    $codeLines[] = $lines[$index];
                    $index++;
                }
                if ($index < $lineCount) {
                    $index++;
                }
                $class = $language !== '' ? ' class="language-' . $this->escape($language) . '"' : '';
                $html[] = '<pre><code' . $class . '>' . $this->escape(implode("\n", $codeLines)) . '</code></pre>';
                continue;
            }

            if ($index + 1 < $lineCount && $this->isTableHeader($line, $lines[$index + 1])) {
                $this->flushParagraph($html, $paragraph);
                $tableLines = [$line, $lines[$index + 1]];
                $index += 2;
                while ($index < $lineCount && $this->isTableBodyLine($lines[$index])) {
                    $tableLines[] = $lines[$index];
                    $index++;
                }
                $html[] = $this->tableHtml($tableLines);
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches) === 1) {
                $this->flushParagraph($html, $paragraph);
                $level = strlen($matches[1]);
                $headingHtml = $this->inline($matches[2]);
                if ($level >= 2 && $level <= 4) {
                    $headingLabel = $this->headingLabel($headingHtml, $matches[2]);
                    $headingId = $this->uniqueHeadingId($headingLabel, $usedHeadingIds);
                    $headings[] = [
                        'level' => $level,
                        'id' => $headingId,
                        'label' => $headingLabel,
                    ];
                    $html[] = '<h' . $level . ' id="' . $this->escape($headingId) . '">' . $headingHtml . '</h' . $level . '>';
                } else {
                    $html[] = '<h' . $level . '>' . $headingHtml . '</h' . $level . '>';
                }
                $index++;
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
                $this->flushParagraph($html, $paragraph);
                $html[] = '<hr>';
                $index++;
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line) === 1) {
                $this->flushParagraph($html, $paragraph);
                $quoteLines = [];
                while ($index < $lineCount && preg_match('/^>\s?(.*)$/', $lines[$index], $quoteMatch) === 1) {
                    $quoteLines[] = $quoteMatch[1];
                    $index++;
                }
                $html[] = '<blockquote><p>' . $this->inline(implode(' ', $quoteLines)) . '</p></blockquote>';
                continue;
            }

            $list = $this->listMatch($line);
            if ($list !== null) {
                $this->flushParagraph($html, $paragraph);
                $html[] = $this->renderList($lines, $index, $list['indent'], $list['type']);
                continue;
            }

            $externalUrlHtml = $this->externalUrlResolver->resolve($trimmed);
            if ($externalUrlHtml !== null) {
                $this->flushParagraph($html, $paragraph);
                $html[] = $externalUrlHtml;
                $index++;
                continue;
            }

            $paragraph[] = $trimmed;
            $index++;
        }

        $this->flushParagraph($html, $paragraph);
        return $html;
    }

    private function headingLabel(string $headingHtml, string $source): string
    {
        $label = strip_tags($headingHtml);
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', trim($label)) ?? trim($label);
        if ($label !== '') {
            return $label;
        }

        $fallback = preg_replace('/\s+/u', ' ', trim($source)) ?? trim($source);
        return $fallback !== '' ? $fallback : 'section';
    }

    private function uniqueHeadingId(string $label, array &$usedHeadingIds): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}\p{M}_-]+/u', '-', $label) ?? '';
        $slug = preg_replace('/-+/u', '-', $slug) ?? $slug;
        $slug = trim($slug, '-_');
        if ($slug === '') {
            $slug = 'section';
        }

        $candidate = $slug;
        $suffix = 2;
        while (isset($usedHeadingIds[$candidate])) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
        $usedHeadingIds[$candidate] = true;

        return $candidate;
    }

    private function renderToc(array $headings): string
    {
        if ($headings === []) {
            return '';
        }

        $root = [];
        $stack = [];
        foreach ($headings as $heading) {
            $node = [
                'id' => (string) $heading['id'],
                'label' => (string) $heading['label'],
                'children' => [],
            ];
            $level = (int) $heading['level'];

            while ($stack !== [] && $level <= (int) $stack[count($stack) - 1]['level']) {
                array_pop($stack);
            }

            if ($stack === []) {
                $root[] = $node;
                $index = count($root) - 1;
                $stack[] = ['level' => $level, 'node' => &$root[$index]];
                continue;
            }

            $parent = &$stack[count($stack) - 1]['node'];
            $parent['children'][] = $node;
            $index = count($parent['children']) - 1;
            $stack[] = ['level' => $level, 'node' => &$parent['children'][$index]];
            unset($parent);
        }

        return '<nav class="toc" aria-label="目次"><ul>' . $this->renderTocNodes($root) . '</ul></nav>';
    }

    private function renderTocNodes(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $html .= '<li><a href="#' . $this->escape((string) $node['id']) . '">' . $this->escape((string) $node['label']) . '</a>';
            if ($node['children'] !== []) {
                $html .= '<ul>' . $this->renderTocNodes($node['children']) . '</ul>';
            }
            $html .= '</li>';
        }

        return $html;
    }

    private function inline(string $text): string
    {
        $placeholders = [];
        $codeParts = preg_split('/(`[^`\n]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($codeParts === false) {
            return $this->allowRawHtml ? $text : $this->escape($text);
        }

        $output = '';
        foreach ($codeParts as $part) {
            if (strlen($part) >= 2 && $part[0] === '`' && substr($part, -1) === '`') {
                $output .= '<code>' . $this->escape(substr($part, 1, -1)) . '</code>';
                continue;
            }

            $part = preg_replace_callback('/｜([^《》\n]+)《([^《》\n]+)》/u', function (array $matches) use (&$placeholders): string {
                $token = 'TOMOSINLINE' . count($placeholders) . 'TOKEN';
                $placeholders[$token] = '<ruby>' . $this->escape($matches[1]) . '<rt>' . $this->escape($matches[2]) . '</rt></ruby>';
                return $token;
            }, $part) ?? $part;

            if (!$this->allowRawHtml) {
                $part = preg_replace_callback(
                    '/<!--.*?-->|<\/?[A-Za-z][A-Za-z0-9:-]*(?:\s[^>]*|\/?)>/s',
                    function (array $matches) use (&$placeholders): string {
                        $token = 'TOMOSINLINE' . count($placeholders) . 'TOKEN';
                        $placeholders[$token] = $this->escape($matches[0]);
                        return $token;
                    },
                    $part
                ) ?? $part;
                $part = $this->escape($part);
            }

            $part = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/', function (array $matches) use (&$placeholders): string {
                $url = Security::safeHref($matches[2]);
                if (strpos($url, '/') === 0) {
                    $url = Security::publicUrl($url, $this->publicBasePath);
                }
                $token = 'TOMOSINLINE' . count($placeholders) . 'TOKEN';
                $placeholders[$token] = '<a href="' . $this->escape($url) . '">' . $matches[1] . '</a>';
                return $token;
            }, $part) ?? $part;

            $part = preg_replace_callback('/(?:&lt;|<)(https?:\/\/[^\s>&]+)(?:&gt;|>)/i', function (array $matches) use (&$placeholders): string {
                return $this->autolinkPlaceholder($matches[1], $placeholders);
            }, $part) ?? $part;

            $part = preg_replace_callback('~(?<![A-Za-z0-9_\/"\'=])(https?://[^\s<]+)~i', function (array $matches) use (&$placeholders): string {
                return $this->autolinkPlaceholder($matches[1], $placeholders);
            }, $part) ?? $part;

            $part = preg_replace('/(\*\*\*|___)(.+?)\1/s', '<em><strong>$2</strong></em>', $part) ?? $part;
            $part = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $part) ?? $part;
            $part = preg_replace('/(\*\*|__)(.+?)\1/s', '<strong>$2</strong>', $part) ?? $part;
            $part = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $part) ?? $part;
            $part = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $part) ?? $part;

            $output .= $part;
        }

        return strtr($output, $placeholders);
    }

    private function autolinkPlaceholder(string $source, array &$placeholders): string
    {
        $source = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $trailing = '';
        while ($source !== '' && preg_match('/[.,!?;:]$/', $source) === 1) {
            $trailing = substr($source, -1) . $trailing;
            $source = substr($source, 0, -1);
        }
        $safe = Security::safeHref($source);
        if ($safe === '#') {
            return $this->escape($source . $trailing);
        }

        $token = 'TOMOSINLINE' . count($placeholders) . 'TOKEN';
        $placeholders[$token] = '<a href="' . $this->escape($safe) . '">' . $this->escape($safe) . '</a>' . $this->escape($trailing);
        return $token;
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

    private function listMatch(string $line): ?array
    {
        if (preg_match('/^(\s*)[-*+]\s+(.+)$/', $line, $matches) === 1) {
            return ['indent' => strlen($matches[1]), 'type' => 'ul', 'text' => $matches[2]];
        }
        if (preg_match('/^(\s*)\d+[.)]\s+(.+)$/', $line, $matches) === 1) {
            return ['indent' => strlen($matches[1]), 'type' => 'ol', 'text' => $matches[2]];
        }

        return null;
    }

    private function renderList(array $lines, int &$index, int $indent, string $type): string
    {
        $items = [];
        $lineCount = count($lines);
        while ($index < $lineCount) {
            $match = $this->listMatch($lines[$index]);
            if ($match === null || $match['indent'] !== $indent || $match['type'] !== $type) {
                break;
            }

            $item = '<li>' . $this->listItemInline($match['text']);
            $index++;

            while (true) {
                $lookahead = $index;
                while ($lookahead < $lineCount && trim($lines[$lookahead]) === '') {
                    $lookahead++;
                }
                $child = $lookahead < $lineCount ? $this->listMatch($lines[$lookahead]) : null;
                if ($child === null || $child['indent'] <= $indent) {
                    break;
                }

                $index = $lookahead;
                $item .= $this->renderList($lines, $index, $child['indent'], $child['type']);
            }

            $item .= '</li>';
            $items[] = $item;
        }

        return '<' . $type . '>' . implode("\n", $items) . '</' . $type . '>';
    }

    private function listItemInline(string $text): string
    {
        if (preg_match('/^\[([ xX])\]\s+(.+)$/s', $text, $matches) === 1) {
            $checked = strtolower($matches[1]) === 'x' ? ' checked' : '';
            return '<input type="checkbox" disabled' . $checked . '> ' . $this->inline($matches[2]);
        }

        return $this->inline($text);
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
        return trim($line) !== '' && strpos($line, '|') !== false;
    }

    private function tableHtml(array $lines): string
    {
        $headers = $this->splitTableRow($lines[0]);
        $alignments = $this->tableAlignments($this->splitTableRow($lines[1]));
        $columnCount = count($headers);
        $html = ['<div class="table-scroll">', '<table>', '<thead>', '<tr>'];

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

        return array_map(static fn (string $cell): string => str_replace('\\|', '|', $cell), $cells);
    }

    private function tableAlignments(array $separatorCells): array
    {
        $alignments = [];
        foreach ($separatorCells as $cell) {
            $cell = trim($cell);
            $left = strpos($cell, ':') === 0;
            $right = substr($cell, -1) === ':';
            $alignments[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
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
