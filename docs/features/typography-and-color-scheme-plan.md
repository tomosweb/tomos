# Tomos Typography and Color Scheme Development Plan

Status: revised specification; implementation has not started

## Goal

Add separate site-wide typography settings for post content and the public theme UI in Tomos Post. After typography settings and theme integration are established, add light and dark display modes.

## Stage 1: Web font and text size settings

### Tomos Post settings

Provide separate settings for:

- **Post content**: the Markdown article content and its headings, lists, quotations, and other text. The post title uses the selected content font; the theme retains control of title size and visual hierarchy.
- **Public theme UI**: navigation, site identity, metadata, pagination, buttons, and other interface text rendered by the active public theme.

For each scope:

- Select a web font from a curated list maintained by the Tomos development team. Site administrators cannot register arbitrary font sources or family names.
- Select a base text size from a small set of presets. Keep the labels consistent (for example, small, standard, and large); determine the actual size values by checking the bundled themes.
- Provide a theme-default option. When selected, preserve the active theme's existing typography.
- Keep font files out of Tomos packages. The curated font catalog defines the external stylesheet/source, family value, and fallback stack.
- Do not add visitor-facing font or text-size controls.

If both scopes use the same external font, load its source only once. If the external source cannot load, use the configured fallback stack.

### Theme contract

- Define separate core CSS custom properties for content font family, content base size, theme UI font family, and theme UI base size.
- Themes should use relative sizing for headings and interface hierarchies so base-size changes preserve their visual relationships.
- Adapt and verify all bundled themes before release.
- Third-party themes may opt into the contract. A theme that does not opt in must continue rendering with its own typography and must not break.
- Declare theme typography support in theme metadata so Tomos Post can indicate when the active theme may not apply the selected settings. Follow the existing `supports` convention as descriptive metadata only; do not use it to disable themes or change runtime behavior. Settle the exact field name during implementation.
- After implementation and bundled-theme verification, publish the external-theme integration instructions at https://tomoswords.org/developers/themes/. Include the CSS variables, their scope, theme-default behavior, metadata declaration, and a working example.

### Completion conditions

- The curated font list and its external sources, fallback stacks, and usage terms are defined.
- Tomos Post saves the independent content and UI font and size selections safely.
- Missing or unavailable external fonts fall back without breaking the page.
- The size presets are reflected in every bundled theme that declares support.
- Unsupported third-party themes preserve their own appearance, and Tomos Post indicates their support status.
- The developer page at https://tomoswords.org/developers/themes/ is updated and published after the implementation contract is stable.

## Stage 2: Light and dark display modes

### Tomos Post and public site behavior

- Add a site-level display policy: light only, dark only, or light/dark switchable.
- When the site is switchable, show a standard light/dark control on the public site and remember the visitor's choice in that browser.
- On a first visit without a saved choice, use the operating system preference.
- When the site is configured for one mode only, use that mode and do not show the visitor switch.

### Theme contract

- Extend theme metadata to declare support for light, dark, or both modes.
- Provide semantic core color tokens for page background, text, links, borders, surfaces, and controls. Themes should map their visual design to these tokens for each supported mode.
- Theme mode support constrains the site-level display policy: a light-only theme cannot be configured as dark-only or switchable; likewise for a dark-only theme. A theme supporting both may use any of the three policies.
- Document treatment of assets or components that need mode-specific styling, including logos, illustrations, and code blocks.
- Adapt bundled themes and theme validation/documentation to the new contract. Preserve existing themes with a safe default mode until they declare support.

### Completion conditions

- The selected display policy works with the active theme's declared capabilities.
- Switchable themes have readable, tested light and dark palettes across their public templates and controls.
- Single-mode themes do not expose a nonfunctional switch.
- Saved preference, first-visit system preference, keyboard operation, and contrast are verified.
- Existing themes and sites without the new metadata remain usable.

## Delivery order

1. Inspect the existing settings, theme metadata, CSS architecture, and bundled themes.
2. Specify and implement the curated font catalog and independent Tomos Post settings for post content and public theme UI.
3. Add the core typography variables; adapt and verify bundled themes.
4. Publish the finalized third-party theme integration instructions at https://tomoswords.org/developers/themes/.
5. Specify and implement theme color-scheme capability metadata and semantic color tokens.
6. Add the site-level light-only, dark-only, or switchable policy and the public-site switch for switchable themes.
7. Adapt and verify bundled themes, then update theme-authoring and validation documentation.

## Open decisions for Stage 1

- Which curated web fonts to offer.
- Exact size values for the small, standard, and large presets in each scope.
- The exact theme metadata field used to declare typography support, based on the repository's current conventions.

## Out of scope

- Bundling web-font files with Tomos.
- Arbitrary font source registration by site administrators.
- Visitor-facing font or text-size controls.
- Automatically redesigning arbitrary third-party themes. Theme authors must adopt the documented contract for full support.
