# Theme Platform Gate 6 Validation

Status: PASS  
Date: 2026-08-23  
Scope: Phase 6 research-lab commercial theme and commercial Theme Package workflow

## 1. Conclusion

Gate 6 passes.

Phase 6 demonstrated that a commercially usable research-lab website theme can be built and maintained on the generic Tomos Theme Platform without adding research-lab-specific Core APIs, content types, databases, or administration screens.

The commercial validation now covers both production-staff iteration and ordinary-user browser updates of the same theme ID.

This Gate does not assign the next Tomos Core version and does not start a release. Release scope and version selection follow Gate 6 as a separate decision.

## 2. Phase 6 evidence

### 6.2 Generic Theme Package update

`ThemePackageDeployment` provides the generic same-ID Theme ZIP deployment prerequisite used by the commercial workflow.

Validated behavior includes:

- initial install,
- whole-directory replacement,
- same-version reinstall,
- newer-version update,
- downgrade reporting,
- active-theme replacement without changing selection,
- site-specific resource preservation,
- deploy locking,
- normal runtime failure rollback,
- and cleanup of candidate/backup artifacts on successful rollback paths.

### 6.3 Commercial operating model

`docs/theme/lab-commercial-model-v1.md` fixes the commercial responsibilities, site map, production flow, post-launch flow, multilingual boundary, and Gate 6 conditions.

### 6.4 Requirement mapping

`docs/theme/lab-requirement-mapping-v1.md` found no need for a research-specific Core API, content type, or administration UI.

Research, Members, and Publications remain Markdown-first for v1. Structured Home collection data remains News-only until independent commercial evidence demonstrates a generic collection requirement.

### 6.5 Commercial theme

`themes/tomos-lab` provides the first commercial validation theme.

It uses:

- ThemeSettings for Hero, logo, key color, and News configuration,
- Markdown for Home body, Research, Members, Publications, News, About, and Access/Contact,
- `nav.primary_items` for generic section navigation,
- the existing Home News API,
- and `page.language` for page language output.

The theme contains no PHP and does not duplicate publication, Markdown parsing, URL resolution, or page discovery logic.

### 6.6 Production-staff repeated deployment

`tests/lab_theme_commercial_workflow_check.php` uses the real `themes/tomos-lab` package and validates:

- initial install,
- same-version production revision,
- whole-directory replacement,
- obsolete theme-file removal,
- released-version update,
- active-theme selection preservation,
- byte-identical `theme-settings.php`, `theme-assets/`, `content/`, and `config.php`,
- and the theme PHP prohibition.

The completed CI run passed Core regression, Installer release dry-run, and PHP compatibility checks.

### 6.7 Ordinary-user browser update

`tests/lab_theme_browser_update_check.php` validates the public operator path through Tomos Post:

1. authenticate to Tomos Post;
2. start with `tomos-lab` 1.0.0 active;
3. upload a `tomos-lab` 1.0.1 ZIP through `/post/theme/add/`;
4. see current and incoming versions before confirmation;
5. confirm the update through `/post/theme/add/confirm/`;
6. receive the browser update result;
7. keep `tomos-lab` selected;
8. preserve site-specific resources byte-for-byte;
9. and render the public site after the update.

The completed CI run passed Core regression, Installer release dry-run, and PHP 7.4/8.0/8.2 compatibility checks.

## 3. Gate 6 conditions

### 1. No research-lab-specific Core feature is required — PASS

The requirement mapping and final implementation require no research-specific Core API, content type, database, or administration UI.

### 2. Commercially meaningful visual differentiation — PASS

`tomos-lab` has a dedicated responsive Home layout, split Hero, section-card navigation, structured News presentation, research-site typography, content-page layout, and commercial site header/footer rather than merely recoloring an existing standard theme.

Final project-specific visual acceptance remains normal production QA for each client site; it is not a new Core requirement.

### 3. Site-specific Hero/logo/key color are separated from Theme ZIP — PASS

The theme consumes existing `theme.*` context. Site-specific settings and assets remain outside the package.

### 4. Research/Members/Publications operate as Markdown — PASS

No dedicated content types were introduced. The theme relies on normal pages/folders and existing navigation/list rendering.

### 5. News Home/list share one Markdown source — PASS

The theme uses the existing generic Home News API, which follows the same indexed published pages used by normal News pages.

