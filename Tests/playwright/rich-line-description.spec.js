const { test, expect } = require('@playwright/test');
const http = require('http');

const pluginBase = (process.env.BEPLY_PLUGIN_ASSET_URL
  || 'http://127.0.0.1:8013/Plugins/BeplyPDFStudio').replace(/\/+$/, '');

function fixtureHtml() {
  return `<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Beply rich line description fixture</title>
  <link rel="stylesheet" href="${pluginBase}/Assets/Vendor/quill/quill.snow.css">
  <link rel="stylesheet" href="${pluginBase}/Assets/CSS/RichLineDescription.css">
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; }
    .d-none { display: none !important; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.18); padding: 32px; }
    .modal.show { display: block; }
    .modal-dialog { max-width: 980px; margin: 0 auto; }
    .modal-content { background: #fff; border: 1px solid #ccc; border-radius: 6px; }
    .modal-header, .modal-footer { align-items: center; display: flex; gap: 8px; padding: 12px; }
    .modal-header { border-bottom: 1px solid #ddd; }
    .modal-footer { border-top: 1px solid #ddd; justify-content: flex-end; }
    .modal-body { padding: 12px; }
    .btn { border: 1px solid #bbb; border-radius: 4px; padding: 5px 10px; }
    .form-control, .form-select { border: 1px solid #bbb; border-radius: 4px; padding: 5px 8px; }
    .form-select-sm { font-size: 13px; }
    .doc-line-desc { width: 100%; }
  </style>
</head>
<body>
  <div class="line">
    <textarea name="descripcion_1" class="form-control form-control-sm doc-line-desc beply-rich-source">Alcance
- **Instalacion** inicial
- Soporte prioritario</textarea>
    <button type="button" class="btn btn-sm btn-light me-2 beply-rich-line-button" data-beply-rich-open="descripcion_1">Editor</button>
  </div>
  <div class="line">
    <textarea name="descripcion_2" class="form-control form-control-sm doc-line-desc beply-rich-source">Texto plano inicial</textarea>
    <button type="button" class="btn btn-sm btn-light me-2 beply-rich-line-button" data-beply-rich-open="descripcion_2">Editor</button>
  </div>
  <div class="line">
    <textarea name="descripcion_3" class="form-control form-control-sm doc-line-desc beply-rich-source" disabled>Bloqueado
- **No editable**</textarea>
    <button type="button" class="btn btn-sm btn-light me-2 beply-rich-line-button" data-beply-rich-open="descripcion_3">Editor</button>
  </div>
  <form id="product-form">
    <label for="product-description">Producto</label>
    <div class="beply-rich-product-field">
      <div class="beply-rich-product-actions">
        <button type="button" class="btn btn-sm btn-light beply-rich-product-button"
          data-beply-rich-product-button="1" data-beply-rich-open="descripcion">Editor</button>
      </div>
      <div class="form-control beply-rich-surface beply-rich-product-surface beply-rich-surface-readonly"
        data-beply-rich-for="descripcion" role="textbox" tabindex="0" aria-readonly="true">
        <p>Producto web</p>
        <ul><li><strong>Visible</strong> en ficha</li><li>Listo para venta</li></ul>
      </div>
      <textarea id="product-description" name="descripcion" class="form-control beply-rich-source beply-rich-product-source d-none">Producto web
- **Visible** en ficha
- Listo para venta</textarea>
    </div>
  </form>
  <form id="document-footer">
    <label for="document-observations">Observaciones</label>
    <textarea id="document-observations" name="observaciones" class="form-control">Notas internas
- **Incluye** revision final
- Entrega *documentada*</textarea>
  </form>
  <form id="style-texts">
    <label for="footer-text">Texto final / legal</label>
    <textarea id="footer-text" name="footer_text" class="form-control">Condiciones del servicio
- Pago por transferencia
- Soporte *post-entrega*</textarea>
  </form>
  <script src="${pluginBase}/Assets/Vendor/quill/quill.js"></script>
  <script src="${pluginBase}/Assets/JS/RichLineDescription.js"></script>
  <script src="${pluginBase}/Assets/JS/RichProductDescription.js"></script>
</body>
</html>`;
}

