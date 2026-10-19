const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const locales=['es','en','pt-BR','fr','de','it','ja','ko','zh-CN'];
function load(locale){const context={};context.window=context;vm.createContext(context);vm.runInContext(fs.readFileSync('assets/i18n/'+locale+'.js','utf8'),context);vm.runInContext(fs.readFileSync('js/i18n.js','utf8'),context);return context;}
test('los nueve catálogos de cliente comparten claves y marcadores',()=>{
 const base=load('es').MusicMadMessages;
 for(const locale of locales){const loaded=load(locale),messages=loaded.MusicMadMessages;assert.equal(loaded.MusicMadMessagesLocale,locale);assert.deepEqual(Object.keys(messages),Object.keys(base));
  for(const key of Object.keys(base)){assert.equal(typeof messages[key],'string');assert.ok(messages[key].length);assert.deepEqual((messages[key].match(/\{[a-z_]+\}/g)||[]).sort(),(base[key].match(/\{[a-z_]+\}/g)||[]).sort());}
 }
});
test('contador y cierre de hoja usan parámetros sin ejecutar contenido',()=>{
 const en=load('en');assert.equal(en.MusicMadText('{count} seleccionados · mínimo 3, máximo {maximum}',{count:3,maximum:6}),'3 selected · minimum 3, maximum 6');
 assert.equal(en.MusicMadText('Cerrar {title}',{title:'<img src=x onerror=bad()>'}),'Close <img src=x onerror=bad()>');
 assert.equal(en.MusicMadText('unknown'),'unknown');assert.equal(en.MusicMadText('Cerrar {title}',{title:{}}),'Close {title}');
});
test('selecciones de tutorial y mensajes de error están traducidos',()=>{
 const ja=load('ja');assert.equal(ja.MusicMadText('Guardar y continuar'),'保存して次へ');
 assert.equal(ja.MusicMadText('Elige al menos tres perfiles distintos.'),'異なるプロフィールを3人以上選んでください。');
 assert.equal(load('de').MusicMadText('Guardar perfil'),'Profil speichern');
});
