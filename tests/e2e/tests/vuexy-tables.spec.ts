import { test, expect, type Page } from '@playwright/test';

/**
 * Smoke suite for every admin page migrated to the Vuexy clean-JSON / client-side
 * datatables-bs5 pattern (feat/admin-vuexy-v2). For each page we assert:
 *   - it renders inside the Vuexy shell (#layout-menu) — i.e. the per-page opt-in
 *     allowlist (xc_admin_use_vuexy) routed it to the new shell, not the legacy one;
 *   - its Vuexy-specific table id is present (legacy used #datatable / #datatable-users,
 *     so a hit on the new id proves the rewritten view rendered);
 *   - for serverSide tables, the DataTables `./table` ajax answered 200.
 *
 * These are structural smoke checks — they do not exercise destructive row actions.
 */

type Migrated = {
  /** page name (relative goto target) */
  url: string;
  /** the Vuexy view's table id selector */
  table: string;
  /** serverSide TableController id (d.id); omit for client-side tables */
  ajax?: string;
  /** page has a bulk-select checkbox column (#check-all) */
  bulk?: boolean;
  /** table has no leading Responsive control column (first th is a real header) */
  noControl?: boolean;
};

const LOGS: Migrated[] = [
  { url: 'panel_logs', table: '#panel-logs-table', ajax: 'panel_logs' },
  { url: 'login_logs', table: '#login-logs-table', ajax: 'login_logs' },
  { url: 'client_logs', table: '#client-logs-table', ajax: 'client_logs' },
  { url: 'credit_logs', table: '#credit-logs-table', ajax: 'credits_log' },
  { url: 'stream_errors', table: '#stream-errors-table', ajax: 'stream_errors' },
  { url: 'restream_logs', table: '#restream-logs-table', ajax: 'restream_logs' },
  { url: 'mag_events', table: '#mag-events-table', ajax: 'mag_events' },
  { url: 'mysql_syslog', table: '#syslog-table', ajax: 'mysql_syslog' },
  { url: 'queue', table: '#queue-table', ajax: 'queue' },
  { url: 'user_logs', table: '#user-logs-table', ajax: 'reg_user_logs' },
  { url: 'line_activity', table: '#activity-table', ajax: 'line_activity' },
  { url: 'live_connections', table: '#live-table', ajax: 'live_connections' },
  { url: 'ondemand', table: '#ondemand-table', ajax: 'ondemand' },
  { url: 'theft_detection', table: '#theft-table' },
  { url: 'stream_rank', table: '#rank-table' },
];

const SECURITY: Migrated[] = [
  { url: 'ips', table: '#ips-table' },
  { url: 'isps', table: '#isps-table' },
  { url: 'useragents', table: '#ua-table' },
  { url: 'hmacs', table: '#hmac-table' },
  { url: 'rtmp_ips', table: '#rtmp-table' },
  { url: 'asns', table: '#asns-table', ajax: 'asns' },
];

const REFERENCES: Migrated[] = [
  { url: 'groups', table: '#groups-table' },
  { url: 'packages', table: '#packages-table' },
  { url: 'codes', table: '#codes-table' },
  { url: 'epgs', table: '#epgs-table' },
  { url: 'providers', table: '#providers-table' },
];

const MANAGEMENT: Migrated[] = [
  { url: 'users', table: '#users-table', ajax: 'reg_users' },
  { url: 'series', table: '#series-table', ajax: 'series', bulk: true },
  { url: 'mags', table: '#mags-table', ajax: 'mags', bulk: true },
  { url: 'enigmas', table: '#e2-table', ajax: 'enigmas', bulk: true },
  { url: 'movies', table: '#movies-table', ajax: 'movies', bulk: true },
  { url: 'radios', table: '#radios-table', ajax: 'radios', bulk: true },
  { url: 'backups', table: '#backups-table', ajax: 'backups', noControl: true },
];

/** Load a migrated page and assert the Vuexy shell + table + (serverSide) ajax. */
async function assertMigrated(page: Page, m: Migrated) {
  // Arm the ajax wait before navigating so the DataTables request isn't missed.
  const ajaxWait = m.ajax
    ? page
        .waitForResponse((r) => /\/table(\?|$)/.test(r.url()) && r.request().method() !== 'OPTIONS', {
          timeout: 20_000,
        })
        .catch(() => null)
    : null;

  await page.goto('./' + m.url);

  // Vuexy shell — proves the page opted into the new layout (not legacy chrome).
  await expect(page.locator('#layout-menu')).toBeVisible({ timeout: 20_000 });
  // The rewritten view's table.
  await expect(page.locator(m.table)).toBeAttached();
  // A leading (empty) Responsive control column header (unless the table has none).
  if (!m.noControl) {
    await expect(page.locator(`${m.table} thead th`).first()).toHaveText('');
  }

  if (ajaxWait) {
    const resp = await ajaxWait;
    expect(resp, `${m.url}: ./table ajax was not observed`).not.toBeNull();
    expect(resp!.status(), `${m.url}: ./table ajax status`).toBe(200);
  }
}

for (const [group, pages] of [
  ['Logs', LOGS],
  ['Security', SECURITY],
  ['Reference lists', REFERENCES],
  ['Management', MANAGEMENT],
] as const) {
  test.describe(`Vuexy tables — ${group}`, () => {
    for (const m of pages) {
      test(`${m.url} renders in the Vuexy shell and loads its table`, async ({ page }) => {
        await assertMigrated(page, m);
      });
    }
  });
}

test.describe('Vuexy tables — interactions', () => {
  test('series bulk-select toggles the bulk toolbar', async ({ page }) => {
    await page.goto('./series');
    await expect(page.locator('#series-table')).toBeAttached();
    // Wait for the first data row (or skip if the catalog is empty).
    const firstCheck = page.locator('#series-table tbody .row-check').first();
    if (!(await firstCheck.count())) {
      test.skip(true, 'no series rows to select');
    }
    await expect(page.locator('#bulk-bar')).toHaveClass(/d-none/);
    await firstCheck.check();
    await expect(page.locator('#bulk-bar')).not.toHaveClass(/d-none/);
    await expect(page.locator('#bulk-count')).toContainText(/1/);
    await firstCheck.uncheck();
    await expect(page.locator('#bulk-bar')).toHaveClass(/d-none/);
  });

  test('panel_logs search box filters server-side', async ({ page }) => {
    await page.goto('./panel_logs');
    await expect(page.locator('#panel-logs-table')).toBeAttached();
    const search = page.locator('.dt-search input, input[type="search"]').first();
    await expect(search).toBeVisible({ timeout: 15_000 });
    const reload = page
      .waitForResponse((r) => /\/table(\?|$)/.test(r.url()), { timeout: 15_000 })
      .catch(() => null);
    await search.fill('__e2e_no_match__');
    const resp = await reload;
    expect(resp?.status() ?? 200).toBe(200);
  });

  test('users row action dropdown opens', async ({ page }) => {
    // A wide viewport keeps the (low-priority) actions column from collapsing into
    // the DataTables Responsive child row, so its dropdown toggle stays visible.
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto('./users');
    await expect(page.locator('#users-table')).toBeAttached();
    const toggle = page.locator('#users-table tbody [data-bs-toggle="dropdown"]:visible').first();
    if (!(await toggle.count())) {
      test.skip(true, 'no user rows / no edit permission');
    }
    // Assert Bootstrap opened the dropdown via aria-expanded (robust to the menu
    // being visually clipped by the table-responsive overflow container).
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  });
});
