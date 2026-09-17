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

requireNeedle($source, 'if (returnSession && document.getElementById("markdown_file")) {', 'return receiver must require a valid upload page');
requireNeedle($source, 'let returnSource = opener && !opener.closed ? opener : null;', 'return source binding is missing');
requireNeedle($source, 'window.addEventListener("message", handleReturnMessage);', 'top-level receiver must listen for the Write probe');
requireNeedle($source, 'if (message.type === "write:return-probe") {', 'return receiver must answer the ready probe');
requireNeedle($source, 'if (!returnSource) returnSource = event.source;', 'top-level receiver must bind the first valid Write source');
requireNeedle($source, 'if (event.source !== returnSource) return;', 'return receiver must reject a different source window');
requireNeedle($source, 'event.origin !== WRITE_ORIGIN', 'return receiver must validate the exact Write origin');
requireNeedle($source, 'message.protocol !== PROTOCOL || message.session !== returnSession', 'return receiver must validate protocol and session');
requireNeedle($source, 'receiveReturnDocument(message, event.source, returnSession);', 'return receiver must pass the validated source to document handling');
requireNeedle($source, 'type: "tomos:return-ready",', 'return receiver must acknowledge readiness');

$listenerPosition = strpos($source, 'window.addEventListener("message", handleReturnMessage);');
$proactiveReadyPosition = strpos($source, 'returnSource.postMessage({', $listenerPosition ?: 0);
if ($listenerPosition === false || $proactiveReadyPosition === false || $listenerPosition > $proactiveReadyPosition) {
    throw new RuntimeException('return receiver must install validation before sending the proactive ready message');
}

echo "write_handoff_return_receiver_check: top-level upload receiver probe, source binding, and document handoff checks passed\n";
