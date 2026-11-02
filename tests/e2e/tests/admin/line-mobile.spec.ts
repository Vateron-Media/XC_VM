import { test, expect, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Exercise the shipped picker and expiry handler without creating a real line.
const root = path.resolve(__dirname, '../../../..');
const view = fs.readFileSync(path.join(root, 'src/Public/Views/admin/line.php'), 'utf8');
const expiryScript = view.slice(view.indexOf('        if (window.flatpickr)'), view.indexOf('        // Allowed-IP'));
const expiryInput = view.match(/<input[^>]+id="exp_date"[^\n]+/)![0]
  .replace(/value=".*$/, 'value="2031-02-03 04:05:37">');

test('mobile line expiry allows submitting changed seconds and disabling expiry', async ({ browser }) => {
  const context = await browser.newContext({ ...devices['iPhone 13'] });
  const page = await context.newPage();
  try {
    await page.setContent(`<form>${expiryInput}<input type="checkbox" id="no_expire"><button>Add</button></form>`);
    await page.addScriptTag({ path: path.join(root, 'src/Public/assets/admin/vendor/libs/flatpickr/flatpickr.js') });
    await page.addScriptTag({ content: expiryScript });
    await page.evaluate(() => {
      document.querySelector('form')!.addEventListener('submit', event => {
        event.preventDefault();
        document.body.dataset.saved = 'yes';
      });
    });
    const native = page.locator('.flatpickr-mobile');
    await expect(native).toBeVisible();
    await native.fill('2031-02-04T06:10');
    await page.locator('button').click();
    await expect(page.locator('body')).toHaveAttribute('data-saved', 'yes');
    await expect(page.locator('#exp_date')).toHaveValue('2031-02-04 06:10:00');
    await page.locator('#no_expire').check();
    await expect(native).toBeDisabled();
    await expect(page.locator('#exp_date')).toBeDisabled();
    await page.locator('#no_expire').uncheck();
    await expect(native).toBeEnabled();
  } finally {
    await context.close();
  }
});
