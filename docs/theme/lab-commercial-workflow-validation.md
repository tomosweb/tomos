# Tomos Lab Commercial Workflow Validation

Status: Phase 6.6 validation evidence  
Date: 2026-08-23

## Scope

This document records the Phase 6.6 commercial production workflow validation for the actual bundled `tomos-lab` theme.

The purpose is to verify that the generic Theme Package deployment implemented in Phase 6.2 supports the repeated deployment workflow required by a real commercial theme production process.

## Automated validation

`tests/lab_theme_commercial_workflow_check.php` uses the actual `themes/tomos-lab` directory as the package source rather than a synthetic theme fixture.

The test creates a temporary Tomos site with:

- Tomos `VERSION` 0.3.0,
- `config.php` selecting `tomos-lab`,
- site-specific `theme-settings.php`,
- site-specific `theme-assets/logo.svg`,
- site-specific `theme-assets/hero.jpg`,
- and `content/index.md`.

It then validates the following production sequence.

### 1. Initial commercial theme installation

The current `tomos-lab` package is ZIP-deployed through `ThemePackageDeployment` and must install as theme version `1.0.0`.

### 2. Same-version production iteration

A production revision is made while keeping theme version `1.0.0`.

The deployment must:

- report an update,
- report version relation `same`,
- replace the modified theme CSS,
- remove an obsolete theme-only file from the previous deployment,
- and leave site-specific resources untouched.

This models repeated ZIP uploads by production staff while HTML/CSS is being adjusted.

### 3. Released theme upgrade

The test then creates a package revision with theme version `1.0.1`.

The deployment must:

- report version relation `newer`,
- keep `config.php` selecting `tomos-lab`,
- and leave all site-specific files byte-identical.

This models a post-launch commercial theme maintenance release.

### 4. Package isolation

The installed theme package must not contain:

- `theme-settings.php`,
- `theme-assets/`,
- `content/`,
- or `config.php`.

Those resources remain site-specific and outside theme replacement.

### 5. Theme code boundary

The deployed commercial theme must contain no PHP files.

## First CI execution evidence

On the first PR execution, the new Phase 6.6 check completed successfully:

```text
lab_theme_commercial_workflow_check: 5 checks passed
```

The same workflow execution later encountered two unrelated existing CI timing-sensitive failures:

1. an existing hidden-staging failure-injection test accepted a different validation stage than its narrow expected-stage list;
2. the installer dry-run ZIP verifier reported `trash/.gitkeep` missing while the same distribution builder independently passed in Core regression, with the failing log also showing a `printf` broken pipe.

Neither failure occurred inside `lab_theme_commercial_workflow_check.php`, and the commercial workflow test itself completed successfully before the existing regression failure.

A clean complete CI run is still required before the Phase 6.6 PR is merged.

## Failure-safety boundary

Phase 6.2 already verifies rollback for normal runtime failures during Theme Package deployment. Phase 6.6 relies on that generic transaction behavior and does not duplicate the synthetic rollback injection tests.

A hard process crash or power loss between filesystem rename stages is a separate recovery boundary. Automatic startup recovery for that hard-crash window is not currently claimed by this validation.

Gate 6 must therefore distinguish:

- normal runtime exception rollback: implemented and tested;
- hard process termination between rename stages: not automatically recovered by the current implementation.

## Phase 6.6 pass criterion

Phase 6.6 is complete when the commercial workflow test passes in a complete green CI run and the PR is merged.
