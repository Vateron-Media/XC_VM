import { test, expect, devices, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Exercise the shipped pickers and handlers without creating real subscribers.
const root = path.resolve(__dirname, '../../../..');

async function expiryForm(page: Page, viewName: string, neverExpire = false): Promise<void> {
  const view = fs.readFileSync(path.join(root, `src/Public/Views/admin/${viewName}.php`), 'utf8');
  const expiryScript = view.slice(view.indexOf('        if (window.flatpickr)'), view.indexOf('        // Allowed-IP'));
  const expiryInput = view.match(/<input[^>]+id="exp_date"[^\n]+/)![0]
    .replace(/value=".*$/, 'value="2031-02-03 04:05:37">');
  const paired = viewName !== 'line';
  const pairScript = paired ? view.slice(view.indexOf('        var pairEl'), view.indexOf('        if ($ && pairEl)')) : '';
  await page.setContent(`<form>${expiryInput}<input type="checkbox" id="no_expire" ${neverExpire ? 'checked' : ''}>
    <select id="pair_id"><option value="">Unpaired</option><option value="1">Paired</option></select><button>Add</button></form>
    ${['linked_info', 'advanced_info', 'bouquets_info', 'advanced_warning', 'bouquet_warning'].map(id => `<div id="${id}"></div>`).join('')}`);
  await page.addScriptTag({ path: path.join(root, 'src/Public/assets/admin/vendor/libs/flatpickr/flatpickr.js') });
  await page.addScriptTag({ content: pairScript + expiryScript + (paired ? "pairEl.addEventListener('change', applyPair); applyPair();" : '') });
  await page.evaluate(() => {
    document.querySelector('form')!.addEventListener('submit', event => {
      event.preventDefault();
      const expiry = new FormData(event.target as HTMLFormElement).get('exp_date');
      document.body.dataset.savedExpiry = expiry === null ? 'omitted' : String(expiry);
    });
  });
}

for (const viewName of ['line', 'mag', 'enigma']) {
  for (const neverExpire of [false, true]) {
    test(`${viewName}: mobile expiry submits seconds and toggles never-expire (initially ${neverExpire})`, async ({ browser }) => {
      const context = await browser.newContext({ ...devices['iPhone 13'] });
      const page = await context.newPage();
      try {
        await expiryForm(page, viewName, neverExpire);
        const native = page.locator('.flatpickr-mobile');
        const named = page.locator('#exp_date');
        await expect(native).toBeVisible();
        if (neverExpire) {
          await expect(native).toBeDisabled();
          await expect(named).toBeDisabled();
          await page.locator('button').click();
          await expect(page.locator('body')).toHaveAttribute('data-saved-expiry', 'omitted');
          await page.locator('#no_expire').uncheck();
        }
        await expect(native).toBeEnabled();
        await expect(named).toBeEnabled();
        // Different seconds from the initial value catch the native default step.
        await native.fill('2031-02-04T06:10:42');
        await page.locator('button').click();
        await expect(page.locator('body')).toHaveAttribute('data-saved-expiry', '2031-02-04 06:10:42');
        await page.locator('#no_expire').check();
        await expect(native).toBeDisabled();
        await expect(named).toBeDisabled();
        await page.locator('button').click();
        await expect(page.locator('body')).toHaveAttribute('data-saved-expiry', 'omitted');
        await page.locator('#no_expire').uncheck();
        await expect(native).toBeEnabled();
        await expect(named).toBeEnabled();
        await page.locator('button').click();
        await expect(page.locator('body')).toHaveAttribute('data-saved-expiry', '2031-02-04 06:10:42');
      } finally {
        await context.close();
      }
    });
  }
  if (viewName !== 'line') {
    test(`${viewName}: pairing synchronizes mobile expiry and preserves never-expire on unpair`, async ({ browser }) => {
      const context = await browser.newContext({ ...devices['iPhone 13'] });
      const page = await context.newPage();
      try {
        await expiryForm(page, viewName);
        await page.locator('#pair_id').selectOption('1');
        await expect(page.locator('.flatpickr-mobile')).toBeDisabled();
        await expect(page.locator('#exp_date')).toBeDisabled();
        await page.locator('#pair_id').selectOption('');
        await expect(page.locator('.flatpickr-mobile')).toBeEnabled();
        await expect(page.locator('#exp_date')).toBeEnabled();
        await page.locator('#no_expire').check();
        await page.locator('#pair_id').selectOption('1');
        await page.locator('#pair_id').selectOption('');
        await expect(page.locator('.flatpickr-mobile')).toBeDisabled();
        await expect(page.locator('#exp_date')).toBeDisabled();
      } finally {
        await context.close();
      }
    });
  }
}
