# Tomos GitHub Pages example

This example builds a Tomos site without copying the Tomos core into the site repository. GitHub Actions checks out the Tomos version pinned in `TOMOS_VERSION` and runs the static site builder.

## Configuration

- `content/`: Markdown and public content assets
- `tomos.config.php`: site name, URL, theme, and feature settings
- `.github/workflows/github-pages.yml`: build and Pages deployment workflow

The default configuration derives the Pages origin and project path from `GITHUB_REPOSITORY`. For a project repository such as `owner/my-site`, it uses `https://owner.github.io` and `/my-site`. For the account site repository `owner/owner.github.io`, it uses an empty base path. `TOMOS_SITE_NAME`, `TOMOS_SITE_URL`, and `TOMOS_BASE_PATH` can override the derived values.

The setup flow should write the user's chosen site name to `tomos.config.php`, configure Pages with the GitHub API, and then commit `.tomos-setup-complete`. The workflow may run on the template-generated repository's initial push, but it skips deployment until that marker exists. The marker is written only after Pages configuration succeeds. A later push deploys automatically; `workflow_dispatch` can be used to retry deployment.

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
