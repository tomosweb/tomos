# Known Limitations

- Tomos Postの画像は最大5点、1点10MBまでです。公開先の受信条件に合わせて分割送信しますが、WebサーバーやプロキシなどPHPから把握できない制限で送信が失敗する場合はあります。その場合も未完了のMarkdownは公開しません。

Tomos is currently pre-release software. It is usable for small
Markdown-based publishing, but it is not a full CMS or general-purpose file
manager.

## Design Assumptions

- Tomos is a lightweight Markdown publishing engine that does not use a
  database.
- Markdown files under `content/` are the source of truth.
- Direct browser-based freeform Markdown editing is not the primary workflow;
  use Tomos Write, local files, FTP, or another file management workflow when
  editing source Markdown directly.
- Tomos Post provides management screens for authentication, posting, drafts,
  published content, settings, security, and installed themes, but it is not a
  general-purpose file manager or full CMS.

## Current Limitations

- Tomos Post does not provide a general media library or image manager.
- Tomos Post does not provide individual trash restore, individual trash
  deletion, overwrite, or complete content history management.
- Tomos Post rate limiting is a lightweight deterrent. It is not a complete
  bot protection system or a replacement for server-side access controls or
  WAFs.
- Browser-based theme editing and CSS editing are not implemented.
- Theme validation checks risky patterns, but it is not a complete HTML
  sanitizer or sandbox for arbitrary third-party themes.
- Automatic update application, background updates, and multi-hop automatic
  updates are not performed. Online update information is checked only after
  the user opens Tomos Update, ZIP download requires an explicit confirmation
  action, and apply requires a separate explicit confirmation. Updates advance
  one version at a time.
- The signed manual Update ZIP route remains available as a formal update
  route.
- Detailed Nginx configuration is not documented yet; Apache-compatible
  hosting with `.htaccess` and `mod_rewrite` is the primary target.
- A strict production CSP policy for arbitrary third-party themes is not
  finalized yet.
- Advanced search, search highlighting, and search suggestions are not
  implemented.
- Per-page OGP image selection is not implemented.
- HEIC/HEIF image upload is not implemented.
- External Markdown Inbox accepts Markdown text but does not transfer
  referenced images.
- External Inbox API requires HTTPS and a separately issued posting token.
  `draft: true` entries remain in the Inbox for manual publication.

## HTML Cache Scope

- HTML cache stores only body HTML for normal Markdown pages.
- Search results, tag pages, RSS, sitemap, setup, and 404 pages are not cached
  by the HTML cache.
- To keep normal page views lightweight, Tomos does not scan the whole
  `content/` tree on every request when metadata cache files exist.
- When files are added by FTP or another external file manager, index cache
  regeneration may be needed before lists, search, tags, and Wiki link aliases
  reflect the change.
- If a theme change requires body HTML regeneration, delete `cache/html/`
  generated `.html` and `.json` files.

## Environment Notes

- PHP 7.4 or later is expected.
- Apache-compatible hosting with `.htaccess` and `mod_rewrite` is the primary
  target.
- If the local environment does not provide the `php` command, local PHP
  syntax checks cannot be run there.