test.describe('Beply rich line description editor', () => {
  let server;
  let baseURL;

  test.beforeAll(async () => {
    server = http.createServer((req, res) => {
      res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
      res.end(fixtureHtml());
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    baseURL = `http://127.0.0.1:${server.address().port}`;
  });

  test.afterAll(async () => {
    await new Promise((resolve) => server.close(resolve));
  });

  test('shows Visual/Markdown mode selector and keeps toolbar controls usable', async ({ page }) => {
    await page.goto(baseURL, { waitUntil: 'networkidle' });
    await page.locator('[data-beply-rich-open="descripcion_1"]').click();

    const mode = page.locator('[data-beply-rich-mode]');
    await expect(mode).toBeVisible();
    await expect(mode).toHaveValue('visual');
    await expect(mode.locator('option')).toHaveText(['Visual', 'Markdown']);

    await expect(page.locator('.beply-quill-toolbar')).toBeVisible();
    await expect(page.locator('.beply-quill-toolbar .ql-picker.ql-header')).toHaveCount(0);
    await expect(page.locator('.beply-quill-toolbar .ql-bold')).toBeVisible();
    await expect(page.locator('.beply-quill-toolbar .ql-list[value="bullet"]')).toBeVisible();
    await expect(page.locator('.ql-editor')).toBeVisible();
    await expect(page.locator('.ql-editor')).toContainText('Alcance');

    await mode.selectOption('markdown');
    await expect(page.locator('.beply-rich-md')).toBeVisible();
    await expect(page.locator('.beply-quill-wrap')).toHaveClass(/d-none/);
    await expect(page.locator('.beply-quill-toolbar .ql-bold')).toBeVisible();

    await page.locator('.beply-rich-md').fill('Texto prueba');
    await page.locator('.beply-rich-md').evaluate((textarea) => {
      textarea.setSelectionRange(6, 12);
    });
    await page.locator('.beply-quill-toolbar .ql-bold').click();
    await expect(page.locator('.beply-rich-md')).toHaveValue('Texto **prueba**');
  });

  test('preserves spaces around bold text when saving from visual mode', async ({ page }) => {
    await page.goto(baseURL, { waitUntil: 'networkidle' });
    await page.locator('[data-beply-rich-open="descripcion_1"]').click();

    await page.evaluate(() => {
      const quill = window.Quill.find(document.querySelector('.beply-quill-editor'));
      quill.setText('Hola mundo vecino');
      quill.formatText(5, 6, 'bold', true);
    });

    await page.locator('[data-beply-rich-save]').click();

    const richSurface = page.locator('[data-beply-rich-for="descripcion_1"]');
    await expect(page.locator('textarea[name="descripcion_1"]')).toHaveValue('Hola **mundo** vecino');
    await expect(richSurface.locator('strong')).toHaveText('mundo');
    await expect(richSurface).toContainText('Hola mundo vecino');
    await expect(page.locator('textarea[name="descripcion_1"]')).toHaveClass(/d-none/);
  });

  test('keeps rich lines rendered and plain lines editable', async ({ page }) => {
    await page.goto(baseURL, { waitUntil: 'networkidle' });

    const richTextarea = page.locator('textarea[name="descripcion_1"]');
    const richSurface = page.locator('[data-beply-rich-for="descripcion_1"]');
    await expect(richTextarea).toHaveClass(/d-none/);
    await expect(richSurface).toBeVisible();
    await expect(richSurface.locator('h4,h5,h6')).toHaveCount(0);
    await expect(richSurface.locator('p').first()).toHaveText('Alcance');
    await expect(richSurface.locator('li')).toHaveCount(2);
    await expect(richSurface.locator('strong')).toHaveText('Instalacion');

    await richSurface.click();
    await expect(page.locator('#beply-rich-desc-modal')).toHaveClass(/show/);
    await page.locator('[data-beply-rich-save]').click();

    const plainTextarea = page.locator('textarea[name="descripcion_2"]');
    const plainSurface = page.locator('[data-beply-rich-for="descripcion_2"]');
    await expect(plainTextarea).toBeVisible();
    await expect(plainSurface).toHaveClass(/d-none/);
    await plainTextarea.fill('Texto plano modificado');
    await plainTextarea.blur();
    await expect(plainTextarea).toBeVisible();
    await expect(plainTextarea).toHaveValue('Texto plano modificado');

    await page.locator('[data-beply-rich-open="descripcion_2"]').click();
    await page.locator('[data-beply-rich-mode]').selectOption('markdown');
    await page.locator('.beply-rich-md').fill('Texto plano desde modal');
    await page.locator('[data-beply-rich-save]').click();
    await expect(plainTextarea).toBeVisible();
    await expect(plainTextarea).toHaveValue('Texto plano desde modal');
    await expect(plainSurface).toHaveClass(/d-none/);

    await page.locator('[data-beply-rich-open="descripcion_2"]').click();
    await page.locator('[data-beply-rich-mode]').selectOption('markdown');
    await page.locator('.beply-rich-md').fill('## Titulo modal\n- punto\n- **negrita**');
    await page.locator('[data-beply-rich-save]').click();
    await expect(plainTextarea).toHaveClass(/d-none/);
    await expect(plainSurface).toBeVisible();
    await expect(plainSurface.locator('h4,h5,h6')).toHaveCount(0);
    await expect(plainSurface.locator('p').first()).toHaveText('Titulo modal');
    await expect(plainSurface.locator('li')).toHaveCount(2);
    await expect(plainSurface.locator('strong')).toHaveText('negrita');
  });

  test('does not open the editor for disabled document descriptions', async ({ page }) => {
    await page.goto(baseURL, { waitUntil: 'networkidle' });

    const lockedTextarea = page.locator('textarea[name="descripcion_3"]');
    const lockedSurface = page.locator('[data-beply-rich-for="descripcion_3"]');
    await expect(lockedTextarea).toHaveClass(/d-none/);
    await expect(lockedTextarea).toBeDisabled();
    await expect(lockedSurface).toBeVisible();
    await expect(lockedSurface).toHaveClass(/beply-rich-surface-locked/);
    await expect(lockedSurface.locator('h4,h5,h6')).toHaveCount(0);
    await expect(lockedSurface.locator('p').first()).toHaveText('Bloqueado');
    await expect(lockedSurface.locator('strong')).toHaveText('No editable');

    await lockedSurface.click();
    await page.locator('[data-beply-rich-open="descripcion_3"]').click();
    await expect(page.locator('#beply-rich-desc-modal.show')).toHaveCount(0);
  });

  test('enhances product description with the same button and rendered lock state', async ({ page }) => {
    await page.goto(baseURL, { waitUntil: 'networkidle' });

    const productTextarea = page.locator('textarea[name="descripcion"]');
    const productSurface = page.locator('[data-beply-rich-for="descripcion"]');
    const productButton = page.locator('[data-beply-rich-product-button="1"]');

    await expect(productSurface).toHaveCount(1);
    await expect(productButton).toHaveCount(1);
    await expect(productButton).toBeVisible();
    await expect(productButton).toHaveAttribute('data-beply-rich-open', 'descripcion');
    await expect(productButton.locator('xpath=ancestor::*[contains(@class, "beply-rich-product-actions")]')).toHaveCSS('position', 'absolute');
    await expect(productButton.locator('xpath=ancestor::*[contains(@class, "beply-rich-product-field")]')).toHaveCSS('position', 'relative');
    await expect(productTextarea).toHaveClass(/d-none/);
    await expect(productSurface).toBeVisible();
    await expect(productSurface).toHaveClass(/beply-rich-product-surface/);
    await expect(productSurface.locator('h4,h5,h6')).toHaveCount(0);
    await expect(productSurface.locator('p').first()).toHaveText('Producto web');
    await expect(productSurface.locator('li')).toHaveCount(2);
    await expect(productSurface.locator('strong')).toHaveText('Visible');

    await productSurface.click();
    await expect(page.locator('#beply-rich-desc-modal')).toHaveClass(/show/);
    await page.locator('[data-beply-rich-mode]').selectOption('markdown');
    await page.locator('.beply-rich-md').fill('Descripcion plana de producto');
    await page.locator('[data-beply-rich-save]').click();

    await expect(productTextarea).toBeVisible();
    await expect(productTextarea).toHaveValue('Descripcion plana de producto');
    await expect(productSurface).toHaveClass(/d-none/);

    await productTextarea.fill('Producto final\n- **Ficha** renderizada');
    await productTextarea.blur();
    await expect(productTextarea).toHaveClass(/d-none/);
    await expect(productSurface).toBeVisible();
    await expect(productSurface.locator('h4,h5,h6')).toHaveCount(0);
    await expect(productSurface.locator('p').first()).toHaveText('Producto final');
    await expect(productSurface.locator('strong')).toHaveText('Ficha');
  });

  test('enhances document observations and style legal text with the same modal', async ({ page }) => {
    await page.goto(baseURL, { waitUntil: 'networkidle' });

    const observationsTextarea = page.locator('textarea[name="observaciones"]');
    const observationsSurface = page.locator('[data-beply-rich-for="observaciones"]');
    const observationsButton = page.locator('[data-beply-rich-open="observaciones"]');
    await expect(observationsButton).toBeVisible();
    await expect(observationsButton.locator('xpath=ancestor::*[contains(@class, "beply-rich-inline-actions")]')).toHaveCSS('position', 'absolute');
    await expect(observationsTextarea).toHaveClass(/d-none/);
    await expect(observationsSurface).toBeVisible();
    await expect(observationsSurface.locator('p').first()).toHaveText('Notas internas');
    await expect(observationsSurface.locator('strong')).toHaveText('Incluye');
    await expect(observationsSurface.locator('em')).toHaveText('documentada');

    await observationsButton.click();
    await expect(page.locator('[data-beply-rich-title]')).toHaveText('Observaciones');
    await page.locator('[data-beply-rich-mode]').selectOption('markdown');
    await page.locator('.beply-rich-md').fill('Observacion sencilla');
    await page.locator('[data-beply-rich-save]').click();
    await expect(observationsTextarea).toBeVisible();
    await expect(observationsTextarea).toHaveValue('Observacion sencilla');
    await expect(observationsSurface).toHaveClass(/d-none/);

    const legalTextarea = page.locator('textarea[name="footer_text"]');
    const legalSurface = page.locator('[data-beply-rich-for="footer_text"]');
    const legalButton = page.locator('[data-beply-rich-open="footer_text"]');
    await expect(legalButton).toBeVisible();
    await expect(legalTextarea).toHaveClass(/d-none/);
    await expect(legalSurface).toBeVisible();
    await expect(legalSurface.locator('p').first()).toHaveText('Condiciones del servicio');
    await expect(legalSurface.locator('em')).toHaveText('post-entrega');

    await legalButton.click();
    await expect(page.locator('[data-beply-rich-title]')).toHaveText('Texto final / legal');
    await page.locator('[data-beply-rich-mode]').selectOption('markdown');
    await page.locator('.beply-rich-md').fill('Contrato\n- **Confidencialidad**\n- Soporte *incluido*');
    await page.locator('[data-beply-rich-save]').click();
    await expect(legalTextarea).toHaveClass(/d-none/);
    await expect(legalTextarea).toHaveValue('Contrato\n- **Confidencialidad**\n- Soporte *incluido*');
    await expect(legalSurface.locator('strong')).toHaveText('Confidencialidad');
    await expect(legalSurface.locator('em')).toHaveText('incluido');
  });
});
