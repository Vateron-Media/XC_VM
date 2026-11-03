import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(__dirname, '../../../..');
const controls = fs.readFileSync(path.join(root, 'src/Public/Views/admin/vod_m3u_controls.php'), 'utf8')
  .replace(/<\?php[\s\S]*?\?>/g, '')
  .replace(/<\?=[\s\S]*?\?>/g, 'label');

async function setup(page: import('@playwright/test').Page, view = 'movie') {
  const viewSource = fs.readFileSync(path.join(root, `src/Public/Views/admin/${view}.php`), 'utf8');
  const scriptUrl = viewSource.match(/<script src="([^"]+vod-m3u-import\.js)"/)![1];
  await page.route('http://vod.test/assets/xcvm/vod-m3u-import.js', route => route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(root, 'src/Public/assets/admin/xcvm/vod-m3u-import.js'), 'utf8') }));
  await page.route('http://vod.test/post.php?action=vod_playlist_preview', route => route.fulfill({
    json: { result: true, entries: [{ id: 'a'.repeat(64), title: '<img src=x onerror=alert(1)>' }, { id: 'b'.repeat(64), title: 'Show S01E02' }] }
  }));
  await page.route('http://vod.test/', route => route.fulfill({ contentType: 'text/html', body: `<form>
    <input type="radio" id="import_type_1" name="import_kind" value="m3u" checked>
    <input type="radio" id="import_type_2" name="import_kind" value="folder">
    <input type="file" id="m3u_file" name="m3u_file">
    <input id="import_folder" name="import_folder">
    <input type="checkbox" id="direct_source" name="direct_source" checked>
    <input type="checkbox" id="direct_proxy" name="direct_proxy" checked>
    ${controls}<button type="submit" id="save">Save</button></form>` }));
  await page.goto('http://vod.test/');
  await page.evaluate(() => {
    (window as any).xcToast = (message: string) => { document.body.dataset.toast = message; };
    document.querySelector('form')!.addEventListener('submit', event => {
      event.preventDefault();
      // Both shipped forms re-enable disabled settings before serializing.
      document.querySelectorAll<HTMLInputElement>(':disabled').forEach(el => { el.disabled = false; });
      document.body.dataset.payload = JSON.stringify(Object.fromEntries(new FormData(event.target as HTMLFormElement)));
    });
  });
  await page.addScriptTag({ url: new URL(scriptUrl, 'http://vod.test/').href });
}

test('preview selects titles safely and download keeps the destination in submitted settings', async ({ page }) => {
  await setup(page);
  await page.locator('#m3u_url').fill('https://provider.test/list.m3u');
  await page.locator('#vod_m3u_preview').click();
  await expect(page.locator('#vod_m3u_list input')).toHaveCount(2);
  await expect(page.locator('#vod_m3u_list img')).toHaveCount(0);
  await page.locator('#vod_m3u_list input').first().uncheck();
  await expect(page.locator('#m3u_selection')).toHaveValue(JSON.stringify(['b'.repeat(64)]));
  await page.locator('#m3u_download').check();
  await expect(page.locator('#vod_download_path')).toHaveAttribute('required', '');
  await expect(page.locator('#direct_source')).not.toBeChecked();
  await expect(page.locator('#direct_proxy')).not.toBeChecked();
  await page.locator('#vod_download_path').fill('s:7:/media/vod/');
  await page.locator('#save').click();
  const payload = JSON.parse((await page.locator('body').getAttribute('data-payload'))!);
  expect(payload.m3u_url).toBe('https://provider.test/list.m3u');
  expect(payload.m3u_selection).toBe(JSON.stringify(['b'.repeat(64)]));
  expect(payload.vod_download_path).toBe('s:7:/media/vod/');
  expect(payload.m3u_download).toBe('1');
  expect(payload).not.toHaveProperty('direct_source');
});

test('changing playlist resets selection and folder import clears playlist/download mode', async ({ page }) => {
  await setup(page, 'serie');
  await page.locator('#m3u_url').fill('https://provider.test/one.m3u');
  await page.locator('#vod_m3u_preview').click();
  await expect(page.locator('#m3u_selection')).toHaveValue(JSON.stringify(['a'.repeat(64), 'b'.repeat(64)]));
  await page.locator('#m3u_url').fill('https://provider.test/two.m3u');
  await expect(page.locator('#m3u_selection')).toHaveValue('');
  await expect(page.locator('#vod_m3u_entries')).toBeHidden();
  await page.locator('#m3u_download').check();
  await page.locator('#import_type_2').check();
  await expect(page.locator('#vod_download_path')).not.toHaveAttribute('required', '');
  await page.locator('#import_folder').fill('s:7:/media/existing/');
  await page.locator('#save').click();
  const payload = JSON.parse((await page.locator('body').getAttribute('data-payload'))!);
  expect(payload.import_folder).toBe('s:7:/media/existing/');
  expect(payload.m3u_url).toBe('');
  expect(payload).not.toHaveProperty('m3u_download');
});
