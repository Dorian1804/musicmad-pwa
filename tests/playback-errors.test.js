const test=require('node:test'),assert=require('node:assert/strict'),{message,createReporter}=require('../js/playback-errors.js');
test('un mismo fallo de play y del elemento de audio produce un solo aviso',async()=>{
 let probes=0,release;const notices=[],reporter=createReporter({notify:text=>notices.push(text),probe:()=>{probes++;return new Promise(resolve=>release=resolve);}});reporter.reset(true);
 const first=reporter.report({name:'NotSupportedError'},4,'/audio.php?id=1');await reporter.report(null,4,'/audio.php?id=1');release({status:404,reason:'missing-file'});await first;
 assert.equal(probes,1);assert.equal(notices.length,1);assert.match(notices[0],/servidor.*archivo/);assert.doesNotMatch(notices[0],/toque en Reproducir/);
});
test('cancelar o restaurar una cola al abrir la página no muestra errores',async()=>{
 const notices=[],reporter=createReporter({notify:x=>notices.push(x),probe:async()=>({status:404})});await reporter.report(null,4,'x');reporter.reset(true);await reporter.report({name:'AbortError'},0,'x');await reporter.report(null,1,'x');assert.equal(notices.length,0);
});
test('el diagnóstico tardío de otra pista o una sesión cerrada se descarta',async()=>{
 let release;const notices=[],reporter=createReporter({notify:x=>notices.push(x),probe:()=>new Promise(resolve=>release=resolve)});reporter.reset(true);const pending=reporter.report(null,4,'old');reporter.reset();release({reason:'missing-file'});await pending;assert.equal(notices.length,0);
});
test('autoplay, formato, servidor y sesión tienen mensajes distintos',()=>{
 assert.match(message({name:'NotAllowedError'},0),/toque en Reproducir/);assert.match(message({name:'NotSupportedError'},4,{status:200}),/formato no compatible/);assert.match(message(null,2,{status:500}),/servidor/);assert.match(message(null,4,{status:403}),/sesión/);assert.match(message(null,4,{status:405,login:true}),/sesión/);assert.match(message(null,4,{status:404}),/disponible/);assert.match(message(null,4,{reason:'invalid-record'}),/referencia guardada/);
});
test('un reintento permite avisar otra vez si falla, sin bloquear el reproductor',async()=>{
 const notices=[],reporter=createReporter({notify:x=>notices.push(x),probe:async()=>({status:503})});reporter.reset(true);await reporter.report(null,2,'x');reporter.reset(true);await reporter.report(null,2,'x');assert.equal(notices.length,2);
});
