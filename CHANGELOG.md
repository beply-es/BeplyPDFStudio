# Changelog

## v2.2 - 2026-07-29

- Uses the selected `AttachedFile` logo in the SVG fallback preview shown by the style editor, before tenant-branding or packaged Beply fallbacks.
- Invalidates cached style previews so previously generated Beply-logo thumbnails are rebuilt with the selected logo.

## v2.1 - 2026-07-29

- Shows `.jpeg` uploads in the style logo library and automatically selects the newly uploaded file.
- Resolves the selected `AttachedFile` logo in the real PDF header before legacy, tenant-branding and packaged fallbacks, while rejecting paths outside `MyFiles`.

## v2.0 - 2026-07-23

- Reorganiza la cabecera de Clásico en dos bloques alineados desde el borde superior: logo y bloque fiscal completo, intercambiando ambos al mover el logo.
- Reduce a la mitad el espacio vertical del bloque cliente/fecha/número/serie y elimina el separador redundante sobre los totales.
- Ancla dinámicamente totales, recibos y bloques finales al pie útil del documento Clásico, con cobertura PDF para facturas cortas, medias y multipágina.

## v1.25 - 2026-07-14

- Keeps totals on the same page for short corporate invoices, quotes, orders and delivery notes by applying one shared bottom-spacing safety calculation to every customer and supplier document type.
- Adds real PDF regression coverage for orphaned totals across all supported business document flows.

## v1.24 - 2026-07-06

- Adds the rich Markdown editor for document lines, observations, product descriptions and template legal text, while keeping listings/search results rendered as clean plain text.
- Improves dynamic PDF block placement, fiscal QR slots, preview generation and Studio Quote template rendering.
- Adds document PDF caching and expands local browser/PHP test coverage for rich text, templates and document layouts.

## v1.23 - 2026-06-26

- Shows IRPF in the rendered tax breakdown, with negative withholding amounts, across all HTML/PDF templates.
- Prints the IBAN of the bank account assigned to the payment method, regardless of the payment method name.
- Keeps compact A5 totals rendering valid for the Azure layout after the tax-breakdown rows became explicit.

## v1.21 - 2026-06-22

- Keeps PDFStudio line-column configuration authoritative over native `FormatoDocumento.linecols`, preventing unconfigured `RE`/`IRPF` columns from leaking into rendered documents.
- Hides configured optional percentage columns (`% Dto.`, `IVA`, `RE`, `IRPF`) when every document line has a zero value for that column.
- Adds default and content-based automatic line-column widths so description-heavy documents get more usable space when widths are left automatic.

## v1.20 - 2026-06-15

- Adds tenant white-label logo fallback for previews, HTML rendering and PDF exports.
- Keeps the admin template gallery on cached/static previews so opening `AdminBeplyPdf` does not render all previews synchronously.

## v1.18 - 2026-06-09

- Replaces packaged design fallback thumbnails and legacy aliases with real renderer previews so the gallery remains styled even before dynamic previews are available.
- Adds cache busting to static fallback thumbnail URLs used by the template gallery and side preview.

## v1.17 - 2026-06-09

- Invalidates BeplyPDFStudio preview cache after switching previews to the real HTML/PDF renderer.
- Restores the declared minimum PHP version to 8.1 so the plugin remains installable on current production runtimes.

## v1.16 - 2026-06-08

- Adds a GitHub-hosted fallback for PHP 8.4 CI and release jobs when `BEPLY_GHA_RUNNER` is not available for the repository.
- Supersedes the cancelled `v1.15` validation run without moving the existing immutable tag.

## v1.15 - 2026-06-08

- Enables tag-based GitHub releases for the PHP 8.4 validation workflow.

## v1.14 - 2026-06-08

- Adds a Tests workflow for the PHP 8.4 scanner.

- Declares PHP 8.4 as the minimum supported runtime.
- Adds a PHP 8.4 compatibility scan across plugin source files in CI.
- Aligns release metadata for the PHP 8.4 validation pass.
