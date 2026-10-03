import { test, expect, type Page } from '@playwright/test';
import { TAG, adminApi, ident, listRow, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';
import { lb, lbName } from './cluster-support';

/**
 * An admin's kill reaches an HLS viewer on a load balancer at once. The
 * viewer plays as a player does: it asks for its playlist every 2 s and
 * fetches the newest segment it lists. Once the Live Connections page kills
 * the connection, neither its playlist nor its segments are served any more
 * (within a few seconds), on a load balancer in any mode and flows.
 *
 * Needs XC_E2E_LB_SERVER and XC_E2E_STREAM_SOURCE (a live source the load
 * balancer reaches); the caches can take minutes to pick the new line up.
 */

test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';
const origin = new URL(process.env.XC_E2E_BASE_URL || 'http://localhost/').origin;

const bouquet = uniq('lb-hlskill-bouquet');
const channel = uniq('lb-hlskill-channel');
const line = { username: ident('lbhlskill'), password: ident('lbhlskillpass') };
let channelID = 0;

const RUNNING = 1;
const sleep = (ms: number) => new Promise((res) => setTimeout(res, ms));

async function findRow(page: Page) {
  await page.goto('./streams');
  await searchTable(page, channel, '#streams-table_wrapper .dt-search input, .dt-search input');
  return rowWith(page.locator('#streams-table'), channel);
}

test.describe.serial('killing an HLS viewer on the load balancer', () => {
  test('a channel on the load balancer, in a bouquet, and a line', async ({ page }) => {
    test.setTimeout(240_000);
    const server = await lbName(page);
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);

    await page.goto('./stream');
    await page.locator('#stream_display_name').fill(channel);
    await page.locator('#notes').fill(TAG);
    await page.locator('#bouquets').selectOption({ label: bouquet }, { force: true });
    await page.getByRole('tab', { name: /sources/i }).click();
    await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);
    await page.getByRole('tab', { name: /^servers$/i }).click();
    await page.locator('#server_tree .jstree-anchor', { hasText: server }).click();
    await submitForm(page, page, 'stream', page.locator('#stream-submit'));
    await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
    const row = (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel);
    expect(row, 'the channel is listed').toBeTruthy();
    channelID = Number(row.id);

    await page.goto('./line');
    await page.locator('#username').fill(line.username);
    await page.locator('#password').fill(line.password);
    await page.locator('#max_connections').fill('2');
    await page.locator('#admin_notes').fill(TAG);
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
    await submitForm(page, page, 'line', page.locator('#line-submit'));
    await page.waitForURL(/lines/);

    const started = await rowAction(page, await findRow(page), 'start');
    expect(started?.result, `start answered ${JSON.stringify(started)}`).toBe(true);
    await expect
      .poll(async () => (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel)?.status, { timeout: 120_000, intervals: [2_000] })
      .toBe(RUNNING);
  });

  test('the Live Connections kill stops the HLS viewer at once, every time', async ({ page }) => {
    test.setTimeout(1_500_000);
    const results: string[] = [];
    let failed = false;
    for (let cycle = 1; cycle <= 3; cycle++) {
      // A player connects through MAIN (the caches take a new line at their next passes).
      // Until MAIN's caches know the channel runs, MAIN answers itself with its
      // off-air video's playlist: only one MAIN sends on to the load balancer counts.
      let playlistURL = '';
      await expect
        .poll(async () => {
          const r = await fetch(`${origin}/live/${line.username}/${line.password}/${channelID}.m3u8`, { redirect: 'follow' });
          const text = await r.text();
          playlistURL = r.url;
          return r.status === 200 && r.redirected && text.includes('#EXTINF') ? 'served' : `${r.status}${r.redirected ? '' : ' from MAIN'} ${text.slice(0, 80)}`;
        }, { timeout: cycle === 1 ? 780_000 : 120_000, intervals: [cycle === 1 ? 15_000 : 3_000] })
        .toBe('served');

      // It asks for the playlist every 2 s and fetches the newest segment it lists.
      // Only a real playlist counts (an error page can come back as 200 in debug
      // mode), and only MPEG-TS bytes count as a segment.
      const timeline: { t: number; what: string; ok: boolean; status: number }[] = [];
      let segment = '';
      let stop = false;
      const player = (async () => {
        while (!stop) {
          const pl = await fetch(playlistURL, { redirect: 'follow' }).catch(() => null);
          const text = pl ? await pl.text() : '';
          const real = pl?.status === 200 && text.includes('#EXTM3U') && text.includes('#EXTINF');
          timeline.push({ t: Date.now(), what: 'playlist', ok: real, status: pl?.status ?? 0 });
          if (real) {
            const listed = text.split('\n').filter((l) => l.trim() && !l.startsWith('#'));
            segment = new URL(listed[listed.length - 1].trim(), pl!.url).toString();
          }
          if (segment) {
            const sg = await fetch(segment, { redirect: 'follow' }).catch(() => null);
            const body = sg ? Buffer.from(await sg.arrayBuffer().catch(() => new ArrayBuffer(0))) : Buffer.alloc(0);
            timeline.push({ t: Date.now(), what: 'segment', ok: sg?.status === 200 && body.length >= 188 && body[0] === 0x47, status: sg?.status ?? 0 });
          }
          await sleep(2_000);
        }
      })();

      const story = (from: number) => timeline.filter((e) => e.t > from).map((e) => `${((e.t - from) / 1000).toFixed(1)}s ${e.what} ${e.ok ? 'ok' : e.status}`).join(' | ');
      try {
        await expect
          .poll(() => timeline.some((e) => e.what === 'segment' && e.ok), { timeout: 60_000, intervals: [1_000], message: `cycle ${cycle}: segments are served before the kill` })
          .toBe(true);
        let uuid = '';
        await expect
          .poll(async () => {
            const rows = (await tableRows(page.request, 'live_connections', line.username)).filter((r) => String(r.container).toUpperCase() === 'HLS');
            uuid = rows[0]?.uuid ?? '';
            return uuid;
          }, { timeout: 120_000, intervals: [3_000], message: `cycle ${cycle}: the HLS viewer is listed in Live Connections` })
          .not.toBe('');

        // Past the token's create window (create_expiration, 5 s): within it a
        // closed viewer's next playlist request is a new connection by design (a
        // kill is not a ban), and an admin's kill comes long after a viewer joins.
        await sleep(8_000);
        const killedAt = Date.now();
        expect((await adminApi(page.request, 'line_activity', { sub: 'kill', pid: uuid }))?.result, 'the kill').toBe(true);
        await sleep(20_000);
        stop = true;
        await player;
        const after = timeline.filter((e) => e.t > killedAt);
        const lastPlaylist = after.filter((e) => e.what === 'playlist' && e.ok).pop();
        const lastSegment = after.filter((e) => e.what === 'segment' && e.ok).pop();
        const stoppedIn = Math.max(lastPlaylist ? lastPlaylist.t - killedAt : 0, lastSegment ? lastSegment.t - killedAt : 0);
        failed ||= stoppedIn >= 5_000;
        results.push(`cycle ${cycle}: ${stoppedIn < 5_000 ? 'stopped' : 'STILL SERVED'} (${story(killedAt)})`);
      } finally {
        stop = true;
        await player;
      }
    }
    expect(failed, `the HLS viewer stops within 5 s of the kill, each time:\n${results.join('\n')}`).toBe(false);
  });

  test('delete the line, channel and bouquet', async ({ page }) => {
    await rowAction(page, await findRow(page), 'stop');
    const [l] = (await tableRows(page.request, 'lines', line.username)).filter((r) => r.username === line.username);
    expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: l.id }))?.result).toBe(true);
    const deleted = await rowAction(page, await findRow(page), 'delete', { confirm: true });
    expect(deleted?.result, `delete answered ${JSON.stringify(deleted)}`).toBe(true);
    await page.goto('./bouquets');
    const id = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
  });
});
