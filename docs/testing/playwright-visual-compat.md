# Playwright visual compatibility evidence

## Runner

```bash
scripts/run-playwright-visual.sh
```

Credential lookup:

```text
1. BEPLY_FS_USER / BEPLY_FS_PASSWORD when set.
2. facturascripts/passfs-beplytests.txt when present.
3. facturascripts/passfs.txt as fallback.
```

## Scope

The test logs into the real FacturaScripts instance and captures observable UI evidence for:

- `AdminBeplyPdf` (current BeplyPDFStudio gallery).
- `AdminPlantillasPDF` (legacy PlantillasPDF configuration UI).

It stores local-only artifacts under:

```text
docs/testing/evidencias/playwright-visual-compat/
```

These artifacts are ignored by Git because they contain screenshots of an installed third-party
plugin. They are evidence for local audit/testing, not distributable BeplyPDFStudio assets.

## Current Result

Last local run:

```text
1 passed
```

Generated files:

```text
beplypdfstudio-gallery.png
plantillaspdf-observable-ui.png
visual-compat-facts.json
```

The facts file detected 10 legacy references:

- 5 template values: `Template1`, `Template2`, `Template3`, `Template4`, `Template5`.
- 5 observable thumbnail URLs: `template1.png` to `template5.png`.
- 5 Beply compatible profiles visible in the gallery: `Standard`, `Summary`, `Boxes`, `Framed`,
  `Banner`.

## Acceptance Value

This proves the current environment exposes five legacy visual families and that BeplyPDFStudio
offers five compatible `legacy_*` profiles, shown before the three Beply-native layouts in the
gallery.
