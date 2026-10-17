// Minimal jQuery-shaped probe-table fixture for the view's DOM/text extraction.
function probeDom(selector) {
    if (selector && typeof selector === 'object' && 'probeText' in selector) return { text: () => selector.probeText };
    if (selector !== '<div>') return null;
    return { html(html) { return { find() {
        const rows = [...String(html).matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map(row =>
            [...row[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map(cell => cell[1].replace(/<[^>]*>/g, '').replaceAll('&nbsp;', '\u00a0').replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&amp;', '&')));
        return { length: rows.length, eq(index) { return { find() { return { map(fn) { return { get: () => rows[index].map(text => fn.call({ probeText: text })) }; } }; } }; } };
    } }; } };
}
function probeButton(extra = {}) {
    const classes = new Set(['btn-label-info']);
    return { disabled: false, textContent: 'Scan Source', classes,
        classList: { add: (...names) => names.forEach(name => classes.add(name)), remove: (...names) => names.forEach(name => classes.delete(name)) },
        setAttribute() {}, ...extra };
}
const media = '<table><tr><td>headers</td></tr><tr><td>1920&nbsp;x&nbsp;1080</td><td>h264</td><td>aac</td><td>25</td><td>extra</td></tr></table>';
module.exports = { probeDom, probeButton, media };
