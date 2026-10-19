const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const tokens = fs.readFileSync('CSS/tokens.css', 'utf8');
const stage = fs.readFileSync('CSS/stage.css', 'utf8');
test('stage fonts are local, small and licensed', () => {
 for (const family of ['manrope', 'space-grotesk']) {
  assert.ok(fs.statSync(`assets/fonts/${family}-latin.woff2`).size < 30000);
  assert.ok(fs.readFileSync(`assets/fonts/${family}-LICENSE.txt`, 'utf8').includes('OFL'));
 }
 assert.match(tokens, /font-display:swap/);
 assert.doesNotMatch(tokens, /https?:/);
});
test('stage has two themes, shared colors and display typography', () => {
 assert.match(tokens, /data-theme=dark/);
 for (const name of ['stage','font-body','font-display','surface-soft','focus']) assert.ok(tokens.includes(`--${name}:`));
 assert.match(stage, /font-family:var\(--font-display\)/);
});
test('vinyl playback and reduced motion use state rather than auto animation', () => {
 assert.match(stage, /animation-play-state:paused/);
 assert.match(stage, /music-is-playing/);
 assert.match(stage, /prefers-reduced-motion:reduce/);
 assert.match(stage, /animation:none!important/);
 assert.match(fs.readFileSync('js/player.js','utf8'), /classList.toggle\('music-is-playing', playing\)/);
});
test('all shell and page entries ship stage styles and fonts', () => {
 const header=fs.readFileSync('includes/header.php','utf8'),sw=fs.readFileSync('sw.js','utf8');
 for (const file of ['CSS/core.css','CSS/shell.css']) {
  assert.ok(header.includes(file));assert.ok(sw.includes(file));
 }
 assert.ok(fs.readFileSync('CSS/core.css','utf8').includes(tokens));
 assert.ok(fs.readFileSync('CSS/shell.css','utf8').includes(stage));
 assert.ok(header.indexOf('CSS/core.css') < header.indexOf('CSS/shell.css')); 
});

test('standalone offline pages load the shared palette',()=> {
 for (const page of ['offline.html','biblioteca.html']) assert.ok(fs.readFileSync(page,'utf8').includes('CSS/tokens.css'));
 assert.ok(fs.readFileSync('pendientes.html','utf8').includes('CSS/core.css'));
});
