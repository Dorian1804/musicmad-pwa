'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const source = fs.readFileSync('js/perfil.js', 'utf8');
const start = source.indexOf('const publicImageUrl =');
const end = source.indexOf('\n  };', start);
assert.ok(start >= 0 && end > start);
const convert = vm.runInNewContext(source.slice(start, end + 5) + '\npublicImageUrl;', {
  URL, document: { baseURI: 'https://qa.invalid/MusicMad_PWA/perfil.php' }
});
test('profile previews use the endpoint without rewrite rules', () => {
  const name = '1_' + 'a'.repeat(24) + '.jpg';
  for (const kind of ['avatars', 'covers']) {
    assert.equal(convert(`uploads/${kind}/${name}`), `https://qa.invalid/MusicMad_PWA/media-image.php?kind=${kind}&name=${name}`);
  }
});
test('built-in art and temporary photo previews retain their URLs', () => {
  for (const value of ['img/portadas/portada-01.svg', 'blob:https://qa.invalid/temporary', '']) assert.equal(convert(value), value);
});
test('traversal and invalid server filenames are never rewritten', () => {
  for (const value of ['uploads/avatars/../config/local.php', 'uploads/market/invalid.jpg', 'uploads/covers/0_' + 'a'.repeat(24) + '.jpg']) assert.equal(convert(value), value);
});
