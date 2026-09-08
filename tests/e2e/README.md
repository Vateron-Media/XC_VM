# XC_VM admin E2E (Playwright)

Browser smoke tests for the admin panel. They run against a **live**
instance (there is no built-in server), so point them at a test/canary panel.

## Run

```bash
# one-time: install deps + the Chromium browser
cd tests/e2e && npm ci && npx playwright install --with-deps chromium

# configure (or copy .env.example -> .env)
export XC_E2E_BASE_URL="http://<host>/<access-code>"
export XC_E2E_USER="<admin user>"
export XC_E2E_PASS="<admin pass>"

# from the repo root:
make e2e          # headless run
make e2e-ui       # interactive Playwright UI
```

`XC_E2E_BASE_URL` is the admin base including the access-code path segment
(e.g. `http://panel.example.com/ACCESS_CODE`) — the same prefix as `/<code>/login`.

The `setup` project logs in once and stores the session in `.auth/admin.json`;
the smoke specs reuse it. Traces/screenshots/video for failures land in
`test-results/`, the HTML report in `playwright-report/` (`npm run report`).
