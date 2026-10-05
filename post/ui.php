<?php

declare(strict_types=1);

/** Render a small, single-colour Tomos Post line icon from the local icon set. */
function tomosPostIcon(string $name, string $class = 'ui-icon'): string
{
    $icons = [
        'upload' => '<path d="M10 13V3m0 0L6.5 6.5M10 3l3.5 3.5M4 12v4a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-4"/>',
        'draft' => '<path d="M11.5 3H5a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V7.5z"/><path d="M11 3v5h5M7 12h6M7 15h4"/>',
        'globe' => '<circle cx="10" cy="10" r="7.5"/><path d="M2.8 10h14.4M10 2.5c2 2 3 4.5 3 7.5s-1 5.5-3 7.5c-2-2-3-4.5-3-7.5s1-5.5 3-7.5"/>',
        'settings' => '<circle cx="10" cy="10" r="2.7"/><path d="M8.1 2.8h3.8l.5 2a5.7 5.7 0 0 1 1.2.7l1.9-.7 1.9 3.3-1.5 1.4v1.1l1.5 1.4-1.9 3.3-1.9-.7a5.7 5.7 0 0 1-1.2.7l-.5 2H8.1l-.5-2a5.7 5.7 0 0 1-1.2-.7l-1.9.7-1.9-3.3 1.5-1.4v-1.1L2.6 8.1l1.9-3.3 1.9.7a5.7 5.7 0 0 1 1.2-.7z"/>',
        'site' => '<path d="M2.5 17.5h15M4 17V7l6-4 6 4v10M7 17v-5h6v5M7 8h.01M10 8h.01M13 8h.01"/>',
        'navigation' => '<path d="M3 5h14M3 10h14M3 15h14"/><circle cx="6" cy="5" r="1"/><circle cx="13" cy="10" r="1"/><circle cx="8" cy="15" r="1"/>',
        'theme' => '<path d="M10 2.5a7.5 7.5 0 1 0 0 15h1.1a1.8 1.8 0 0 0 1.2-3.1 1.4 1.4 0 0 1 1-2.4h1.3A3.9 3.9 0 0 0 18.5 8c0-3-3.8-5.5-8.5-5.5z"/><path d="M6.5 8h.01M9.5 5.8h.01M13 6.2h.01"/>',
        'bluesky' => '<path d="M10 9.1C8.9 6.9 6 3.5 3.3 2.8 1.9 2.4 1.4 3.2 1.6 4.5c.4 2.6 2.6 4.5 5.5 5.8-2.9-.3-5.2.7-4.7 3.1.5 2.3 3.2 3.2 7.6-.2 4.4 3.4 7.1 2.5 7.6.2.5-2.4-1.8-3.4-4.7-3.1 2.9-1.3 5.1-3.2 5.5-5.8.2-1.3-.3-2.1-1.7-1.7C14 3.5 11.1 6.9 10 9.1z"/>',
        'analytics' => '<path d="M3 16.5V10M7.7 16.5V5M12.3 16.5v-4M17 16.5V7"/><path d="M2.5 17.5h15"/>',
        'security' => '<path d="M10 2.5 16 5v4.8c0 3.5-2.4 6-6 7.7-3.6-1.7-6-4.2-6-7.7V5z"/><path d="m7.3 9.8 1.8 1.8 3.7-3.8"/>',
        'update' => '<path d="M16.2 7.5A6.8 6.8 0 0 0 4.7 5.3L3.2 7M3.2 3.5V7h3.5M3.8 12.5a6.8 6.8 0 0 0 11.5 2.2l1.5-1.7M16.8 16.5V13h-3.5"/>',
        'image' => '<rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/><circle cx="7" cy="8" r="1.3"/><path d="m3.5 14 4-3.5 2.5 2 2.5-3 4 4.5"/>',
        'rss' => '<path d="M3 3.5a13.5 13.5 0 0 1 13.5 13.5M3 8.5A8.5 8.5 0 0 1 11.5 17M3.5 16.5h.01"/>',
        'sitemap' => '<rect x="8" y="2.5" width="4" height="3.5" rx=".7"/><rect x="2.5" y="14" width="4" height="3.5" rx=".7"/><rect x="13.5" y="14" width="4" height="3.5" rx=".7"/><path d="M10 6v4M4.5 14v-4h11v4"/>',
        'edit' => '<path d="m12.5 4.5 3 3M3 17l3.7-.8L16.8 6a1.6 1.6 0 0 0-2.3-2.3L4.4 13.8z"/>',
        'preview' => '<path d="M1.8 10s3-5 8.2-5 8.2 5 8.2 5-3 5-8.2 5-8.2-5-8.2-5z"/><circle cx="10" cy="10" r="2"/>',
        'download' => '<path d="M10 3v9m0 0 3.5-3.5M10 12 6.5 8.5M4 13v3a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-3"/>',
        'trash' => '<path d="M3.5 5.5h13M8 5.5V3.5h4v2M5 5.5l.7 11h8.6l.7-11M8 8.5v5M12 8.5v5"/>',
        'withdraw' => '<path d="M3 10h13M11 5l5 5-5 5M3 4v12"/>',
        'external' => '<path d="M11 3h6v6M17 3l-8 8"/><path d="M15 11v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        'back' => '<path d="m12.5 4.5-5.5 5.5 5.5 5.5M7.5 10h10"/>',
        'arrow-up' => '<path d="m4.5 11 5.5-5.5 5.5 5.5M10 5.5v9"/>',
        'arrow-down' => '<path d="m4.5 9 5.5 5.5L15.5 9M10 14.5v-9"/>',
        'chevron' => '<path d="m7 4 6 6-6 6"/>',
        'more' => '<circle cx="4" cy="10" r=".8"/><circle cx="10" cy="10" r=".8"/><circle cx="16" cy="10" r=".8"/>',
        'check' => '<path d="m3.5 10.5 4.2 4.2 8.8-9"/>',
        'warning' => '<path d="M10 2.5 18 17H2z"/><path d="M10 7v4.5M10 14.5v.01"/>',
        'home' => '<path d="m2.5 9 7.5-6 7.5 6M4.5 8v9h11V8M8 17v-5h4v5"/>',
        'about' => '<circle cx="10" cy="10" r="7.5"/><path d="M10 9v4M10 6.5v.01"/>',
    ];

    if (!isset($icons[$name])) {
        return '';
    }

    $class = preg_replace('/[^A-Za-z0-9 _-]/', '', $class) ?? 'ui-icon';
    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $icons[$name]
        . '</svg>';
}

/** Render the quiet, shared return link used at the foot of Post screens. */
function tomosPostReturnLink(string $url, string $label = 'Tomos Postへ戻る'): string
{
    return '<footer class="post-return"><a class="back-link" href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . tomosPostIcon('back') . '<span>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span></a></footer>';
}
