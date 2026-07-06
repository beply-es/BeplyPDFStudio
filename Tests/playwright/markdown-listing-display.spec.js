const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const baseURL = (process.env.BEPLY_FS_URL || 'http://127.0.0.1:8013').replace(/\/+$/, '');
const workspacePassFile = path.resolve(__dirname, '../../../../pass.txt');

function localCredentials() {
  if (!fs.existsSync(workspacePassFile)) {
    return { user: '', password: '' };
  }

  const [user = '', password = ''] = fs.readFileSync(workspacePassFile, 'utf8').trim().split(/\r?\n/);
  return { user, password };
}

const localAuth = localCredentials();
const user = process.env.BEPLY_FS_USER || localAuth.user || 'admin';
const password = process.env.BEPLY_FS_PASSWORD || localAuth.password;

async function login(page) {
  await page.goto(baseURL, { waitUntil: 'domcontentloaded' });

  const userInput = page.locator('input[name="fsNick"]').first();
  if (!await userInput.count()) {
    return;
  }

  if (!password) {
    throw new Error('BEPLY_FS_PASSWORD is required to login in FacturaScripts');
  }

  await userInput.fill(user);
  await page.locator('input[name="fsPassword"]').first().fill(password);
  await page.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');
}

async function searchTab(page, url, tab, query) {
  await page.goto(`${baseURL}${url}`, { waitUntil: 'networkidle' });

  const form = page.locator(`form:has(input[name="activetab"][value="${tab}"])`).first();
  await expect(form).toBeVisible();

  const queryInput = form.locator('input[name="query"]').first();
  await queryInput.fill(query);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    queryInput.press('Enter'),
  ]);

  return page.locator('table tbody tr').filter({ hasText: query }).first();
}

test.describe('Markdown display in core listings', () => {
  test('renders product descriptions and document observations as clean text in search results', async ({ page }) => {
    await login(page);

    const productRow = await searchTab(page, '/ListProducto', 'ListProducto', 'test');
    await expect(productRow).toBeVisible();
    await expect(productRow).toContainText('Producto de prueba');
    await expect(productRow).toContainText('GRATIS');
    const productText = await productRow.innerText();
    expect(productText).not.toContain('**GRATIS**');
    expect(productText).not.toContain('*ESPAÑA*');
    expect(productText).not.toContain('**');

    const invoiceRow = await searchTab(
      page,
      '/ListFacturaCliente?activetab=ListFacturaCliente',
      'ListFacturaCliente',
      'FAC2026A6'
    );
    await expect(invoiceRow).toBeVisible();
    await expect(invoiceRow).toContainText('Notas de entrega');
    await expect(invoiceRow).toContainText('consultoría');
    const invoiceText = await invoiceRow.innerText();
    expect(invoiceText).not.toContain('**');
    expect(invoiceText).not.toContain('*consultoría*');
  });
});
