# Dogfood RC policy

This document records the temporary Phase 4 dogfood rule for applying unreleased runtime fixes to the Tomos official site without consuming new public beta version numbers.

## Public version vs dogfood revision

Public versions remain normal SemVer-like Tomos versions such as:

- `0.1.0-beta.1`
- `0.1.0-beta.2`
- `0.1.0-beta.3`
- `0.1.0-beta.4`

Internal official-site verification must not increment the public beta number merely to test one additional runtime fix.

The official site's displayed `VERSION` therefore stays at the current public/dogfood baseline while an unreleased runtime patch is being tested.

## Patch application boundary

A same-version package must not be published through the normal Tomos Update release path. Normal Update artifacts remain version transitions with an exact `from_version` and a newer target `version`.

For Phase 4 dogfood only, an unreleased runtime fix may be applied manually to the official site when all of the following hold:

1. the patch is already merged to the controlled development `main` branch;
2. the changed runtime file set is explicitly enumerated;
3. the patch does not require schema migration or a `VERSION` change;
4. the production signing key is not required because the normal Update path is intentionally not being exercised;
5. a backup/rollback copy of every replaced runtime file is retained until browser verification passes;
6. the live verification result is recorded before the next public release is prepared.

This is a dogfood-only maintenance operation, not a distributable Update artifact.

## Phase 4 / Issue #110 application

For the Issue #110 fix merged as `4f1caa2b39f7fc6decf8bff9f6a3afc62304b40b`, the runtime delta is limited to:

- `core/App.php`

The official site's `VERSION` remains `0.1.0-beta.4` during this dogfood verification.

Required live checks after replacing `core/App.php`:

- `/news/` renders as the virtual-folder list and contains current public News children;
- `/` renders Home News normally;
- `/feed.xml` remains current;
- a representative News article renders;
- `/about/`, `/start/`, `/docs/` render normally;
- Tomos Post and Tomos Update entry pages remain reachable.

If `/news/` still fails, restore the previous `core/App.php` and keep Gate 4 on HOLD.

## Release follow-up

Once Gate 4 passes, the next public beta release may include all accumulated unreleased runtime changes in one public version increment. RC iterations before that release should use non-public identifiers in build filenames/notes rather than consuming additional public beta numbers.
