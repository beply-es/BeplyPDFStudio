# Changelog

## v3.6 - 2026-09-01

- Retira el paso legado de subida a produccion de `release.yml`. La ingesta en el
  catalogo de produccion es del workflow canonico
  `beply-es/beply-k3s` `prod-plugin-artifact-ingest.yml`, y en su lugar queda un
  aviso que imprime ese camino con sus pines exactos, para que un verde signifique
  "release inmutable publicada" y nunca "fila ingestada en produccion".
- Version nueva porque `3.5` ya tenia bytes de artefacto en el catalogo: el
  contrato es inmutable y no se sobrescribe.

## v3.5 - 2026-09-01

- Cierra en falso la subida del candidato al catálogo de producción: una
  credencial ausente ya no produce un release verde sin fila ingestada, sino un
  fallo explícito con el artefacto inmutable preservado.
- La subida a producción reutiliza el mismo helper verificado que la de
  desarrollo, con checksum y tamaño fijados y relectura del testigo de la fila
  del catálogo, en lugar de un `curl` sin readback.
- Adopta el nombre de secreto canónico de la flota `BEPLY_PROD_CI_TOKEN` y la
  URL de producción por defecto, en vez de nombres que ningún repositorio tenía
  configurados.

## v3.4 - 2026-08-31

- Corrige el campo `CIF/NIF` del bloque de metadatos del diseño Enmarcado con
  roles explícitos: cliente comprador en ventas y empresa compradora en compras,
  sin duplicar allí la identidad del proveedor.
- Hace visible en toda factura rectificativa la referencia persistida a la
  original y etiqueta como motivo sus observaciones persistidas, incluso cuando
  los documentos padre o las notas estén ocultos; si falta cualquiera de esos
  datos se omite sin derivarlo ni inventarlo.
- Normaliza y deduplica la referencia original en una única frontera compartida
  por HTML y Cezpdf, incluidos los fallbacks Clásico, Banda, Resumen, Corporativo
  y Cajas con documentos padre ocultos o visibles.
- Reserva dinámicamente en el fallback Corporativo la altura de sus metadatos,
  manteniendo `Original`, `Número 2` y documentos padre por encima del separador
  y del bloque de empresa/contraparte.
- Añade un contrato sintético HTML/PDF real para identidades válidas, vacías,
  espacios, placeholders y prefijos de integración, además de base, IVA y total
  exactos en facturas ordinarias y rectificativas negativas.

## v3.3 - 2026-08-27

- Evita imprimir como identidad fiscal del comprador los placeholders de
  cliente compartido y los identificadores de integración de ALI, LYM, MAI,
  MIR, MIRR y SHP.
- Conserva el fallback desde una factura con espacios o identidad sintética al
  NIF autoritativo del cliente; cuando ambos son sintéticos, no imprime ninguno.

## v3.2 - 2026-08-27

- Trata un `cifnif` de factura compuesto sólo por espacios como ausente y
  recupera la identidad fiscal del cliente en los dos motores PDF.
- Añade un rojo unitario con la preimagen literal `"   "` y valida el efecto
  generando un PDF real, extrayendo su texto y comprobando la columna del
  comprador.

## v3.1 - 2026-08-26

- Trata un CIF/NIF compuesto sólo por espacios como ausente en ambos motores
  PDF y evita caer al CIF del cliente compartido para documentos de venta de
  marketplace.
- Conserva el fallback fiscal habitual de los documentos de proveedor y añade
  contrato unitario y prueba de efecto sobre el texto extraído del PDF real.

## v3.0 - 2026-08-26

- La plantilla de factura por defecto desglosa por línea base, porcentaje de
  IVA y total con IVA, que el render HTML calcula con el mismo criterio que el
  motor nativo.
- El reparto de 520 puntos conserva al menos 62 puntos para importes y 48 para
  el resto de columnas.
- El contrato genera el PDF real y verifica en el texto extraído el IVA, el
  total bruto, un nombre completo sintético y la ausencia de una identidad
  fiscal sintética.
- El canario focal neutraliza tanto comprador como emisor y rechaza cualquier
  etiqueta o patrón de identidad fiscal en el texto completo del PDF.
- Mantiene la fecha dentro de la caja de metadatos cuando una extensión añade
  una tercera caja, compactando únicamente esa variante del diseño Cajas.
- Añade las traducciones turcas exigidas por el catálogo actual de FacturaScripts.

## v2.9 - 2026-08-20

- Evita que la fecha y el número se recorten cuando Taller añade la tercera caja de vehículo al diseño `legacy_boxes`.
- Mantiene las tres cajas dentro del ancho útil A4 y reserva más espacio para los metadatos del documento.

## v2.8 - 2026-08-20

- Restores the four-column currency, net, taxes and grand-total summary in the legacy Boxes invoice layout.
- Renders long native `FormatoDocumento` legal footers as a wrapping running element inside the reserved page margin, instead of silently dropping the text.
- Extends the framed line area through the measured blank space and aligns the legacy header, party boxes, tax summary and warranty text with PlantillasPDF documents.
- Adds HTML and real-PDF regression contracts for complete long legal footers, lower-margin placement, legacy summary columns and dynamic bottom anchoring.

## v2.6 - 2026-08-14

- Preserves every table section when FacturaScripts reports combine a parameter page with tabular results, instead of returning only the styled report header.
- Renders trial balance, balance sheet and general ledger reports inside the active PDFStudio design, keeping its logo, colors and table styling.
- Keeps report sections in their original order and automatically uses landscape orientation for wide accounting tables such as the general ledger.
- Adds end-to-end PDF text assertions for all three BeplyInformes accounting report flows across the complete template gallery.

## v2.5 - 2026-08-14

- Places the optional total-units value before every monetary subtotal, tax breakdown and grand total in all nine PDF layouts.
- Keeps the grand total in euros as the final/rightmost summary value, including the horizontal Boxes layout.
- Adds a semantic ordering contract across every template so future layout changes cannot silently move units behind monetary totals.

## v2.3 - 2026-08-12

- Applies the configured logo position (left / center / right) in every template. Boxes, Framed and Banner were locked to the right, while Corporate, Azure, Prisma and Studio were locked to the left, so the setting was silently ignored in 7 of 9 designs.
- Centers the logo over the printable width instead of inside its own column, so "center" no longer looks right-aligned in the Classic design.
- Stops the Prisma totals from being clipped on the right: the totals column was pinned to 45% of a fixed-layout table while the amount was `nowrap`, pushing the currency outside the right margin.
- Keeps the Prisma header contact columns inside the sheet on narrow paper (A5), where the auto-layout table could not shrink below its minimum width and overflowed the page.
- Retires the Studio design from the gallery without removing it: companies that already use it keep rendering, but it is no longer offered.
- Logs a warning when the HTML engine returns nothing and a document falls back to the drawing engine, which flattens line markdown to plain text. This used to happen silently.
- Adds `Tests/run-contract.php`, a visual contract suite that measures the real PDF geometry (logo placement, margin overflow, blank pages, markdown bold/italic/lists in lines, observations and footer, across A4/A5 and both orientations).
- Wires `run-contract.php` and the previously orphaned `run-visual.php` into `scripts/run-tests.sh`, so a green suite now means the documents actually look right.
- Fixes three long-standing false failures that made the suite unusable as a signal: the A5 bottom-anchor probe only understood `padding-top` and reported `-1` for designs using `translateY`; `run-e2e` and `run-format-template` aborted with a fatal error on any environment where the global style had not been seeded; and the "render under 2s" guard charged the first design with the one-off Twig/WeasyPrint warm-up.

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
