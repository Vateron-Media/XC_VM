import { defineConfig } from '@playwright/test';
export default defineConfig({ testDir: './tests/admin', testMatch: 'vod-m3u-import.spec.ts', workers: 1, reporter: 'list', use: { browserName: 'chromium', headless: true } });
