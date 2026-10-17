const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../../src/Public/Views/admin/epgs.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/<\?=[\s\S]*?\?>/g, '"message"');
const topbar = fs.readFileSync(path.join(__dirname, '../../src/Core/Util/Topbar.php'), 'utf8');
assert.match(topbar, /onClick="forceUpdate\(\);"/);

async function main() {
    let reply;
    const requests = [];
    const toasts = [];
    const context = {
        window: {},
        jQuery: () => ({ DataTable: () => ({}), on: () => {} }),
        fetch: (url, options) => {
            requests.push({ url, options });
            return new Promise((resolve, reject) => { reply = { resolve, reject }; });
        },
        xcToast: (message, type) => toasts.push(type)
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
    assert.deepEqual(toasts, ['success']);

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
        assert.equal(toasts.at(-1), 'error');
    }
    const retry = context.window.forceUpdate();
    reply.resolve({ ok: true, json: async () => ({ result: true }) });
    await retry;
    assert.equal(requests.length, 6, 'the action must be usable again after failures');
    assert.equal(toasts.at(-1), 'success');
    console.log('EPG force reload: acknowledgement, duplicate clicks, failures and retry passed');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
