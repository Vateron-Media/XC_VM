import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { TAG, adminApi, ident, listRow, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';
import { addArchiveChannel, hold, lb, serverView, timeshiftStarts } from './cluster-support';

/**
 * Files a load balancer serves through its xc_fanout daemon (ADR 0004, "VOD and
 * timeshift bytes in the daemon"): a direct-proxy movie the daemon relays from
 * its source, a movie the load balancer holds, and a channel's timeshift (its
 * TS and its HLS). Each viewer comes through MAIN, gets the right bytes (a
 * range too), and the load balancer's daemon counts it while it downloads: the
 * PHP-FPM worker that admitted it has returned.
 *
 * Needs XC_E2E_LB_SERVER (its TELEMETRY flow on), XC_E2E_VOD_SOURCE (an MP4
 * both servers reach, served as video/mp4 with ranges, far larger than the
 * TCP buffers between the load balancer and the test runner: they took a
 * 30 MB file whole, so the daemon had finished before its count was read)
 * and XC_E2E_STREAM_SOURCE (the timeshift channel's live source).
 */

test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

const VOD_SOURCE = process.env.XC_E2E_VOD_SOURCE || 'https://archive.org/download/Sintel/sintel-2048-stereo.mp4';
const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';
const origin = new URL(process.env.XC_E2E_BASE_URL || 'http://localhost/').origin;

const bouquet = uniq('lb-files-bouquet');
const viewer = { username: ident('lbfiles'), password: ident('lbfilespass') };
type Movie = { name: string; direct: boolean; id: number };
const movies: Record<'proxy' | 'local', Movie> = {
  proxy: { name: uniq('lb-file-proxy'), direct: true, id: 0 },
  local: { name: uniq('lb-file-local'), direct: false, id: 0 },
};
const channel = { name: uniq('lb-file-archive'), id: 0, startedAt: 0 };

/** The load balancer's daemon's viewers, as its agent last reported them; null without a report. */
async function daemonViewers(request: APIRequestContext): Promise<number | null> {
  const c = (await serverView(request, lb)).watchdog?.fanout?.connections;
  return typeof c === 'number' ? c : null;
}

/** Bytes [from, to] of the source itself, to compare with what the viewer got. */
async function sourceBytes(from: number, to: number): Promise<Buffer> {
  const resp = await fetch(VOD_SOURCE, { headers: { Range: `bytes=${from}-${to}` } });
  return Buffer.from(await resp.arrayBuffer());
}

/** A ranged read through MAIN: status, type, body and the file's whole size (Content-Range, 0 without). */
async function ranged(url: string, from: number, to: number): Promise<{ status: number; type: string; body: Buffer; total: number }> {
  const resp = await fetch(url, { redirect: 'follow', headers: { Range: `bytes=${from}-${to}` } });
  const total = Number(/\/(\d+)$/.exec(resp.headers.get('content-range') ?? '')?.[1] ?? 0);
  return { status: resp.status, type: resp.headers.get('content-type') ?? '', body: Buffer.from(await resp.arrayBuffer()), total };
}

/** Held open, the download is one of the daemon's viewers (its agent reports every few seconds). */
async function countedByTheDaemon(request: APIRequestContext, url: string, what: string): Promise<void> {
  await expect.poll(() => daemonViewers(request), { timeout: 90_000, intervals: [3_000], message: 'the daemon idle before this viewer' }).toBe(0);
  const h = await hold(url);
  expect(h, `${what} is served`).not.toBeNull();
  try {
    await expect.poll(async () => (await daemonViewers(request)) ?? 0, { timeout: 150_000, intervals: [3_000], message: `${what}: the load balancer's daemon counts the download (its agent reports within a minute)` }).toBeGreaterThan(0);
  } finally {
    h?.abort.abort();
  }
}

async function addMovie(page: Page, m: Movie): Promise<void> {
  await page.goto('./movie');
  await page.locator('#stream_display_name').fill(m.name);
  await page.locator('#stream_source').fill(VOD_SOURCE);
  await page.locator('#bouquets').selectOption({ label: bouquet }, { force: true });
  await page.locator('[data-bs-target="#tab-advanced"]').click();
  await page.locator('#target_container').selectOption('mp4', { force: true });
  if (m.direct) {
    await page.locator('#direct_source').check({ force: true });
    await page.locator('#direct_proxy').check({ force: true });
  }
  await page.locator('[data-bs-target="#tab-server"]').click();
  await page.evaluate((node) => (window as any).$('#server_tree').jstree('move_node', String(node), 'source', 'last'), lb);
  await submitForm(page, page, 'movie', page.locator('#movie-submit'));
  await page.waitForURL(/\/(stream_view\?id=\d+|movie\?id=\d+|movies)/, { waitUntil: 'commit' });
  const r = (await tableRows(page.request, 'movies', m.name)).find((x) => x.name === m.name || x.title === m.name || String(x.stream_display_name ?? '').includes(m.name));
  expect(r, `${m.name} is listed`).toBeTruthy();
  m.id = Number(r.id);
}

const movieURL = (m: Movie) => `${origin}/movie/${viewer.username}/${viewer.password}/${m.id}.mp4`;

test.describe.serial('files served by the load balancer\'s daemon', () => {
  test('a bouquet, a line, a direct-proxy movie, a movie on the load balancer and a channel recording there', async ({ page }) => {
    test.setTimeout(300_000);
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);
    await addMovie(page, movies.proxy);
    await addMovie(page, movies.local);
    channel.id = await addArchiveChannel(page, { name: channel.name, bouquet, source: SOURCE });

    await page.goto('./line');
    await page.locator('#username').fill(viewer.username);
    await page.locator('#password').fill(viewer.password);
    await page.locator('#max_connections').fill('4');
    await page.locator('#admin_notes').fill(TAG);
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
    await submitForm(page, page, 'line', page.locator('#line-submit'));
    await page.waitForURL(/lines/);

    // The movie the load balancer holds is fetched there; the channel records there.
    expect((await adminApi(page.request, 'movie', { sub: 'start', stream_id: movies.local.id, server_id: lb }))?.result).toBe(true);
    await page.goto('./streams');
    await searchTable(page, channel.name, '#streams-table_wrapper .dt-search input, .dt-search input');
    const started = await rowAction(page, await rowWith(page.locator('#streams-table'), channel.name), 'start');
    expect(started?.result, `start answered ${JSON.stringify(started)}`).toBe(true);
    channel.startedAt = Date.now();
  });

  for (const kind of ['proxy', 'local'] as const) {
    test(`a ${kind === 'proxy' ? 'direct-proxy movie, relayed from its source' : 'movie the load balancer holds'}: its bytes, a range, and the daemon serving it`, async ({ page }) => {
      test.setTimeout(900_000);
      const m = movies[kind];
      // MAIN's caches take the new line and movie, and the load balancer's copy
      // is whole: its size no longer grows (ffmpeg writes it as it downloads).
      let size = -1;
      await expect
        .poll(async () => {
          const r = await ranged(movieURL(m), 0, 0);
          const settled = r.status === 206 && r.total > 0 && r.total === size;
          size = r.status === 206 ? r.total : -1;
          return settled;
        }, { timeout: 780_000, intervals: [15_000], message: `${m.name} is never served whole` })
        .toBe(true);

      const got = await ranged(movieURL(m), 1_000_000, 1_000_999);
      expect(got.status).toBe(206);
      expect(got.type).toMatch(/video\/mp4/);
      if (m.direct) {
        expect(got.body.equals(await sourceBytes(1_000_000, 1_000_999)), 'the range is the source\'s').toBe(true);
      } else {
        // The load balancer's copy is ffmpeg's remux (+faststart), not the
        // source's bytes: an MP4, whose ranges agree with each other.
        expect((await ranged(movieURL(m), 0, 11)).body.subarray(4, 8).toString(), 'an MP4 (ftyp first)').toBe('ftyp');
        const wider = await ranged(movieURL(m), 999_000, 1_001_999);
        expect(got.body.equals(wider.body.subarray(1_000, 2_000)), 'the range is the same bytes as a wider one').toBe(true);
      }

      await countedByTheDaemon(page.request, movieURL(m), m.name);
    });
  }

  test('the channel\'s timeshift: its TS and its HLS, the TS counted by the daemon', async ({ page }) => {
    test.setTimeout(900_000);
    // A few archive minutes first.
    const wait = channel.startedAt + 240_000 - Date.now();
    if (wait > 0) {
      await page.waitForTimeout(wait);
    }
    let url = '';
    await expect
      .poll(async () => {
        for (const start of timeshiftStarts(channel.startedAt)) {
          const candidate = `${origin}/timeshift/${viewer.username}/${viewer.password}/2/${start}/${channel.id}.ts`;
          const h = await hold(candidate);
          if (h) {
            h.abort.abort();
            url = candidate;
            return 'served';
          }
        }
        return 'not yet';
      }, { timeout: 600_000, intervals: [15_000], message: 'the timeshift is never served' })
      .toBe('served');
    await countedByTheDaemon(page.request, url, 'the timeshift');

    // HLS: a playlist of archive segments, each served (by the daemon, uncounted per segment).
    const pl = await fetch(url.replace(/\.ts$/, '.m3u8'), { redirect: 'follow' });
    expect(pl.status).toBe(200);
    const text = await pl.text();
    const seg = text.split('\n').find((l) => l.includes('/hls/'));
    expect(seg, 'the playlist lists archive segments').toBeTruthy();
    const segURL = new URL(seg!.trim(), pl.url).toString();
    const s = await fetch(segURL, { redirect: 'follow' });
    expect(s.status).toBe(200);
    expect((await s.arrayBuffer()).byteLength, 'a segment carries bytes').toBeGreaterThan(1000);
  });

  test('delete the movies, the channel, the line and the bouquet', async ({ page }) => {
    test.setTimeout(300_000);
    await page.goto('./streams');
    await searchTable(page, channel.name, '#streams-table_wrapper .dt-search input, .dt-search input');
    await rowAction(page, await rowWith(page.locator('#streams-table'), channel.name), 'stop');
    const [l] = (await tableRows(page.request, 'lines', viewer.username)).filter((r) => r.username === viewer.username);
    expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: l.id }))?.result).toBe(true);
    for (const m of Object.values(movies)) {
      expect((await adminApi(page.request, 'movie', { sub: 'delete', stream_id: m.id, server_id: -1 }))?.result, `${m.name} deleted`).toBe(true);
    }
    expect((await adminApi(page.request, 'stream', { sub: 'delete', stream_id: channel.id, server_id: -1 }))?.result, `${channel.name} deleted`).toBe(true);
    await page.goto('./bouquets');
    const id = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
  });
});
