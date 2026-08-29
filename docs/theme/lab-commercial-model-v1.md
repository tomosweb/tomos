# Research Lab Commercial Theme Model v1

Status: Phase 6 commercial validation baseline  
Scope: Tomos Theme Platform / research lab commercial theme  
Date: 2026-08-23

## 1. Purpose

This document fixes the commercial operating model for the first research-lab theme built on Tomos Theme Platform.

The purpose is not to add research-lab-specific CMS features. The purpose is to verify that an actual commercial lab website can be designed, delivered, revised, and operated by combining:

- a reusable commercial Theme ZIP,
- site-specific ThemeSettings and `theme-assets/`,
- ordinary Markdown content,
- the existing Home News API,
- the existing multilingual foundation,
- and repeated Theme ZIP deployment through Tomos Post.

A requirement becomes a candidate for Tomos core only when it is reusable beyond research-lab sites and cannot be safely solved by Theme, ThemeSettings, or Markdown.

## 2. Commercial assumptions

The first lab theme is treated as a real production product, not as a demo-only theme.

Commercial production assumes all of the following:

1. A creator prepares an initial site for a specific laboratory.
2. Site-specific logo, Hero image, Hero copy, key color, News settings, and similar low-frequency settings are separated from the Theme ZIP.
3. During production, staff repeatedly revise HTML/CSS/assets and upload the same theme ID as a ZIP through Tomos Post.
4. Same-version re-upload is allowed during production iteration.
5. A later commercial theme release may increment the theme version and be deployed through the same ZIP flow.
6. Replacing the Theme ZIP must not overwrite `theme-settings.php`, `theme-assets/`, `content/`, or `config.php`.
7. The laboratory's ordinary post-launch work remains Markdown-centered and does not require editing theme settings.
8. A currently active theme may be updated in place while remaining selected.

## 3. Reference site map

The v1 commercial lab site uses the following reference structure.

```text
Home
Research
Members
Publications
News
About
Access / Contact
```

A concrete content layout may be:

```text
content/
├── index.md
├── research/
│   ├── index.md
│   ├── project-a.md
│   └── project-b.md
├── members/
│   └── index.md
├── publications/
│   └── index.md
├── news/
│   ├── 2026-08-01-paper.md
│   └── 2026-08-15-event.md
├── about.md
└── contact.md
```

This is a validation baseline rather than a new Tomos content type contract. Projects, member profiles, and publications remain ordinary Markdown unless commercial validation demonstrates a generic platform-level need.

## 4. Home page model

The reference Home page is:

```text
Header
Hero
Laboratory introduction / index.md body
Research section or link
News
Members section or link
Publications section or link
Footer
```

### 4.1 Theme responsibilities

The theme owns:

- HTML structure,
- responsive layout,
- visual hierarchy,
- typography,
- card/list presentation,
- navigation presentation,
- Hero presentation,
- News presentation,
- Research/Members/Publications teaser presentation when it can be produced from existing template context and links,
- and all CSS/static theme assets shipped in the package.

The theme must not parse Markdown, inspect `pages.json`, duplicate publication rules, infer draft state, or implement URL-generation rules.

### 4.2 ThemeSettings responsibilities

Low-frequency per-site values belong in `theme-settings.php` and `theme-assets/`, including where applicable:

- Hero enabled state,
- Hero image,
- Hero title,
- Hero subtitle,
- Hero button label and URL,
- News enabled/path/limit/heading/more-label,
- site-specific logo,
- key color,
- virtual-folder display titles.

These settings are initial-production or occasional-maintenance data, not the laboratory's daily content workflow.

### 4.3 Markdown responsibilities

Markdown remains the source of truth for content that the laboratory is expected to maintain, including:

- Home body copy in `content/index.md`,
- Research,
- Members,
- Publications,
- News,
- About,
- Access / Contact,
- and Japanese/English content pages.

## 5. News model

