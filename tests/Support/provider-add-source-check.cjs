const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { probeDom, probeButton, media } = require('./provider-probe-fixture.cjs');
const view = fs.readFileSync(path.join(__dirname, '../../src/Public/Views/admin/stream.php'), 'utf8');
const button = view.slice(view.indexOf('            function providerAddButton(row)'), view.indexOf('            var provTable'));
const handler = view.slice(view.indexOf('            var providerProbeRequest = null;'), view.indexOf("            $('#datatable-provider-streams tbody').on('click', '.prov-add'"));
const clean = code => code.replace(/<\?=[\s\S]*?\?>/g, php => JSON.stringify(php.includes("'online'") ? 'Online' : php.includes("'probe_failed'") ? 'Probe failed' : 'Scan Source'));
const handlers = {};
const modal = { addEventListener: (event, fn) => { handlers[event] = fn; } };
const result = {};
const requests = [];
const $ = selector => probeDom(selector) || ({ on: (event, selector, fn) => { handlers[selector] = fn; } });
$.ajax = config => {
    const hooks = {};
    const request = {
        config,
        done(fn) { hooks.done = fn; return request; },
        fail(fn) { hooks.fail = fn; return request; },
        always(fn) { hooks.always = fn; return request; },
        resolve(html) { hooks.done(html); hooks.always(); },
        abort() { hooks.fail(); hooks.always(); }
    };
    requests.push(request);
    return request;
};
const esc = text => String(text).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const urls = [];
const context = { $, esc, escAttr: esc,
    document: { getElementById: id => id === 'providerModal' ? modal : result },
    probingBadge: 'Probing',
    probeURL: url => { urls.push(url); return 'existing-probe-with-selected-server'; },
    renderProbe: html => 'existing renderer: ' + html
};
vm.runInNewContext(clean(button + handler), context);
const html = context.providerAddButton({ name: 'Channel', stream_url: 'https://example.test/"<unsafe>' });
assert.match(html, /prov-add/);
assert.match(html, /prov-check/);
assert.equal(html.includes('"<unsafe>'), false);
const buttonNode = probeButton({ dataset: { url: 'https://source.test/live' } });
handlers['.prov-check'].call(buttonNode);
handlers['.prov-check'].call(buttonNode);
assert.equal(requests.length, 1);
assert.deepEqual(urls, ['https://source.test/live']);
assert.equal(requests[0].config.url, 'existing-probe-with-selected-server');
assert.equal(requests[0].config.timeout, 45000);
requests[0].resolve(media);
assert.equal(buttonNode.textContent, 'Online · 1920 x 1080 · h264 · aac · 25');
assert.equal(buttonNode.classes.has('btn-label-success'), true);
handlers['.prov-check'].call(buttonNode);
requests[1].resolve('<table><tr><td>Stream probe failed!</td></tr></table>');
assert.equal(buttonNode.textContent, 'Probe failed');
assert.equal(buttonNode.classes.has('btn-label-danger'), true);
assert.equal(buttonNode.disabled, false);
handlers['.prov-check'].call(buttonNode);
handlers['hidden.bs.modal']();
assert.equal(buttonNode.disabled, false);
assert.equal(result.innerHTML, undefined, 'probe output belongs in the clicked button');
console.log('Add Stream provider check: existing probeURL, inline button results, failure and close passed');
