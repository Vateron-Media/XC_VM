import { test, expect } from '@playwright/test';
import { TAG, adminApi, ident, listRow, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';
import { addArchiveChannel, hold, lb, timeshiftStarts } from './cluster-support';

/**
 * A copy-only channel on a load balancer runs on xc_fanout's native remuxer
 * (`xc_fanout remux`, not ffmpeg), and what the panel builds on the on-disk HLS
 * it writes works unchanged: the timeshift archive (its TS and its HLS) and the
 * thumbnail (Phase 13).
 *
 * Needs XC_E2E_LB_SERVER and XC_E2E_NATIVE_SOURCE: a source the load balancer
 * reads natively (MPEG-TS over http(s), or HLS with TS segments; not fMP4 or
 * rtmp, which go to ffmpeg). It is read by the load balancer, not the runner,
 * so a loopback URL there serves.
 */

const SOURCE = process.env.XC_E2E_NATIVE_SOURCE || '';
test.skip(!lb || !SOURCE, 'XC_E2E_LB_SERVER and XC_E2E_NATIVE_SOURCE not set');

const origin = new URL(process.env.XC_E2E_BASE_URL || 'http://localhost/').origin;
const bouquet = uniq('native-bouquet');
const viewer = { username: ident('native'), password: ident('nativepass') };
const channel = { name: uniq('native-channel'), id: 0, startedAt: 0 };

test.describe.serial('a copy-only channel on the native remuxer', () => {
  test('a bouquet, a line and a channel on the load balancer, recording and taking thumbnails there', async ({ page }) => {
    test.setTimeout(300_000);
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);
    channel.id = await addArchiveChannel(page, { name: channel.name, bouquet, source: SOURCE, thumbnails: true });

    await page.goto('./line');
    await page.locator('#username').fill(viewer.username);
    await page.locator('#password').fill(viewer.password);
    await page.locator('#max_connections').fill('2');
    await page.locator('#admin_notes').fill(TAG);
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
    await submitForm(page, page, 'line', page.locator('#line-submit'));
    await page.waitForURL(/lines/);

    await page.goto('./streams');
    await searchTable(page, channel.name, '#streams-table_wrapper .dt-search input, .dt-search input');
    const started = await rowAction(page, await rowWith(page.locator('#streams-table'), channel.name), 'start');
    expect(started?.result, `start answered ${JSON.stringify(started)}`).toBe(true);
    channel.startedAt = Date.now();
  });

  test('it runs on the native remuxer, not ffmpeg', async ({ page }) => {
    test.setTimeout(300_000);
    // cron:streams on the load balancer reads the producer from /proc each minute.
    await expect
      .poll(async () => {
        const r = (await tableRows(page.request, 'streams', channel.name)).find((x) => x.title === channel.name);
        return r?.usage?.producer ?? 'not reported yet';
      }, { timeout: 240_000, intervals: [15_000], message: 'the load balancer never reports the producer' })
      .toBe('fanout');
  });

  test('its timeshift is served from the remuxer\'s segments: the TS and the HLS', async () => {
    test.setTimeout(900_000);
    const wait = channel.startedAt + 240_000 - Date.now();
    if (wait > 0) {
      await new Promise((r) => setTimeout(r, wait));
    }
    let url = '';
    await expect
      .poll(async () => {
        for (const start of timeshiftStarts(channel.startedAt)) {
          const candidate = `${origin}/timeshift/${viewer.username}/${viewer.password}/2/${start}/${channel.id}.ts`;
          const h = await hold(candidate);
          h?.abort.abort();
          if (h && h.first[0] === 0x47) { // an MPEG-TS sync byte
            url = candidate;
            return 'served';
          }
        }
        return 'not yet';
      }, { timeout: 600_000, intervals: [15_000], message: 'the timeshift is never served' })
      .toBe('served');

    const pl = await fetch(url.replace(/\.ts$/, '.m3u8'), { redirect: 'follow' });
    expect(pl.status).toBe(200);
    const seg = (await pl.text()).split('\n').find((l) => l.includes('/hls/'));
    expect(seg, 'the playlist lists archive segments').toBeTruthy();
    const s = await fetch(new URL(seg!.trim(), pl.url).toString(), { redirect: 'follow' });
    expect(s.status).toBe(200);
    const body = new Uint8Array(await s.arrayBuffer());
    expect(body.byteLength, 'a segment carries bytes').toBeGreaterThan(1000);
    expect(body[0], 'an MPEG-TS segment').toBe(0x47);
  });

  test('its thumbnail is taken from the remuxer\'s segments', async ({ page }) => {
    test.setTimeout(300_000);
    await expect
      .poll(async () => {
        await page.goto(`./stream_view?id=${channel.id}`);
        const img = page.locator('img[src*="/admin/thumb?uitoken="]').first();
        if ((await img.count()) === 0) {
          return 'no thumbnail image';
        }
        const width = await img.evaluate((el: HTMLImageElement) => (el.complete ? el.naturalWidth : new Promise<number>((res) => {
          el.onload = () => res(el.naturalWidth);
          el.onerror = () => res(0);
        })));
        return width > 0 ? 'loaded' : 'not yet';
      }, { timeout: 240_000, intervals: [20_000], message: 'the thumbnail never loads' })
      .toBe('loaded');
  });

  test('delete the channel, the line and the bouquet', async ({ page }) => {
    test.setTimeout(300_000);
    await page.goto('./streams');
    await searchTable(page, channel.name, '#streams-table_wrapper .dt-search input, .dt-search input');
    await rowAction(page, await rowWith(page.locator('#streams-table'), channel.name), 'stop');
    const [l] = (await tableRows(page.request, 'lines', viewer.username)).filter((r) => r.username === viewer.username);
    expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: l.id }))?.result).toBe(true);
    expect((await adminApi(page.request, 'stream', { sub: 'delete', stream_id: channel.id, server_id: -1 }))?.result, `${channel.name} deleted`).toBe(true);
    await page.goto('./bouquets');
    const id = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
  });
});
