import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { adminApi } from './support';

/**
 * The Cluster Nodes page's row of the load balancer the LB specs drive
 * (XC_E2E_LB_SERVER: its server id, enrolled in the cluster API), and the
 * helpers they share. Each spec skips itself without XC_E2E_LB_SERVER.
 */

export const lb = Number(process.env.XC_E2E_LB_SERVER || 0);

/** Open the page and return the node's row. */
export async function row(page: Page): Promise<Locator> {
  const resp = await page.goto('./cluster_nodes');
  expect(resp?.status(), 'cluster_nodes').toBe(200);
  const tr = page.locator(`#node-${lb}`);
  await expect(tr, `node ${lb} on the page`).toBeVisible();
  return tr;
}

/** Press one of the row's cluster_action buttons; the page posts and reloads. */
export async function act(page: Page, action: string): Promise<void> {
  const tr = await row(page);
  const posted = page.waitForResponse((r) => r.request().method() === 'POST' && /cluster_nodes/.test(r.url()));
  await tr.locator(`button[name="cluster_action"][value="${action}"]`).first().click();
  expect((await posted).status(), action).toBeLessThan(400);
  await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught|Stack trace/);
}

/** Reload the page until the row satisfies `ok`, or fail after `ms`. */
export async function until(page: Page, what: string, ok: (tr: Locator) => Promise<boolean>, ms = 120_000): Promise<void> {
  const end = Date.now() + ms;
  for (;;) {
    if (await ok(await row(page))) {
      return;
    }
    if (Date.now() > end) {
      throw new Error(`timed out waiting for: ${what}`);
    }
    await page.waitForTimeout(3000);
  }
}

export const health = async (tr: Locator): Promise<string> => (await tr.locator('td').nth(1).locator('.badge').first().innerText()).trim();
export const flowOn = async (tr: Locator, flow: string): Promise<boolean> => (await tr.locator(`button[value="${flow}_off"]`).count()) > 0;
/** The node's cluster mode, as the forms of its mode cell name it. */
export const mode = async (tr: Locator): Promise<number> => Number(await tr.locator('input[name="mode"]').first().inputValue());
export const epochCell = (tr: Locator): Locator => tr.locator('td').filter({ hasText: /\(gen \d+\)/ }).first();
export const epoch = async (tr: Locator): Promise<number> => Number((await epochCell(tr).innerText()).trim().split(/\s+/)[0]);
/** The node's command queue: the badge three cells after the epoch's. */
export const queued = async (tr: Locator): Promise<number> => Number((await epochCell(tr).locator('xpath=following-sibling::td[3]').innerText()).trim());
/** The node's last heartbeat as the page shows it (UTC, to the second), in the cell after the queue's. */
export const lastSeen = async (tr: Locator): Promise<string> =>
  ((await epochCell(tr).locator('xpath=following-sibling::td[4]').innerText()).match(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/) ?? [''])[0];

/** The load balancer's server name, as the page's first cell shows it. */
export async function lbName(page: Page): Promise<string> {
  return (await (await row(page)).locator('td').first().innerText()).split('\n')[0].trim();
}

/** Run `body` with the node's `flow` on, then put the flow back as it was. */
export async function withFlow(page: Page, flow: string, body: () => Promise<void>): Promise<void> {
  const wasOn = await flowOn(await row(page), flow);
  if (!wasOn) {
    await act(page, `${flow}_on`);
    await until(page, `${flow} on`, (tr) => flowOn(tr, flow), 30_000);
  }
  try {
    await body();
  } finally {
    if (!wasOn) {
      await act(page, `${flow}_off`);
    }
  }
}

/** The server page's live figures for a server (`api?action=server_view`): its watchdog data and counts. */
export async function serverView(request: APIRequestContext, serverID: number): Promise<{ watchdog: Record<string, any> | null; [k: string]: any }> {
  const body = await adminApi(request, 'server_view', { server_id: serverID });
  return body?.data ?? { watchdog: null };
}

/** A viewer of a live channel: its response, and its stream while it flows. */
export type Viewer = { status: number; type: string; reader: ReadableStreamDefaultReader<Uint8Array> | null; abort: AbortController };

/**
 * Served: the stream itself, still flowing, not a refusal. Production refuses
 * with a bare 404; with Settings → debug_show_errors on, with a 200 HTML page
 * naming the error (NOT_IN_BOUQUET until the caches take a new line). Until
 * MAIN's stream cache sees a channel running on the load balancer, it serves
 * its own not-on-air clip, a short MPEG-TS that ends at once.
 */
export const served = (v: Viewer | null): boolean => !!v && v.status === 200 && !/text\/html/i.test(v.type) && v.reader !== null;

/** Open a viewer of `url`: its first bytes read, the stream left open while it flows. */
export async function openViewer(url: string): Promise<Viewer> {
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), 30_000);
  try {
    const resp = await fetch(url, { redirect: 'follow', signal: abort.signal });
    const v: Viewer = { status: resp.status, type: resp.headers.get('content-type') ?? '', reader: null, abort };
    if (v.status === 200 && !/text\/html/i.test(v.type) && resp.body) {
      v.reader = resp.body.getReader();
      await v.reader.read();
      // A live stream keeps flowing; an off-air clip has ended by now.
      if (await endsWithin(v, 3_000)) {
        v.reader = null;
      }
    }
    if (!v.reader) {
      abort.abort();
    }
    return v;
  } finally {
    clearTimeout(timer);
  }
}

/** Does the viewer's stream end within `ms` (cut by the server)? False while it still flows. */
export async function endsWithin(v: Viewer, ms: number): Promise<boolean> {
  if (!v.reader) {
    return true;
  }
  const deadline = Date.now() + ms;
  try {
    for (;;) {
      const left = deadline - Date.now();
      if (left <= 0) {
        return false;
      }
      const r = await Promise.race([v.reader.read(), new Promise<null>((res) => setTimeout(() => res(null), left))]);
      if (r === null) {
        return false;
      }
      if (r.done) {
        return true;
      }
    }
  } catch {
    return true; // the connection was cut
  }
}
