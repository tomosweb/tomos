# Known Limitations

- Tomos Postの画像は最大5点、1点10MBまでです。公開先の受信条件に合わせて分割送信しますが、WebサーバーやプロキシなどPHPから把握できない制限で送信が失敗する場合はあります。その場合も未完了のMarkdownは公開しません。

Tomos v0.1.0-alpha.2 is an alpha release candidate. It is usable for small
Markdown-based publishing, but several features are intentionally not included
yet.

## Design Assumptions

- Tomos is a lightweight Markdown publishing engine that does not use a
  database.
- Tomos is not a full CMS.
- Markdown files under `content/` are the source of truth.
- Editing is currently expected to happen through Tomos Write, local files, FTP,
  or another file management workflow.
- Web-based editing and full administration are not part of this alpha candidate.

## Not Implemented Yet

- Web-based Markdown editing is not implemented.
- Tomos Post is a minimal management entry point. It can post Markdown files,
  move posted Markdown files to `trash/`, clear `trash/`, and switch among
  installed themes, but it is not a browser-based editor or file manager.
- Tomos Write is an external Markdown writing tool. It does not save directly
  into Tomos; use Tomos Post to post the saved `.md` file.
- Tomos Post can upload images referenced by Tomos Write Markdown, but it is
  not a general media library or image manager.
- Tomos Post does not provide posted-file lists, individual trash restore,
  individual trash deletion, overwrite, or history management.
- Tomos Post rate limiting is a lightweight deterrent. It is not a complete bot
  protection system or a replacement for server-side access controls or WAFs.
- Login is not implemented.
- Admin screens are not implemented.
- Theme editing in the browser is not implemented.
- Theme upload is not implemented.
- Browser-based CSS editing is not implemented.
- Post-install theme switching is available inside Tomos Post, but adding
  themes still requires FTP or a server file manager to place files under
  `themes/`.
- AI-oriented theme creation prompts are available as an initial draft, but are not finalized yet.
- Additional sample themes `tomos-journal` and `tomos-dark` are bundled. More
  themes may be added in future work.
- Automatic updates are not implemented.
- Detailed Nginx configuration is not documented yet.
- A strict production CSP policy for arbitrary third-party themes is not
  finalized yet.
- Theme validation checks risky patterns, but it is not a complete HTML
  sanitizer or sandbox.
- Advanced search, search highlighting, and search suggestions are not
  implemented.
- Per-page OGP image selection is not implemented.
- HEIC/HEIF image upload is not implemented.
- External Markdown Inbox accepts Markdown text but does not transfer referenced images.
- External Inbox API requires HTTPS and a separately issued posting token. `draft: true` entries remain in the Inbox for manual publication.

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
- If the local environment does not provide the `php` command, local PHP syntax
  checks cannot be run there.
