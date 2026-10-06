import { test, expect, type Page } from '@playwright/test';
import { act, epoch, flowOn, health, lastSeen, lb, mode, queued, row, until, withFlow } from './cluster-support';

/**
 * The Cluster Nodes page against a real load balancer enrolled in the cluster
 * API (XC_E2E_LB_SERVER: its server id). The node's flows, its token rotation,
 * a quarantine and a fence are driven from the page and seen reaching the node
 * (its agent acks each command); every test puts the node back as it found it.
 * Without XC_E2E_LB_SERVER the file is skipped.
 */

// One worker runs these in order (playwright.config); each test puts the node
// back itself, so one failing does not skip the others.
test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

/** Run `body` with the node's COMMANDS flow on, then put the flow back as it was. */
const withCommands = (page: Page, body: () => Promise<void>): Promise<void> => withFlow(page, 'commands', body);

test.beforeEach(async ({ page }) => {
  page.on('dialog', (d) => d.accept()); // fence and quarantine ask first
});

test('the load balancer is active, heard and names its agent', async ({ page }) => {
  const tr = await row(page);
  expect(await health(tr), 'health').toBe('ok');
  await expect(tr).toContainText(/\d+\.\d+\.\d+/); // the agent's version
  expect(await epoch(tr)).toBeGreaterThan(0);
});

test('a flow switched on reaches the node and is switched off again', async ({ page }) => {
  test.setTimeout(120_000);
  const tr = await row(page);
  const wasOn = await flowOn(tr, 'telemetry');
  if (wasOn && (await mode(tr)) === 2) {
    // A node in mode 2 keeps every flow: the page refuses the switch and says so.
    await act(page, 'telemetry_off');
    await expect(page.locator('.alert-warning')).toBeVisible();
    expect(await flowOn(await row(page), 'telemetry'), 'telemetry stays on').toBe(true);
    return;
  }
  await act(page, wasOn ? 'telemetry_off' : 'telemetry_on');
  await until(page, 'telemetry switched', async (tr) => (await flowOn(tr, 'telemetry')) !== wasOn, 30_000);
  // The node keeps heart-beating under the new flows: a heartbeat newer than
  // the one shown once the switch took lands, and the node is healthy.
  const seen = await lastSeen(await row(page));
  expect(seen, 'a last heartbeat on the page').not.toBe('');
  await until(page, 'a heartbeat under the new flows', async (tr) => (await lastSeen(tr)) !== seen && (await health(tr)) === 'ok', 60_000);
  await act(page, wasOn ? 'telemetry_on' : 'telemetry_off');
  await until(page, 'telemetry restored', async (tr) => (await flowOn(tr, 'telemetry')) === wasOn, 30_000);
});

test('rotate now gives the node a newer token', async ({ page }) => {
  test.setTimeout(240_000);
  await withCommands(page, async () => {
    const before = await epoch(await row(page));
    await act(page, 'rotate_now');
    await expect(page.locator('.alert-success')).toBeVisible();
    await until(page, `an epoch above ${before}`, async (tr) => (await epoch(tr)) > before, 150_000);
  });
});

test('a quarantine is lifted with trust', async ({ page }) => {
  test.setTimeout(240_000);
  await withCommands(page, async () => {
    await act(page, 'quarantine');
    try {
      await until(page, 'quarantined', async (tr) => (await health(tr)) === 'quarantined', 60_000);
    } finally {
      await act(page, 'trust');
    }
    await until(page, 'trusted again', async (tr) => (await health(tr)) === 'ok', 90_000);
  });
});

test('a fence and its lifting reach the node', async ({ page }) => {
  test.setTimeout(240_000);
  await withCommands(page, async () => {
    await act(page, 'fence');
    try {
      await expect(page.locator('.alert-success')).toBeVisible();
      await until(page, 'the fence acked', async (tr) => (await queued(tr)) === 0, 90_000);
    } finally {
      await act(page, 'unfence');
    }
    await until(page, 'the unfence acked', async (tr) => (await queued(tr)) === 0, 90_000);
    expect(await health(await row(page))).toBe('ok');
  });
});
