const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { probeDom, probeButton, media } = require('./provider-probe-fixture.cjs');
const view = fs.readFileSync(path.join(__dirname, '../../src/Public/Views/admin/providers.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?=[\s\S]*?\?>/g, php => JSON.stringify(php.includes("'online'") ? 'Online' : php.includes("'probe_failed'") ? 'Probe failed' : 'message')).replace(/<\?php[\s\S]*?\?>/g, '');
const elements = new Map();
function element(id) {
    if (!elements.has(id)) elements.set(id, {
        textContent: '', hidden: new Set(['d-none']), listeners: {}, clears: 0,
        addEventListener(name, fn) { this.listeners[name] = fn; },
        replaceChildren() { this.clears++; },
        get classList() { return { add: n => this.hidden.add(n), remove: n => this.hidden.delete(n) }; }
    });
    return elements.get(id);
}
const handlers = {};
const requests = [];
const probes = [];
const results = [];
const timers = new Map();
let timerId = 0;
let options;
let api;
let draw = 0;
const encode = value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const renderer = { display: encode };
function load() {
    return options.ajax({ draw: ++draw, start: 0, length: 25, search: { value: '' } }, data => results.push(data));
}
const jQuery = selector => probeDom(selector) || ({
    on: (event, selector, fn) => { handlers[selector] = fn; },
    DataTable: config => {
        if (selector === '#providers-table') return {};
        options = config;
        api = { search: () => api, page: () => api, ajax: { reload: load }, columns: { adjust() {} } };
        load();
        return api;
    }
});
jQuery.fn = { dataTable: { render: { text: () => renderer } } };
jQuery.ajax = config => {
    const hooks = {};
    const request = { config,
        done(fn) { hooks.done = fn; return request; },
        fail(fn) { hooks.fail = fn; return request; },
        always(fn) { hooks.always = fn; return request; },
        resolve(html) { hooks.done(html); hooks.always(); },
        abort() { if (hooks.fail) hooks.fail(); if (hooks.always) hooks.always(); }
    };
    probes.push(request);
    return request;
};
const context = {
    jQuery, document: { getElementById: element, querySelector: element },
    bootstrap: { Modal: { getOrCreateInstance: () => ({ show() {} }) } },
    window: {}, AbortController, URLSearchParams,
    setTimeout: (fn, delay) => { assert.equal(delay, 15000); timers.set(++timerId, fn); return timerId; },
    clearTimeout: id => timers.delete(id),
    fetch: (url, init) => new Promise((resolve, reject) => {
        requests.push({ url, init, resolve, reject });
        init.signal.addEventListener('abort', () => reject(new Error('aborted')));
    })
};
vm.runInNewContext(script, context);
const flush = () => new Promise(resolve => setImmediate(resolve));
function open(id, name) {
    const attrs = { 'data-id': id, 'data-name': name, 'data-updated': '2026-10-17 12:00' };
    handlers['.js-channels'].call({ getAttribute: key => attrs[key] });
}
function respond(request, data) { request.resolve({ ok: true, json: async () => data }); }
async function main() {
    assert.equal(requests.length, 0, 'opening the page must not fetch every provider');
    open('1', '<img src=x onerror=alert(1)>');
    assert.equal(element('provider-channels-name').textContent, '<img src=x onerror=alert(1)>');
    const params = new URL(requests[0].url, 'https://panel.test').searchParams;
    assert.equal(params.get('provider_id'), '1');
    assert.equal(params.get('stream_type'), 'live');
    assert.equal(params.get('length'), '25');
    assert.equal(requests[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(options.serverSide, true);
    assert.equal(options.columns[1].render, renderer);
    assert.equal(options.columns[2].render('["<b>Sport</b>","Österreich"]', 'display'), '&lt;b&gt;Sport&lt;/b&gt; / Österreich');
    assert.equal(options.columns[2].render('bad JSON', 'display'), '');

    // Switch before provider 1 answers. Its later answer must never appear under provider 2.
    respond(requests[0], { draw: 1, recordsTotal: 1, recordsFiltered: 1, data: [{ stream_display_name: 'Wrong provider' }] });
    open('2', 'Second Provider');
    assert.equal(requests[0].init.signal.aborted, true);
    assert.equal(element('#provider-channels-table tbody').clears, 1);
    respond(requests[1], { draw: 2, recordsTotal: 1, recordsFiltered: 1, data: [{ stream_display_name: 'Second channel' }] });
    await flush();
    assert.equal(results.length, 1);
    assert.equal(results[0].data[0].stream_display_name, 'Second channel');
    assert.equal(timers.size, 0);

    const sourceButton = probeButton({ getAttribute: () => '10' });
    handlers['.js-provider-source'].call(sourceButton);
    handlers['.js-provider-source'].call(sourceButton);
    assert.equal(probes.length, 1, 'do not start multiple source probes from one preview');
    assert.equal(probes[0].config.data.action, 'probe_stream', 'reuse the panels existing source check');
    assert.equal(probes[0].config.data.provider_id, '2');
    assert.equal(probes[0].config.data.provider_stream_id, '10');
    assert.equal('url' in probes[0].config.data, false, 'provider credentials stay server-side');
    assert.equal(probes[0].config.timeout, 45000);
    probes[0].resolve(media);
    assert.equal(sourceButton.textContent, 'Online · 1920 x 1080 · h264 · aac · 25');
    assert.equal(sourceButton.classes.has('btn-label-success'), true);
    handlers['.js-provider-source'].call(sourceButton);
    probes[1].resolve('<table><tr><td>Stream probe failed!</td></tr></table>');
    assert.equal(sourceButton.textContent, 'Probe failed');
    assert.equal(sourceButton.classes.has('btn-label-danger'), true);
    assert.equal(sourceButton.disabled, false);

    const pending = api.ajax.reload();
    [...timers.values()][0]();
    await pending;
    assert.equal(element('provider-channels-error').hidden.has('d-none'), false);
    assert.equal(results.at(-1).data.length, 0);
    assert.equal(timers.size, 0);
    element('provider-channels-retry').listeners.click();
    respond(requests.at(-1), { draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
    await flush();
    assert.equal(element('provider-channels-error').hidden.has('d-none'), true);

    const closed = api.ajax.reload();
    element('provider-channels-modal').listeners['hidden.bs.modal']();
    await closed;
    assert.equal(requests.at(-1).init.signal.aborted, true);
    assert.equal(element('provider-channels-error').hidden.has('d-none'), true, 'closing is not a request failure');
    assert.equal(timers.size, 0);
    console.log('Provider preview: lazy loading, scoping, escaping, provider switching, timeout, retry and close passed');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
