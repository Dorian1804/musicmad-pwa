'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { musicmadQaRoot, MUSICMAD_QA_ROOT } = require('./paths.cjs');

function resolveInChild(root) {
    return spawnSync(process.execPath, ['-e', 'try { process.stdout.write(require(process.argv[1]).musicmadQaRoot()); } catch { process.stdout.write("invalid"); process.exitCode = 1; }', path.join(__dirname, 'paths.cjs')], {
        encoding: 'utf8',
        env: { ...process.env, MM_QA_ROOT: root }
    });
}

test('QA storage is external and shared by fixtures', () => {
    const relative = path.relative(path.resolve(__dirname, '..'), musicmadQaRoot());
    assert.ok(path.isAbsolute(musicmadQaRoot()));
    assert.ok(relative.startsWith('..') || path.isAbsolute(relative));
    assert.equal(musicmadQaRoot(), MUSICMAD_QA_ROOT);
});

test('QA root rejects relative or application locations', () => {
    for (const root of ['relative/qa', path.resolve(__dirname, '..', 'qa-public')]) {
        const result = resolveInChild(root);
        assert.equal(result.status, 1);
        assert.equal(result.stdout, 'invalid');
        assert.equal(result.stderr, '');
    }
});

test('explicit external root takes precedence over the local location file', () => {
    const root = path.join(os.tmpdir(), 'musicmad-qa-explicit-fixture');
    const result = resolveInChild(root);
    assert.equal(result.status, 0);
    assert.equal(result.stdout, root.replace(/\\/g, '/'));
    assert.equal(result.stderr, '');
});
