# Changelog

## v0.1.0-alpha

Initial alpha release candidate.

### Added

- Tomos Post sequential one-image upload sessions with retry, cancellation, environment-aware limits, and publish-after-complete behavior
- Adaptive chunk upload for images larger than the current server request limit
- Preservation of single line breaks inside Markdown paragraphs
- Clear validation when an image extension and its actual data format do not match
- JPEG EXIF Orientation 1-8 correction with IFD0 fallback, correct mirrored rotation directions, and diagnostics
- Phase 9 distribution and documentation audit, including safe existing-installation update guidance
- Completion of Phase 9 real-environment integration and regression verification with a release-ready final decision
- Virtual folder index pages when public child articles exist without a folder `index.md`
- Folder article lists in the tomos-minimal page template

- Markdown file based page rendering
- Frontmatter support
- Draft exclusion
- Metadata index generation
- Navigation and breadcrumbs
- Folder and page lists
- Wiki links
- Image embedding
- Tags
- Search
- RSS and sitemap
- Setup screen
- Setup guard
- Theme validation
- favicon and OGP support
- Installation documentation
- Distribution build folder generation

### Known limitations

- HTML cache is not implemented yet.
- Web-based Markdown editing is not implemented.
- Image upload is not implemented.
- Login and admin screens are not implemented.
- Distribution ZIP generation is not implemented yet.

### Fixed

- Preserve virtual folder page type and content-relative folder path through theme rendering so folder article lists are displayed
- Keep folder article lists limited to direct child articles
- Refresh the tomos-90s stylesheet URL after removing unintended tag-list bullet markers
