import { test, expect } from '@playwright/test';

/**
 * The customizer persists each user's settings to users.ui_prefs via
 * ./api?action=save_ui_prefs, and the shell restores them on load. These tests
 * drive the navbar theme switcher (which routes through the customizer) and
 * verify the choice survives a reload, then reset it.
 */
test.describe('Per-user customizer persistence', () => {
  test('theme change is saved and restored after reload', async ({ page }) => {
    await page.goto('./dashboard');

    // Pick the theme we are NOT currently on.
    const current = await page.locator('html').getAttribute('data-bs-theme');
    const target = current === 'dark' ? 'light' : 'dark';

    await page.locator('#nav-theme').click();
    const savePost = page.waitForResponse(
      (r) => /api\?action=save_ui_prefs/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 15_000 },
    );
    await page.locator(`[data-bs-theme-value="${target}"]`).click();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', target);
    expect((await savePost).status()).toBe(200);

    // Reload: the stored theme wins (server-authoritative, no flash back).
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', target);
  });

  test('reset clears the saved prefs', async ({ page }) => {
    await page.goto('./dashboard');
    // The reset button lives inside the (collapsed) customizer panel — open it first.
    await page.locator('.template-customizer-open-btn').click();
    const resetBtn = page.locator('.template-customizer-reset-btn');
    await expect(resetBtn).toBeVisible();
    const resetPost = page.waitForResponse(
      (r) => /api\?action=save_ui_prefs/.test(r.url()) && r.request().method() === 'POST',
      { timeout: 15_000 },
    );
    await resetBtn.click();
    // The reset fires a synchronous {__reset:true} POST before reloading.
    expect((await resetPost).status()).toBe(200);
  });
});
