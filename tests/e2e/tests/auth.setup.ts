import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';

const authFile = '.auth/admin.json';

/**
 * Logs into the admin panel once and stores the session for the browser
 * projects. The login form (Views/admin/login.php) posts username/password to
 * ./login; the access code is already part of XC_E2E_BASE_URL.
 */
setup('authenticate', async ({ page }) => {
  const user = process.env.XC_E2E_USER;
  const pass = process.env.XC_E2E_PASS;
  expect(user, 'set XC_E2E_USER').toBeTruthy();
  expect(pass, 'set XC_E2E_PASS').toBeTruthy();

  await page.goto('./login');
  await page.locator('#username').fill(user!);
  await page.locator('#password').fill(pass!);
  await page.locator('#login_button').click();

  // After login the panel redirects to the dashboard, whose sidebar only
  // renders once authenticated. Waiting on the element (rather than waitForURL's
  // 'load' event) is robust to the heavy dashboard being slow to fully load,
  // especially headed.
  await expect(page.locator('#layout-menu')).toBeVisible({ timeout: 30_000 });

  fs.mkdirSync('.auth', { recursive: true });
  await page.context().storageState({ path: authFile });
});
