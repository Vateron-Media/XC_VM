const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../../src/Public/Views/admin/epgs.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/<\?= json_encode\(\$language::get\('([^']+)'\)[\s\S]*?\?>/g, (_, key) => JSON.stringify(key));
const topbar = fs.readFileSync(path.join(__dirname, '../../src/Core/Util/Topbar.php'), 'utf8');
assert.match(topbar, /onClick="forceUpdate\(\);"/);

async function main() {
    let reply;
    const requests = [];
    const timers = new Map();
    let timerId = 0;
    const toasts = [];
    const context = {
        window: {},
        AbortController,
        setTimeout: (callback, delay) => {
            assert.equal(delay, 15000);
            timers.set(++timerId, callback);
            return timerId;
        },
        clearTimeout: id => timers.delete(id),
        jQuery: () => ({ DataTable: () => ({}), on: () => {} }),
        fetch: (url, options) => {
            requests.push({ url, options });
            return new Promise((resolve, reject) => {
                reply = { resolve, reject };
                options.signal.addEventListener('abort', () => reject(new Error('aborted')));
            });
        },
        xcToast: (message, type) => toasts.push({ message, type })
    };
    vm.runInNewContext(script, context);
    assert.equal(typeof context.window.forceUpdate, 'function');

    const first = context.window.forceUpdate();
    context.window.forceUpdate();
    assert.equal(requests.length, 1, 'double clicks must not restart the EPG job twice');
    assert.equal(requests[0].url, './api?action=force_epg');
    assert.equal(requests[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.deepEqual(toasts, [], 'do not report success before acknowledgement');
    reply.resolve({ ok: true, json: async () => ({ result: true }) });
    await first;
    assert.deepEqual(toasts, [{ message: 'queued', type: 'success' }]);
    assert.equal(timers.size, 0);

    for (const answer of [
        { ok: true, json: async () => ({ result: false }) },
        { ok: false, json: async () => ({ result: true }) },
        { ok: true, json: async () => { throw new Error('invalid JSON'); } },
        null
    ]) {
        const pending = context.window.forceUpdate();
        if (answer === null) reply.reject(new Error('network failed'));
        else reply.resolve(answer);
        await pending;
        assert.deepEqual(toasts.at(-1), { message: 'error_occured', type: 'error' });
        assert.equal(timers.size, 0);
    }
    const stalled = context.window.forceUpdate();
    const abort = [...timers.values()][0];
    abort();
    await stalled;
    assert.equal(requests.at(-1).options.signal.aborted, true);
    assert.deepEqual(toasts.at(-1), { message: 'error_occured', type: 'error' });
    assert.equal(timers.size, 0);
    const retry = context.window.forceUpdate();
    reply.resolve({ ok: true, json: async () => ({ result: true }) });
    await retry;
    assert.equal(requests.length, 7, 'the action must be usable again after failures');
    assert.deepEqual(toasts.at(-1), { message: 'queued', type: 'success' });
    console.log('EPG force reload: acknowledgement, duplicate clicks, failures and retry passed');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