News uses the existing generic Home News API.

The commercial theme may render:

- `home.has_news`,
- `home.news_items`,
- `home.news_url`,
- and existing `theme.news_*` settings.

The core continues to own public-page selection, ordering, draft exclusion, URL resolution, and limit application. The theme owns only presentation.

No research-specific News API is introduced.

## 6. Research, Members, and Publications

For v1, Research, Members, and Publications are ordinary Markdown pages/folders.

The following are explicitly not introduced during initial theme production:

```text
home.research_items
home.members
home.publications
```

Nor are research-specific post types, member databases, publication databases, faculty/student attributes, DOI integrations, ORCID integrations, or research-only category systems introduced.

If a real commercial implementation cannot achieve an acceptable result using existing Theme context, ThemeSettings, links, and Markdown, the deficiency must be recorded as evidence. A core addition is considered only if the requirement is demonstrably generic across unrelated site types.

## 7. Multilingual model

Multilingual validation is part of the commercial model because current Tomos provides site and page language support.

The reference validation includes at least:

- Japanese site-default operation,
- one or more English pages,
- `page.language` reflected in the theme's HTML language attribute,
- coexistence of Japanese and English URLs without automatic translation linkage.

The theme must not infer language from URL structure and must not create its own translation-management logic.

Automatic `hreflang`, translation relations, automatic translation, and translation workflow remain outside Phase 6.

## 8. Roles and update responsibility

### 8.1 Creator / production staff

Production staff are responsible for:

- selecting or preparing the commercial theme,
- initial site structure,
- initial content migration/creation assistance,
- site-specific ThemeSettings,
- site-specific `theme-assets/`,
- theme design adjustments,
- repeated Theme ZIP uploads during production,
- responsive QA,
- and release validation.

FTP/SFTP may still be used for initial site-specific setup where appropriate. It is not the only acceptable theme-maintenance path.

### 8.2 Laboratory operator

The ordinary laboratory operator is responsible for:

- writing and editing Markdown through Tomos Post,
- publishing News,
- updating Research/Members/Publications/About/Access content,
- and maintaining Japanese/English Markdown pages as needed.

Ordinary operation must not require editing `theme-settings.php`, CSS, HTML, or theme files.

### 8.3 Theme publisher / maintainer

A theme maintainer may release later Theme ZIP revisions. Ordinary users must be able to upload a newer package for the same theme ID without losing site-specific settings or content.

## 9. Commercial production flow

The reference production flow is:

```text
Create/install Tomos site
  ↓
Prepare site-specific settings and assets
  ↓
Upload commercial Theme ZIP
  ↓
Create/import Markdown content
  ↓
Review desktop / tablet / mobile
  ↓
Adjust theme package
  ↓
Re-upload same theme ID ZIP
  ↓
Repeat review and adjustment as needed
  ↓
Production approval
  ↓
Launch
```

The theme package is therefore a deployable product, not a one-time installer payload.

## 10. Post-launch flow

Ordinary post-launch operation is:

```text
Laboratory edits Markdown in Tomos Post
  ↓
Publish
  ↓
Home / list / page output follows existing Tomos publication rules
```

Theme maintenance is separate:

```text
Theme maintainer prepares revised ZIP
  ↓
User uploads same theme ID through Tomos Post
  ↓
Tomos validates package and compatibility
  ↓
Theme directory is transactionally replaced
  ↓
Site-specific settings/assets/content remain unchanged
  ↓
Active theme remains active
```

## 11. Theme ZIP commercial validation

The lab-theme validation must exercise the Theme ZIP deployment capability discovered during Phase 6.

At minimum, validate:

1. initial theme install,
2. repeated same-version upload during design adjustment,
3. newer-version update,
4. removed package files disappearing after replacement,
5. active theme remaining active,
6. `theme-settings.php` preservation,
7. `theme-assets/` preservation,
8. `content/` preservation,
9. `config.php` preservation,
10. invalid or incompatible replacement leaving the previous theme usable,
11. desktop/tablet/mobile display after repeated deployment.

