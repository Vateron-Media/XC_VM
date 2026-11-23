import { test, expect, type Browser, type Page } from '@playwright/test';
import { TAG, ident, rowAction, rowWith, searchTable, submitForm, tableRows } from './support';

/**
 * Signing in and out. A successful admin login re-hashes the password, which
 * ends every other session of that account — including the shared one the rest
 * of the suite runs on. So this spec creates a second administrator, signs in
 * and out as that one in a browser context of its own, and deletes it.
 *
 * The wrong-password case costs one entry against the panel's login flood
 * limit (Settings → login_flood, failures per IP per day), so it runs once.
 */

const username = ident('admin');
const password = ident('adminpass');
// group_id 1 is the seeded Administrators group (bin/install/database.sql).
const ADMINISTRATORS = '1';

test.use({ viewport: { width: 1920, height: 1080 } });

/**
 * A page in a fresh, signed-out browser context. The test runner applies the
 * project's `use` options to browser.newContext() — the shared admin session
 * among them — so the storage state is emptied explicitly.
 */
async function signedOutPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  return context.newPage();
}

async function submitLogin(page: Page, user: string, pass: string) {
  await page.goto('./login');
  await page.locator('#username').fill(user);
  await page.locator('#password').fill(pass);
  await page.locator('#login_button').click();
}

test.describe.serial('administrator sign-in', () => {
  test('create a second administrator', async ({ page }) => {
    await page.goto('./user');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#member_group_id').selectOption(ADMINISTRATORS, { force: true });
    await page.locator('#notes').fill(TAG);
    await submitForm(page, page, 'user', page.locator('#user-submit'));
    await page.waitForURL(/users/);
    const rows = (await tableRows(page.request, 'reg_users', username)).filter((r) => r.username === username);
    expect(rows).toHaveLength(1);
    expect(rows[0].member_group_id).toBe(Number(ADMINISTRATORS));
  });

  test('pages need a session', async ({ browser }) => {
    const page = await signedOutPage(browser);
    await page.goto('./lines');
    await expect(page.locator('#login_button')).toBeVisible();
    await expect(page.locator('#layout-menu')).toHaveCount(0);
    await page.context().close();
  });

  test('a wrong password is refused with a message', async ({ browser }) => {
    const page = await signedOutPage(browser);
    await submitLogin(page, username, `wrong-${password}`);
    await expect(page.locator('.panel-alert')).toBeVisible();
    await expect(page.locator('#login_button')).toBeVisible();
    await expect(page.locator('#layout-menu')).toHaveCount(0);
    await page.context().close();
  });

  test('the new administrator signs in, then signs out', async ({ browser }) => {
    const page = await signedOutPage(browser);
    await submitLogin(page, username, password);
    await expect(page.locator('#layout-menu')).toBeVisible({ timeout: 30_000 });

    // The profile menu in the navbar holds the POST-only sign-out form.
    const profile = page.locator('.dropdown-user');
    await profile.locator('[data-bs-toggle="dropdown"]').first().click();
    await profile.locator('form[action$="logout"] button[type="submit"]').click();
    await expect(page.locator('#login_button')).toBeVisible();

    // The session is gone, not just the page.
    await page.goto('./dashboard');
    await expect(page.locator('#login_button')).toBeVisible();
    await page.context().close();
  });

  test('delete the second administrator', async ({ page }) => {
    await page.goto('./users');
    await searchTable(page, username, '#users-table_wrapper .dt-search input, .dt-search input');
    const result = await rowAction(page, await rowWith(page.locator('#users-table'), username), 'delete', { confirm: true });
    expect(result?.result).toBe(true);
    await expect
      .poll(async () => (await tableRows(page.request, 'reg_users', username)).filter((r) => r.username === username).length)
      .toBe(0);
  });
});
