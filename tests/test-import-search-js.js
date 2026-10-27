'use strict';
// Run with: node tests/test-import-search-js.js
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const js = fs.readFileSync(path.join(__dirname, '../admin/js/admin.js'), 'utf8');
const start = js.indexOf('        function networkPathValue($input) {');
const end = js.indexOf('\n        function getNetworkPath()', start);
assert(start >= 0 && end > start, 'networkPathValue must exist');
const context = {};
vm.runInNewContext(js.slice(start, end) + '\nthis.networkPathValue = networkPathValue;', context);
const get = (value, placeholder) => context.networkPathValue({
    length: 1,
    val: () => value,
    attr: () => placeholder || '',
});
for (const valid of ['/mnt/share', '/mnt/share_Василий', '\\\\server\\share', '/home/user/или-район', 'smb://nas/share/import']) {
    assert.strictEqual(get(valid), valid, 'valid path must not be mistaken for an example');
}
for (const example of ['', '\\\\server\\share\\ или /mnt/share', '\\\\server\\share or /mnt/share']) {
    assert.strictEqual(get(example), '', 'empty/example path must be ignored');
}
assert.strictEqual(get('/mnt/example', '/mnt/example'), '', 'unchanged placeholder must be ignored');
console.log('Import path handling JS: PASS');
