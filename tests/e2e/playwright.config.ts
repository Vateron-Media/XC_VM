import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'node:path';

// Load tests/e2e/.env (copy from .env.example) so `make e2e` picks up creds.
dotenv.config({ path: path.resolve(__dirname, '.env') });

/**
 * Playwright config for the XC_VM Vuexy admin smoke suite.
 *
 * Configure via env (see .env.example):
 *   XC_E2E_BASE_URL  admin panel base incl. the access code,
 *                    e.g. http://panel.example.com/ACCESS_CODE
 *   XC_E2E_USER      admin username
 *   XC_E2E_PASS      admin password
 *
 * A `setup` project logs in once and stores the session; the browser projects
 * reuse it via storageState.
 */
// A trailing slash is required so relative gotos keep the access-code path
// segment (new URL('./login', '.../code') would otherwise drop 'code').
const rawBase = process.env.XC_E2E_BASE_URL || 'http://localhost/admin';
const baseURL = rawBase.endsWith('/') ? rawBase : rawBase + '/';

export default defineConfig({
  testDir: './tests',
  outputDir: './test-results',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    // 'on' so the UI-mode Actions timeline + snapshot preview are always populated
    // (with 'on-first-retry' a passing first run captures nothing to preview).
    trace: 'on',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: '.auth/admin.json' },
      dependencies: ['setup'],
    },
  ],
});
