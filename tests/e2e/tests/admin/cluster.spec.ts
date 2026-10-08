import { test, expect } from '@playwright/test';

/**
 * The server pages an administrator reads before touching the cluster: the
 * servers list shows the main server, and the Cluster Nodes page renders.
 * Read-only: nothing is enrolled, revoked or changed.
 */

const server = process.env.XC_E2E_SERVER || 'Main Server';

test.describe('servers and cluster nodes', () => {
  test('the servers list shows the main server', async ({ page }) => {
    await page.goto('./servers');
    await expect(page.locator('#servers-table')).toBeVisible();
    await expect(page.locator('#servers-table tbody tr', { hasText: server }).first()).toBeVisible();
  });

  test('the Cluster Nodes page renders', async ({ page }) => {
    const resp = await page.goto('./cluster_nodes');
    expect(resp?.status(), 'cluster_nodes').toBe(200);
    // The nodes card's title, as Manage Servers titles its card.
    await expect(page.getByRole('heading', { name: 'Cluster Nodes', level: 5 })).toBeVisible();
    await expect(page.locator('#cluster-nodes-table')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught|Stack trace/);
  });
});
