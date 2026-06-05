const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const baseURL = (process.env.BEPLY_FS_URL || 'http://46.224.63.98:8013').replace(/\/+$/, '');
const user = process.env.BEPLY_FS_USER || 'admin';
const password = process.env.BEPLY_FS_PASSWORD || '';
const evidenceDir = process.env.BEPLY_PDF_EVIDENCE_DIR
  || path.resolve(__dirname, '../../docs/testing/evidencias/playwright-visual-compat');

function ensureEvidenceDir() {
  fs.mkdirSync(evidenceDir, { recursive: true });
}

async function login(page) {
  await page.goto(baseURL, { waitUntil: 'domcontentloaded' });
  const userInput = page.locator('input[name="fsNick"]').first();
  if (await userInput.count()) {
    if (!password) {
      throw new Error('BEPLY_FS_PASSWORD is required to login in FacturaScripts');
    }
    await userInput.fill(user);
    await page.locator('input[name="fsPassword"]').first().fill(password);
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
  }
}

async function capturePage(page, name) {
  ensureEvidenceDir();
  const file = path.join(evidenceDir, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function pageFacts(page) {
  return await page.evaluate(() => {
    const images = Array.from(document.images).map((img) => ({
      alt: img.getAttribute('alt') || '',
      src: img.getAttribute('src') || '',
      width: img.naturalWidth || img.width || 0,
      height: img.naturalHeight || img.height || 0,
    }));
    const beplyDesignInputs = Array.from(document.querySelectorAll('input[name="diseno"]'))
      .map((input) => input.getAttribute('value') || '')
      .filter(Boolean);
    const templateInputs = Array.from(document.querySelectorAll('input[value^="Template"], input[name*="template" i]'))
      .map((input) => ({
        name: input.getAttribute('name') || '',
        value: input.getAttribute('value') || '',
        type: input.getAttribute('type') || '',
      }));
    return {
      title: document.title,
      url: location.href,
      h1: Array.from(document.querySelectorAll('h1,h2,h3')).map((el) => el.textContent.trim()).filter(Boolean),
      images,
      beplyDesignInputs,
      templateInputs,
      textSample: document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 2000),
    };
  });
}

test.describe('BeplyPDFStudio visual compatibility evidence', () => {
  test.use({ viewport: { width: 1440, height: 1100 } });

  test('captures observable Beply and PlantillasPDF visual baselines', async ({ page }) => {
    ensureEvidenceDir();
    await login(page);

    await page.goto(`${baseURL}/AdminBeplyPdf`, { waitUntil: 'networkidle' });
    await expect(page.locator('body')).not.toContainText('Iniciar sesión');
    await expect(page.locator('body')).toContainText(/Beply|PDF/i);
    await capturePage(page, 'beplypdfstudio-gallery');
    const beplyFacts = await pageFacts(page);
    const beplyCompatRefs = [
      'Standard',
      'Summary',
      'Boxes',
      'Framed',
      'Banner',
    ].filter((label) => beplyFacts.textSample.includes(label));

    await page.goto(`${baseURL}/AdminPlantillasPDF`, { waitUntil: 'networkidle' });
    await expect(page.locator('body')).not.toContainText('Iniciar sesión');
    await capturePage(page, 'plantillaspdf-observable-ui');
    const legacyFacts = await pageFacts(page);

    const legacyTemplateRefs = [
      ...legacyFacts.templateInputs.map((item) => item.value),
      ...legacyFacts.images.map((item) => item.src),
    ].filter((value) => /Template[1-5]|template[1-5]\.(png|webp|jpg|jpeg)/i.test(value));

    fs.writeFileSync(
      path.join(evidenceDir, 'visual-compat-facts.json'),
      JSON.stringify({
        capturedAt: new Date().toISOString(),
        baseURL,
        beply: beplyFacts,
        beplyCompatRefs,
        legacy: legacyFacts,
        legacyTemplateRefs,
      }, null, 2)
    );

    expect(beplyCompatRefs.length).toBe(5);
    expect(legacyTemplateRefs.length).toBeGreaterThanOrEqual(5);
  });

  test('translates Beply line editor select value slugs', async ({ page }) => {
    await login(page);

    await page.goto(`${baseURL}/EditBeplyPdfStyle?code=9&activetab=BpsLineas`, { waitUntil: 'networkidle' });
    await expect(page.locator('body')).not.toContainText('Iniciar sesión');
    await expect(page.locator('body')).toContainText('Orden');
    await expect(page.locator('body')).toContainText('Campo');
    await expect(page.locator('body')).toContainText('Alineación');
    await expect(page.locator('body')).toContainText('Tipo');
    await expect(page.locator('body')).toContainText('Ancho');

    const selectedOptions = await page.evaluate(() => Array.from(document.querySelectorAll('select option:checked, select option[selected]'))
      .map((option) => option.textContent.trim())
      .filter(Boolean));
    expect(selectedOptions).toContain('Descripción');
    expect(selectedOptions).toContain('Izquierda');
    expect(selectedOptions).toContain('Texto');

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('line-field-description');
    expect(bodyText).not.toContain('align-left');
    expect(bodyText).not.toContain('type-text');
    await capturePage(page, 'beplypdfstudio-line-editor-i18n');
  });

  test('uses Beply-owned formats screen without opening core format editor', async ({ page }) => {
    await login(page);

    await page.goto(`${baseURL}/AdminBeplyPdf?activetab=ListFormatoDocumento`, { waitUntil: 'networkidle' });
    await expect(page.locator('body')).not.toContainText('Iniciar sesión');
    await expect(page.locator('body')).toContainText(/Esta pantalla no modifica|This screen does not modify/);

    const runtimeRefs = await page.evaluate(() => Array.from(document.querySelectorAll('a, form, button'))
      .map((el) => [
        el.getAttribute('href') || '',
        el.getAttribute('action') || '',
        el.getAttribute('onclick') || '',
        el.getAttribute('formaction') || '',
      ].join(' '))
      .join('\n'));

    expect(runtimeRefs).not.toContain('EditFormatoDocumento');
    await expect(page.locator('button').filter({ hasText: /Personalizar diseño|Configurar|Personalize design|Configure/ }).first())
      .toBeVisible();
    await capturePage(page, 'beplypdfstudio-formats-owned-screen');
  });
});