### 6. Production staff can repeatedly deploy the same theme ID — PASS

Phase 6.6 validates same-version production revision with the actual `tomos-lab` package.

### 7. Ordinary users can deploy a later commercial theme update in the browser — PASS

Phase 6.7 validates the complete Tomos Post upload/inspection/confirmation/result flow.

### 8. Active-theme replacement preserves theme selection — PASS

Both Phase 6.6 and 6.7 verify that `config.php` continues selecting `tomos-lab`.

### 9. Site-specific resources survive repeated theme deployment — PASS

`theme-settings.php`, `theme-assets/`, `content/`, and `config.php` remain byte-identical in the commercial workflow tests.

### 10. Existing standard themes regress cleanly — PASS

Core regression remains green after adding `tomos-lab` and the commercial workflow tests.

### 11. Tomos Update compatibility with custom theme/site resources — PASS with existing Update boundary

Phase 6 does not change the established Tomos Update protection boundary. The commercial theme is a user/custom theme package and site-specific settings/assets/content remain outside the Core distribution replacement boundary. Installer release dry-run and the full regression suite remain green with `tomos-lab` present.

No claim is made that a Theme ZIP update and a Tomos Core Update are one transaction; they remain separate operations by design.

### 12. Desktop/tablet/mobile layout quality — PASS at Theme implementation level

The theme provides explicit desktop, tablet, and mobile responsive layouts, including Hero stacking, mobile navigation, card-grid adaptation, News-list adaptation, responsive content width, and horizontally safe long tables.

Visual acceptance with final laboratory images/content remains part of normal client production QA. Phase 6 did not add a browser screenshot approval system to Core.

### 13. Japanese and English pages use `page.language` — PASS

The theme sets `<html lang="{{ page.language }}">` and contains no URL-based language inference or research-specific translation logic.

### 14. Creator can build without Core PHP changes — PASS

The entire commercial theme is HTML/CSS/metadata/README. No Core PHP modification was required for the site design.

### 15. Ordinary laboratory operation stays Tomos Post/Markdown-centered — PASS

The commercial model places day-to-day News and ordinary page editing in Markdown/Tomos Post. ThemeSettings remain a low-frequency production/maintenance concern.

### 16. Theme does not duplicate Core content/publication/URL logic — PASS

The theme uses only documented template context and does not parse Markdown, inspect `pages.json`, determine draft/publication state, or construct Tomos routing rules.

### 17. Commercial setup/update procedure is reproducible — PASS

The theme README, commercial model, repeated-deployment test, browser-update test, and this Gate evidence document define a reproducible install/production/update path.

### 18. Responsibilities are explainable without lab-specific exceptions — PASS

The final boundary remains:

- Core: publication, indexing, URLs, navigation, security, template context, generic Theme Package deployment;
- Theme: HTML/CSS/static presentation;
- ThemeSettings/theme-assets: low-frequency site-specific presentation values/assets;
- Markdown: site content maintained by operators.

No research-lab exception is required.

## 4. Explicit reliability boundary

Gate 6 validates normal runtime failure rollback for Theme Package deployment.

It does **not** claim automatic recovery from hard process termination or power loss in the narrow filesystem window between renaming the existing theme to a backup and placing the new candidate theme.

Current distinction:

- validation/package/runtime exception while the process remains alive: rollback implemented and tested;
- process death/power loss between filesystem rename stages: no automatic startup transaction recovery is currently implemented.

This limitation does not block Gate 6 because the commercial requirement established in Phase 6 is safe browser deployment with normal validation/failure rollback, not filesystem-level crash consistency across process death. If field evidence later shows this hard-crash window needs automatic recovery, it should be handled as a generic Theme Package deployment reliability improvement, not a research-lab feature.

## 5. Gate 6 result

```text
Phase 6.2 Theme Package update              PASS / merged / unreleased
Phase 6.3 commercial operating model        PASS / merged
Phase 6.4 requirement mapping               PASS / merged
Phase 6.5 tomos-lab commercial theme        PASS / merged
Phase 6.6 repeated production deployment    PASS / merged
Phase 6.7 ordinary-user browser update      PASS / merged
Gate 6                                      PASS
```

The next activity is release-scope/version planning for the completed Phase 6 work, followed separately by Phase 7 Theme distribution foundation according to the roadmap.