A downgrade is rejected; same-version and higher-version updates are the supported replacement paths.

## 12. Release boundary

The Theme ZIP update improvement implemented during Phase 6 is not released independently merely because implementation is complete.

The intended sequence is:

```text
Theme ZIP update implementation
  ↓
commercial lab theme production
  ↓
commercial workflow validation
  ↓
Gate 6
  ↓
release scope and Tomos core version decision
```

The next Tomos core version is not predetermined by this document.

## 13. Gate 6 commercial validation scenarios

Gate 6 requires evidence from the following scenarios.

### Scenario A — New commercial site

A production staff member can install/configure Tomos, add the commercial lab theme, supply site-specific assets/settings, create the required Markdown structure, and reach a production-quality site without changing Tomos core PHP.

### Scenario B — Repeated production revision

A production staff member can repeatedly upload revisions of the same theme ID during design adjustment. Same-version re-upload works, obsolete theme files do not linger, and site-specific content/settings survive.

### Scenario C — Ordinary laboratory operation

A laboratory user can update News and ordinary pages through Tomos Post without touching ThemeSettings or theme files.

### Scenario D — Theme maintenance release

A later theme package can update the active commercial theme through Tomos Post while preserving site-specific data and content.

### Scenario E — Multilingual content

Japanese and English pages render using `page.language` without research-specific language logic in the theme or core.

### Scenario F — Failure safety

An invalid/incompatible theme package or failed replacement cannot destroy the working site theme; rollback/recovery behavior remains available.

## 14. Gate 6 pass conditions

Gate 6 passes only when all of the following are true:

1. No research-lab-specific core feature is required.
2. The theme provides commercially meaningful visual differentiation.
3. Hero/logo/key color and similar site-specific values are separated from the Theme ZIP.
4. Research, Members, and Publications remain operable as Markdown in the v1 model.
5. News updates both Home and News listing from the same Markdown source of truth.
6. Production staff can repeatedly deploy the same theme ID as ZIP during creation.
7. Ordinary users can later deploy a commercial theme update through the same browser flow.
8. Active-theme replacement preserves theme selection.
9. `theme-settings.php`, `theme-assets/`, `content/`, and `config.php` survive repeated theme deployment.
10. Standard themes continue to regress cleanly.
11. Tomos Update works with the commercial custom theme installed/active and preserves site-specific resources.
12. Desktop, tablet, and mobile layouts meet commercial quality expectations.
13. Japanese and English pages correctly expose and use `page.language`.
14. A creator can build the theme without modifying Tomos core PHP.
15. Ordinary laboratory operation remains Tomos Post/Markdown-centered.
16. Theme code does not duplicate Markdown parsing, publication, draft, or URL logic.
17. Commercial setup and update procedures are reproducible by another production staff member.
18. Theme/Core/ThemeSettings/Markdown responsibilities can be explained without lab-specific exceptions.

## 15. Explicit Phase 6 non-goals

Phase 6 does not include:

- Member management,
- Publication database,
- Research-specific content types,
- faculty/student attribute management,
- DOI API integration,
- ORCID integration,
- research-specific category systems,
- lab-specific administration UI,
- Theme Directory or Theme Store,
- no-code Theme Builder,
- automatic translation,
- or automatic installation of third-party themes.

## 16. Decision rule for a newly discovered platform need

A newly discovered requirement may return to core only when all of the following are true:

A. It is clearly useful beyond research-lab sites.  
B. It cannot be implemented safely and reasonably in Theme/ThemeSettings/Markdown.  
C. It belongs to core responsibilities such as publication, structure, URLs, safety, generic data, or package deployment.  
D. It is reusable across independent sites and themes.

If these conditions are not met, keep the requirement in the commercial theme or defer it.
