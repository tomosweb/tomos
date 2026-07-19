# Security Policy

Tomos is designed as a lightweight Markdown publishing engine for general
shared hosting environments. It avoids requiring a database, Composer, or a
JavaScript runtime.

## Default Security Approach

- Raw HTML in Markdown is disabled by default.
- Page resolution is restricted to files under `content/`.
- Path traversal, null bytes, backslashes, unsafe URL decoding patterns, and
  non-Markdown page reads are rejected.
- Draft pages with `draft: true` are not exposed as public pages.
- Template variables are escaped by default.
- HTML output variables are restricted by a whitelist.
- Dangerous URL schemes such as `javascript:`, `data:`, and `vbscript:` are
  rejected in generated links and attributes.
- Themes must not contain PHP files.
- Theme templates are checked for dangerous PHP and script-like patterns.
- setup is disabled after completion.
- Public installations should delete or rename `setup/` after setup completes.
- `config.php` is generated per installation and is not included in
  distribution packages.
- `.htaccess` should be installed with Tomos.
- `cache/index/pages.json` is generated per installation and is not included in
  distribution packages.
- Apache installations include `.htaccess` rules to deny direct access to
  `config.php`, `config.sample.php`, cache JSON files, and PHP-like files under
  cache.

## Operational Notes

- Keep a backup of `content/`, `config.php`, and theme customizations before
  updating Tomos.
- Confirm that `content/.htaccess` prevents PHP execution under `content/` when
  using Apache-compatible hosting.
- Confirm that `cache/` is not used to execute PHP files.
- If `.htaccess` is missing, top-level pages may work while subpages, search,
  RSS, and sitemap URLs fail.
- See `docs/project/security.md` for implementation notes for routing, Markdown,
  templates, wiki links, images, setup, cache, and theme validation.

## Reporting Security Issues

Security contact is not finalized yet.

For now, please report security concerns through the project issue tracker if
available. Do not publish exploit details publicly before the maintainer has a
reasonable chance to review them.
