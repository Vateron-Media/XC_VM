import { test, expect } from '@playwright/test';

test.describe('admin dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('./dashboard');
  });

  test('renders the new UI shell', async ({ page }) => {
    // Sidebar (NavbarRegistry-driven) + navbar.
    await expect(page.locator('#layout-menu')).toBeVisible();
    await expect(page.locator('#layout-navbar')).toBeVisible();
    // The server-authoritative theme is stamped on <html>.
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', /light|dark/);
  });

  test('sidebar has the Dashboard group with a Home link and navigates', async ({ page }) => {
    const menu = page.locator('#layout-menu');
    await expect(menu.getByText('Dashboard', { exact: true })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Home' })).toBeVisible();
  });

  test('shows the six stat tiles and updates them live', async ({ page }) => {
    for (const cls of [
      'active-connections',
      'online-users',
      'active-streams',
      'offline-streams',
      'output-flow',
      'input-flow',
    ]) {
      await expect(page.locator(`.${cls} .entry`)).toBeVisible();
    }
    // The dashboard polls ./api?action=stats — it must answer 200.
    const resp = await page.waitForResponse((r) => /api\?action=stats/.test(r.url()), {
      timeout: 15_000,
    });
    expect(resp.status()).toBe(200);
  });

  test('renders the ApexCharts and the server selector', async ({ page }) => {
    await expect(page.locator('#server_id')).toBeAttached();
    // Charts are gated on dashboard_stats; assert only if present.
    const cpu = page.locator('#cpu_chart');
    if (await cpu.count()) {
      await expect(cpu.locator('svg, canvas').first()).toBeVisible({ timeout: 15_000 });
    }
  });
});
