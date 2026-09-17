<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/post/index.php');
if (!is_string($source)) {
    throw new RuntimeException('post/index.php could not be read');
}

function requireNeedle(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

requireNeedle($source, 'const folder = extractFolder(markdown);', 'upload form must parse Markdown folder');
requireNeedle($source, 'folderInput.value = normalizeFolder(folder);', 'parsed Markdown folder must update the form');
requireNeedle($source, "editableReupload\n        ? \"Markdown内で変更された保存先を反映しました。", 'editable reupload must report a changed Markdown folder');

$sourceMetadataPosition = strpos($source, 'const sourceMetadata = extractSourceMetadata(markdown);');
$folderParsePosition = strpos($source, 'const folder = extractFolder(markdown);');
$folderApplyPosition = strpos($source, 'folderInput.value = normalizeFolder(folder);');
if ($sourceMetadataPosition === false || $folderParsePosition === false || $folderApplyPosition === false) {
    throw new RuntimeException('required editable folder handoff steps are missing');
}
if (!($sourceMetadataPosition < $folderParsePosition && $folderParsePosition < $folderApplyPosition)) {
    throw new RuntimeException('editable source metadata must be processed before Markdown folder parsing and application');
}

echo "post_folder_handoff_check: editable Markdown folder overrides source folder\n";
