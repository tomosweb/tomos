# Research Lab Commercial Theme Requirement Mapping v1

Status: Phase 6.4 implementation mapping  
Date: 2026-08-23  
Depends on: `lab-commercial-model-v1.md`

## 1. Purpose

This document maps the commercial research-lab requirements fixed in Phase 6.3 to the existing Tomos Theme Platform layers before any lab theme implementation begins.

The mapping answers four questions for each requirement:

1. Which layer owns it?
2. Which existing API or contract is used?
3. Can it be implemented now without core changes?
4. What must be validated during commercial theme production?

The default decision is to use existing Theme, ThemeSettings, Markdown, and Core APIs. A new core API is not introduced merely to make theme authoring more convenient.

## 2. Layer definitions

### Theme

Owns HTML structure, CSS, responsive behavior, visual hierarchy, static package assets, and presentation of existing template context.

### ThemeSettings

Owns low-frequency site-specific display values and site-specific assets outside the Theme ZIP.

### Markdown

Owns content maintained by the laboratory, including ordinary pages and News source files.

### Core

Owns Markdown conversion, publication/draft rules, URL generation, navigation/index generation, safe template context, Theme ZIP validation/deployment, and generic structured data already defined by Theme Platform.

## 3. Requirement matrix

| Requirement | Primary layer | Existing contract/API | Status before theme build | Validation note |
| --- | --- | --- | --- | --- |
| Commercial visual identity | Theme | `layout.html`, `home.html`, `page.html`, `list.html`, CSS/assets | Ready | Must prove sufficient design differentiation without core PHP |
| Site-specific logo | ThemeSettings | `theme.logo_url` | Ready | Must survive repeated Theme ZIP deployment |
| Site-specific key color | ThemeSettings | `theme.key_color` | Ready | Theme CSS must use it safely without assuming arbitrary CSS input |
| Hero enabled state | ThemeSettings + Theme | `theme.hero_enabled` | Ready | Theme controls markup/presentation |
| Hero image | ThemeSettings + Theme | `theme.hero_image_url` | Ready | Asset remains outside Theme ZIP |
| Hero title/subtitle | ThemeSettings + Theme | `theme.hero_title`, `theme.hero_subtitle` | Ready | Low-frequency production setting, not daily content |
| Hero button | ThemeSettings + Theme | `theme.hero_button_*` | Ready | Internal URL contract only |
| Home body copy | Markdown + Theme | `content/index.md`, `page.content` / `page.body` | Ready | Must remain editable as normal content |
| Home News list | Core + ThemeSettings + Theme | `home.has_news`, `home.news_items`, `home.news_url`, `theme.news_*` | Ready | Same Markdown source must drive Home and `/news/` |
| News publishing | Markdown + Core | normal page publication rules | Ready | Draft/order/URL logic must remain core-owned |
| Research content | Markdown | normal page/folder + `page.*` / `list.*` | Ready | No research-specific data model in v1 |
| Members content | Markdown | normal page/folder + `page.*` / `list.*` | Ready | No member DB or attributes in v1 |
| Publications content | Markdown | normal page/folder + `page.*` / `list.*` | Ready | No publication DB/DOI API in v1 |
| About | Markdown | normal page rendering | Ready | No special handling required |
| Access / Contact | Markdown | normal page rendering | Ready | No special handling required |
| Primary navigation | Core + Theme | `nav.*`, especially `nav.primary_items`, `nav.tree` | Ready | Theme must not scan files to build navigation |
| Breadcrumbs | Core + Theme | `nav.breadcrumbs` | Ready | Presentation only in Theme |
| Folder indexes | Core + Theme | Virtual Folder / `list.pages` / `page.folder_pages_html` | Ready | Theme must not read `pages.json` |
| Site language default | Core | `site.language` | Ready | Existing backward-compatible default remains `ja` |
| Per-page language | Markdown + Core + Theme | frontmatter `language`, `page.language` | Ready | Theme should use `<html lang="{{ page.language }}">` |
| Mixed Japanese/English pages | Markdown + Core | normal URLs + `page.language` | Ready | No URL-language inference or translation relation logic |
| Same-theme ZIP upload during production | Core | Theme Package deployment | Ready from Phase 6.2 | Same-version replacement must be exercised repeatedly |
| Newer theme package update | Core | Theme Package deployment | Ready from Phase 6.2 | Must preserve site-specific resources |
| Active theme update | Core | same theme ID replacement | Ready from Phase 6.2 | Configured theme selection must remain unchanged |
| Removed theme files disappear | Core | whole-directory replacement | Ready from Phase 6.2 | Must verify no merge-over leftovers |
| Failed update rollback | Core | transactional backup/restore | Ready from Phase 6.2 | Must validate with active commercial theme |
| `requires_tomos` compatibility | Core | Theme Contract v1 | Ready | Incompatible package must fail before replacing current theme |
| Daily operator workflow | Tomos Post + Markdown | existing post/edit/publish flow | Ready | Must not require ThemeSettings/CSS/HTML editing |
| Initial site-specific setup | Production operation | root `theme-settings.php`, `theme-assets/` | Ready | FTP/SFTP may be used; this is not Theme package maintenance |
| Responsive quality | Theme | CSS/layout | Theme work required | Must validate PC/tablet/mobile |
| Theme package release/versioning | Theme package metadata | `theme.json version` | Ready | Theme version remains independent of Tomos core version |

