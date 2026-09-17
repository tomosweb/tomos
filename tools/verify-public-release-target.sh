#!/usr/bin/env bash

set -euo pipefail

die() {
  printf 'error: %s\n' "$1" >&2
  exit 1
}

tag="${1:-}"
repository="${TOMOS_PUBLIC_RELEASE_REPOSITORY:-tomosweb/tomos}"

[[ "$repository" == "tomosweb/tomos" ]] || die "public release repository must be tomosweb/tomos"
command -v gh >/dev/null 2>&1 || die "gh is required"
command -v jq >/dev/null 2>&1 || die "jq is required"

visibility=$(gh repo view "$repository" --json visibility --jq '.visibility')
[[ "$visibility" == "PUBLIC" ]] || die "release repository is not public: $repository"

if [[ -n "$tag" ]]; then
  [[ "$tag" =~ ^v[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$ ]] || die "release tag is unsafe: $tag"
  metadata=$(gh release view "$tag" --repo "$repository" --json tagName,isDraft,isPrerelease,url)
  jq -e --arg tag "$tag" --arg expected "https://github.com/${repository}/releases/tag/${tag}" '
    .tagName == $tag
    and .isDraft == false
    and .isPrerelease == false
    and .url == $expected
  ' <<< "$metadata" >/dev/null || die "public release is not a published stable release: $tag"
fi

printf 'Public release target verified: %s\n' "$repository"
if [[ -n "$tag" ]]; then
  printf 'Public release verified: %s\n' "$tag"
fi
