# Tomos GitHub Pages example

This example builds a Tomos site without copying the Tomos core into the site repository. GitHub Actions checks out the Tomos version pinned in `TOMOS_VERSION` and runs the static site builder.

## Configuration

- `content/`: Markdown and public content assets
- `tomos.config.php`: site name, URL, theme, and feature settings
- `tomos-site-name.txt`: optional site title written by the setup flow
- `.github/workflows/github-pages.yml`: build and Pages deployment workflow

The default configuration derives the Pages origin and project path from `GITHUB_REPOSITORY`. For a project repository such as `owner/my-site`, it uses `https://owner.github.io` and `/my-site`. For the account site repository `owner/owner.github.io`, it uses an empty base path. `TOMOS_SITE_NAME`, `TOMOS_SITE_URL`, and `TOMOS_BASE_PATH` can override the derived values. If `tomos-site-name.txt` exists, its value is used as the site title.

## First deployment

Template generation can trigger a workflow before GitHub Pages is configured. The workflow still builds and validates the static artifact, but skips deployment until `.tomos-setup-complete` exists.

The setup flow writes the chosen site title to `tomos-site-name.txt`, configures Pages through GitHub, and commits `.tomos-setup-complete` only after Pages configuration succeeds. That commit starts the first deployment. Later pushes deploy automatically, and `workflow_dispatch` can retry a deployment after a failure.

The workflow pins `TOMOS_VERSION` to a compatible tag or commit. It does not follow `main` automatically.

## Local build

Set `GITHUB_REPOSITORY` to the intended owner and repository name so the example derives the same URL it would use in Actions:

```bash
GITHUB_REPOSITORY=owner/my-site \
TOMOS_SITE_NAME="My Tomos Site" \
TOMOS_ROOT=/path/to/tomos \
php /path/to/tomos/tools/build-static-site.php \
  --config=/path/to/tomos/examples/tomos-github/tomos.config.php \
  --tomos-root=/path/to/tomos \
  --output=/path/to/build/my-site
```

The output directory contains only static files intended for GitHub Pages.
