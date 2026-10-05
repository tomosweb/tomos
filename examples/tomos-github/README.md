# Tomos GitHub版検証Repository

これは `tomosweb/tomos-github` 用の最小構成です。Tomos本体をこのRepositoryへ複製せず、GitHub Actionsがworkflowに固定された `TOMOS_VERSION` のTomos本体を取得してStatic Buildを実行します。

## 構成

- `content/`: Markdownと公開するcontent asset
- `tomos.config.php`: サイト名、GitHub Pages URL、Theme、機能の設定
- `.github/workflows/github-pages.yml`: Pull Requestでbuildを検証し、`main`へのpushでGitHub Pagesへdeploy

初期公開URLは `https://tomosweb.github.io/tomos-github/` です。Project Pagesのため `base_path` は `/tomos-github` に固定しています。

利用するTomos versionはworkflowの `TOMOS_VERSION` に固定しています。現在はStatic Buildを含む開発基準 `6a7466d511bf971d90c571b30050108c88ebe0af` を使用しています。Phase 5対応tagが用意されたら、tagまたはcommit SHAを明示的に変更し、Pull Requestでbuild結果を確認してください。`main` への常時追従は行いません。

このRepositoryはGitHub版の検証・将来の標準構成候補です。開発中のため、GitHub OAuth、投稿UI、Inbox、Passkey、Update UI、Workspace連携などは含みません。

## ローカルbuild

Tomos本体を別の場所へ取得したうえで、次のように実行します。

```bash
TOMOS_ROOT=/path/to/tomos php /path/to/tomos/tools/build-static-site.php \
  --config=/path/to/tomos-github/tomos.config.php \
  --tomos-root=/path/to/tomos \
  --output=/path/to/build/tomos-github
```

出力ディレクトリには、Pagesへ公開する静的ファイルだけが生成されます。
