<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/post/assets/write-handoff.js');
if (!is_string($source)) {
    throw new RuntimeException('write-handoff.js could not be read');
}

function requireNeedle(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

requireNeedle($source, 'const approved = window.confirm(', 'approval must precede the outbound popup');
requireNeedle($source, 'if (!approved) return;', 'approval rejection must stop before opening Tomos Write');
requireNeedle($source, 'writeWindow = window.open(`${WRITE_URL}#${fragment.toString()}`, "_blank");', 'the approved final Tomos Write URL must be opened directly');
requireNeedle($source, "if (!writeWindow) {\n      failLaunch(", 'a blocked popup must stop the handoff');
requireNeedle($source, 'writeWindow.postMessage({', 'the document handoff must use the opened window handle');
requireNeedle($source, '}, WRITE_ORIGIN);', 'postMessage must retain the exact target origin');

$confirmPosition = strpos($source, 'const approved = window.confirm(');
$openPosition = strpos($source, 'writeWindow = window.open(`${WRITE_URL}#${fragment.toString()}`, "_blank");');
if ($confirmPosition === false || $openPosition === false || !($confirmPosition < $openPosition)) {
    throw new RuntimeException('approval must occur before opening the final Tomos Write URL');
}

if (strpos($source, 'window.open("about:blank", "_blank")') !== false) {
    throw new RuntimeException('outbound handoff must not reserve an about:blank popup');
}

if (strpos($source, 'reservedWindow') !== false) {
    throw new RuntimeException('outbound handoff must not retain reserved popup state');
}

if (strpos($source, 'writeWindow.location.href = `${WRITE_URL}#${fragment.toString()}`;') !== false) {
    throw new RuntimeException('outbound handoff must not navigate a reserved popup after approval');
}

echo "write_handoff_popup_activation_check: approval-first direct final-URL popup and exact-origin handoff checks passed\n";
