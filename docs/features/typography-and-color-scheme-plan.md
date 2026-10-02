# Tomos Typography and Color Scheme Development Plan

Status: planned; implementation has not started

## Goal

Add site-wide typography settings to Tomos Post, then add light and dark display modes to Tomos public sites with an explicit theme contract.

The work is split into two stages. Typography comes first. Color scheme support follows after the typography settings and theme integration are established.

## Stage 1: Web font and text size settings

### Tomos Post settings

- Set the public site's web font and base text size from Tomos Post.
- Font files are not bundled with Tomos. The site administrator supplies the external font stylesheet/source and the corresponding font-family value, with a fallback stack.
- The setting applies site-wide. Do not add a visitor-facing font or text-size switcher.
- Provide safe defaults so a missing, invalid, or unavailable external font falls back to the configured system fonts.

### Theme contract

- Define core typography CSS custom properties for the selected font family and base size.
- Standard themes should consume those properties and use relative sizing where practical, so the site-wide text-size setting has consistent effect.
- Document the variables and provide examples for theme authors.
- Existing themes that do not use the variables continue to render; document that their typography may not fully follow the settings until adapted.

### Completion conditions

- The font is loaded from an administrator-configured external source; no font binaries are added to Tomos packages.
- Font-family fallback works when the external source is unavailable.
- The configured base text size is reflected in themes updated for the contract.
- Tomos Post validates and saves the settings safely.

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

1. Specify and implement Tomos Post web-font and base text-size settings.
2. Add and document core typography variables; adapt bundled themes and verify the settings.
3. Specify and implement theme color-scheme capability metadata and semantic color tokens.
4. Add the site-level light-only, dark-only, or switchable policy and the public-site switch for switchable themes.
5. Adapt and verify bundled themes, then update theme-authoring and validation documentation.

## Out of scope

- Bundling web-font files with Tomos.
- Visitor-facing font or text-size controls.
- Automatically redesigning arbitrary third-party themes. Theme authors must adopt the documented contracts for full support.
