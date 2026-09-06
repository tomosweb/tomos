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

requireNeedle($source, 'let reservedWindow = false;', 'reserved popup state is missing');
requireNeedle($source, 'writeWindow = window.open("about:blank", "_blank");', 'popup must be reserved synchronously as about:blank');
requireNeedle($source, "if (!approved) {\n      closeReservedWindow();", 'approval rejection must close the reserved popup');
requireNeedle($source, 'writeWindow.location.href = `${WRITE_URL}#${fragment.toString()}`;', 'the reserved popup must be navigated after approval');
requireNeedle($source, "if (!writeWindow) {\n      failLaunch(", 'a blocked popup must stop the handoff');
requireNeedle($source, 'writeWindow.postMessage({', 'the existing document handoff must use the retained window handle');
requireNeedle($source, '}, WRITE_ORIGIN);', 'postMessage must retain the exact target origin');

$openPosition = strpos($source, 'writeWindow = window.open("about:blank", "_blank");');
$confirmPosition = strpos($source, 'const approved = window.confirm(');
$navigatePosition = strpos($source, 'writeWindow.location.href = `${WRITE_URL}#${fragment.toString()}`;');
if ($openPosition === false || $confirmPosition === false || $navigatePosition === false || !($openPosition < $confirmPosition && $confirmPosition < $navigatePosition)) {
    throw new RuntimeException('popup reservation and post-approval navigation order is invalid');
}

if (strpos($source, 'window.open(`${WRITE_URL}') !== false) {
    throw new RuntimeException('handoff must not open the final URL after the approval boundary');
}

echo "write_handoff_popup_activation_check: synchronous reservation, approval cleanup, retained window, and exact-origin handoff checks passed\n";
