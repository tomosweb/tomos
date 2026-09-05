from pathlib import Path

path = Path("post/index.php")
text = path.read_text(encoding="utf-8")

button_needle = """        echo '<button class=\"secondary\" type=\"submit\">Markdownを取得</button>';
        echo '</form>';
        if (empty($item['protected'])) {
"""
button_replacement = """        echo '<button class=\"secondary\" type=\"submit\">Markdownを取得</button>';
        echo '</form>';
        $handoffReturnUrl = Tomos\\Security::publicUrl('/post/?section=upload', $publicBasePath);
        echo '<button class=\"secondary tomos-write-edit\" type=\"button\" data-return-url=\"' . e($handoffReturnUrl) . '\">Tomos Writeで編集</button>';
        if (empty($item['protected'])) {
"""

script_needle = """HTML;
    echo '</main></body></html>';
}

function renderSectionNav"""
script_replacement = """HTML;
    echo '<script src=\"' . e(Tomos\\Security::publicUrl('/post/assets/write-handoff.js', $publicBasePath)) . '\" defer></script>';
    echo '</main></body></html>';
}

function renderSectionNav"""

if text.count(button_needle) != 1:
    raise SystemExit(f"published button insertion point count was {text.count(button_needle)}, expected 1")
if text.count(script_needle) != 1:
    raise SystemExit(f"script insertion point count was {text.count(script_needle)}, expected 1")

text = text.replace(button_needle, button_replacement, 1)
text = text.replace(script_needle, script_replacement, 1)
path.write_text(text, encoding="utf-8")
