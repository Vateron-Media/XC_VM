import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { TAG, adminApi, submitForm, tableRows } from './support';

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
  const button = tr.locator(`button[name="cluster_action"][value="${action}"]`).first();
  // The row's actions sit in its menu: open it first.
  if (!(await button.isVisible())) {
    await tr.locator('[data-bs-toggle="dropdown"]').click();
  }
  await button.click();
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

export const health = async (tr: Locator): Promise<string> => (await tr.locator('td[data-col="health"]').getAttribute('data-health')) ?? '';
export const flowOn = async (tr: Locator, flow: string): Promise<boolean> => (await tr.locator(`button[value="${flow}_off"]`).count()) > 0;
/** The node's cluster mode, as the forms of its mode cell name it. */
export const mode = async (tr: Locator): Promise<number> => Number(await tr.locator('input[name="mode"]').first().inputValue());
export const epochCell = (tr: Locator): Locator => tr.locator('td[data-col="epoch"]');
export const epoch = async (tr: Locator): Promise<number> => Number((await epochCell(tr).innerText()).trim().split(/\s+/)[0]);
/** The node's command queue: its badge. */
export const queued = async (tr: Locator): Promise<number> => Number((await tr.locator('td[data-col="queue"]').innerText()).trim());
/** The node's last heartbeat as the page shows it (UTC, to the second). */
export const lastSeen = async (tr: Locator): Promise<string> =>
  ((await tr.locator('td[data-col="last-seen"]').innerText()).match(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/) ?? [''])[0];

/** The load balancer's server name, as the row's link shows it. */
export async function lbName(page: Page): Promise<string> {
  return (await (await row(page)).locator('td[data-col="name"] a').first().innerText()).trim();
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

/**
 * A download kept open as a player keeps it: read a chunk at a time, slowly,
 * so the daemon is still writing it while the test looks (a reader that stops
 * altogether is dropped at the daemon's write deadline, as a stalled viewer).
 * Null until it is served; `first` is the first chunk it read.
 */
export async function hold(url: string): Promise<{ status: number; type: string; first: Uint8Array; abort: AbortController } | null> {
  const abort = new AbortController();
  try {
    const resp = await fetch(url, { redirect: 'follow', signal: abort.signal });
    const type = resp.headers.get('content-type') ?? '';
    if (resp.status !== 200 || /text\/html/i.test(type) || !resp.body) {
      abort.abort();
      return null;
    }
    const reader = resp.body.getReader();
    const first = (await reader.read()).value ?? new Uint8Array();
    void (async () => {
      while (!abort.signal.aborted) {
        const r = await reader.read().catch(() => ({ done: true }));
        if (r.done) {
          break;
        }
        await new Promise((res) => setTimeout(res, 400));
      }
    })();
    return { status: resp.status, type, first, abort };
  } catch {
    abort.abort();
    return null;
  }
}

/**
 * A live channel on the load balancer from `source`, in `bouquet`, its
 * timeshift recorded there (a day kept) and, with `thumbnails`, its
 * thumbnails taken there: its stream id.
 */
export async function addArchiveChannel(page: Page, c: { name: string; bouquet: string; source: string; thumbnails?: boolean }): Promise<number> {
  await page.goto('./stream');
  await page.locator('#stream_display_name').fill(c.name);
  await page.locator('#notes').fill(TAG);
  await page.locator('#bouquets').selectOption({ label: c.bouquet }, { force: true });
  await page.getByRole('tab', { name: /sources/i }).click();
  await page.locator('input[name="stream_source[]"]').first().fill(c.source);
  await page.getByRole('tab', { name: /^servers$/i }).click();
  await page.evaluate((node) => (window as any).$('#server_tree').jstree('move_node', String(node), 'source', 'last'), lb);
  await page.locator('#tv_archive_server_id').selectOption(String(lb), { force: true });
  await page.locator('#tv_archive_duration').fill('1');
  if (c.thumbnails) {
    await page.locator('#vframes_server_id').selectOption(String(lb), { force: true });
  }
  await submitForm(page, page, 'stream', page.locator('#stream-submit'));
  await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
  const r = (await tableRows(page.request, 'streams', c.name)).find((x) => x.title === c.name);
  expect(r, `${c.name} is listed`).toBeTruthy();
  return Number(r.id);
}

/** The archive's first full minute as MAIN names timeshift starts, for a channel started at `startedAt`: tried in UTC and an hour either side. */
export function timeshiftStarts(startedAt: number): string[] {
  const at = new Date(startedAt + 90_000);
  const pad = (n: number) => String(n).padStart(2, '0');
  const fmt = (d: Date) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}:${pad(d.getUTCHours())}-${pad(d.getUTCMinutes())}`;
  return [fmt(at), fmt(new Date(at.getTime() + 3_600_000)), fmt(new Date(at.getTime() - 3_600_000))];
}