## 4. Home page mapping in detail

The reference Home page from Phase 6.3 is mapped as follows.

```text
Header                     → Theme + nav.*
Hero                       → Theme + theme.hero_*
Laboratory introduction    → Markdown index.md + page.content/page.body
Research section/link      → Theme presentation + ordinary URL/navigation context
News                       → home.* + theme.news_*
Members section/link       → Theme presentation + ordinary URL/navigation context
Publications section/link  → Theme presentation + ordinary URL/navigation context
Footer                     → Theme
```

### 4.1 Research / Members / Publications teasers

There is no existing structured API equivalent to:

```text
home.research_items
home.members
home.publications
```

This is not treated as a blocker for the first commercial theme.

The v1 implementation should first use ordinary navigation links, static section labels, and Markdown-managed landing pages. If a section needs richer teaser content, the first design response is to keep that content in `index.md` or the relevant landing page rather than creating a new core collection API.

Only if the finished commercial design cannot meet acceptable requirements without structured collections should this be recorded as Phase 6 evidence.

## 5. Current platform capability findings

### 5.1 No core change is currently required for the baseline site map

The current contracts already provide:

- generic page rendering,
- generic list/Virtual Folder rendering,
- safe navigation context,
- site-specific Hero/logo/key-color settings,
- structured Home News,
- multilingual page metadata,
- and repeated Theme ZIP deployment.

Therefore the baseline Home / Research / Members / Publications / News / About / Access structure can proceed to theme implementation without adding a research-specific core feature.

### 5.2 ThemeSettings remain intentionally low-frequency

`theme-settings.php` is still a production/maintenance surface rather than a laboratory daily-edit surface.

The lab theme must not move ordinary Research, Members, Publications, About, Access, or News copy into ThemeSettings merely because that would make Home layout easier.

### 5.3 News is the only structured Home collection in v1

This is intentional. News already passed the genericity test and was dogfooded before commercial use.

Research/Members/Publications do not receive parallel structured collections unless commercial evidence proves that a generic reusable collection capability is necessary.

### 5.4 Multilingual support is metadata, not translation management

The theme may rely on `page.language` but must not assume that `/en/` implies English, infer translation pairs, generate translation relations, or implement automatic hreflang behavior.

## 6. Items requiring theme-production validation

The following are not missing APIs, but they must be proven in the actual commercial theme.

### A. Home composition quality

Can `home.html`, Hero settings, `index.md`, News, and ordinary links create a sufficiently rich research-lab Home page without new structured collections?

### B. Research presentation

Can Research be visually strong using ordinary Markdown pages/folders and existing list/page context?

### C. Member presentation

Can a commercially acceptable Members page be authored in Markdown without requiring first-class member records?

### D. Publication presentation

Can Publications be maintained in Markdown with acceptable readability and update effort without a publication database?

### E. Repeated production deployment

Can staff repeatedly upload the same Theme ZIP ID while tuning CSS/templates without touching site-specific data?

### F. Operator simplicity

After handoff, can a laboratory operator maintain the site entirely through Tomos Post and Markdown for normal work?

### G. Responsive behavior

Can the theme meet commercial visual quality on desktop, tablet, and mobile using theme-only CSS/layout?

### H. Mixed-language presentation

Can Japanese and English pages share the theme cleanly using only `page.language` and ordinary navigation/links?

## 7. Evidence that would justify returning to core

A Phase 6 implementation finding may become a core candidate only when all four conditions are met:

1. The requirement is useful beyond research-lab sites.
2. Theme, ThemeSettings, and Markdown cannot solve it safely/reasonably.
3. The responsibility belongs to publication, structure, URL, safety, generic data, or generic package deployment.
4. The design is reusable across independent themes and site types.

Examples of evidence that might qualify later:

- multiple unrelated commercial themes need the same generic structured collection contract;
- a generic page relationship cannot be represented safely with current page/navigation APIs;
- a generic package-deployment safety requirement is missing.

Examples that do not qualify by themselves:

- a lab theme wants a prettier member card;
- a designer wants fewer lines of template HTML;
- a specific customer wants a DOI field;
- a single lab wants faculty/student filtering.

## 8. Implementation order derived from the mapping

The commercial theme should now be built in this order:

1. package metadata and theme shell;
2. responsive `layout.html` and navigation;
3. Home Hero and `index.md` body;
4. Home News using existing `home.*`;
5. Research page/folder presentation;
6. Members Markdown presentation;
7. Publications Markdown presentation;
8. About / Access / Contact;
9. Japanese/English page validation;
10. repeated same-ID Theme ZIP deployment;
11. commercial responsive QA;
12. record any platform insufficiency as evidence before considering core changes.

## 9. Phase 6.4 conclusion

For the fixed Phase 6.3 baseline, the mapping result is:

```text
Required new research-specific core APIs: 0
Required new research-specific content types: 0
Required new research-specific admin UI: 0
Known generic core prerequisite discovered in Phase 6: Theme ZIP repeated deployment (implemented in 6.2)
Primary remaining work: commercial Theme implementation and validation
```

The next implementation step may therefore begin with the commercial research-lab theme itself, while treating any newly discovered platform need as evidence to be evaluated against the generic-core decision rule.