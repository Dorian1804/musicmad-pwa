const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function math(){const context={window:{}};vm.runInNewContext(fs.readFileSync('js/listening-math.js','utf8'),context);return context.window.MusicMadListeningMath;}
test('solo suma avance normal limitado al tiempo real transcurrido',()=>{const m=math();assert.equal(m.advance(0,.25,250,1),250);assert.equal(m.advance(0,.5,250,2),250);assert.equal(m.advance(0,.125,250,.5),125);});
test('adelantar, retroceder, pausar y muestras imposibles no inflan el tiempo',()=>{const m=math();for(const args of [[1,30,250,1],[2,1,250,1],[1,1,250,1],[0,10,10000,1],[0,.2,0,1],[0,.2,250,4],[0,.2,250,1,true],[NaN,.2,250,1]])assert.equal(m.advance(...args),0);});
function tracker(respond){
 const events=new Map(),calls=[],timers=new Map();let clock=0,account='fake-account',next=0;
 const media={src:'http://localhost/audio.php?id=42',currentSrc:'http://localhost/audio.php?id=42',currentTime:0,seeking:false,muted:false,volume:1,playbackRate:1,dataset:{},matches:s=>s==='audio,video'||s==='[data-global-audio]'};
 const document={querySelector:s=>({content:s.includes('account')?account:'fake-csrf'}),addEventListener:(name,fn)=>{if(!events.has(name))events.set(name,[]);events.get(name).push(fn);}};
 const context={window:{},document,navigator:{onLine:true},location:{origin:'http://localhost',href:'http://localhost/feed.php'},URL,crypto:require('node:crypto').webcrypto,Uint8Array,AbortController,performance:{now:()=>clock},setTimeout:(fn,delay)=>{const id=++next;timers.set(id,{fn,delay});return id;},clearTimeout:id=>timers.delete(id),fetch:async(url,options)=>{const body=JSON.parse(options.body);calls.push(body);return {ok:true,status:200,json:async()=>respond(body)};}};
 vm.createContext(context);vm.runInContext(fs.readFileSync('js/listening-math.js','utf8'),context);vm.runInContext(fs.readFileSync('js/listening-statistics.js','utf8'),context);
 return {media,calls,timers,setOnline(v){context.navigator.onLine=v;},setAccount(v){account=v;},step(seconds){clock+=seconds*1000;media.currentTime+=seconds;},event(name){for(const fn of events.get(name)||[])fn({target:media});}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('precargar no inicia recibo, avance cinco segundos confirma una vez y seek no suma',async()=>{
 const h=tracker(body=>body.accion==='iniciar'?{ok:true,umbral_ms:5000,confirmada:false}:{ok:true,confirmada:true,nueva:true});h.event('loadedmetadata');assert.equal(h.calls.length,0);h.event('playing');await tick();assert.equal(h.calls.length,1);h.step(1);h.event('timeupdate');h.media.currentTime=30;h.event('seeking');h.event('seeked');h.event('timeupdate');assert.equal(h.calls.length,1);for(let i=0;i<4;i++){h.step(1);h.event('timeupdate');}await tick();assert.equal(h.calls.length,2);assert.equal(h.calls[1].accion,'confirmar');assert.equal(h.calls[1].escuchados_ms,5000);h.event('playing');h.step(1);h.event('timeupdate');await tick();assert.equal(h.calls.length,2);
});
test('offline no envía, cuenta nueva descarta respuesta tardía y reproducción propia no confirma',async()=>{
 const offline=tracker(()=>({ok:true}));offline.setOnline(false);offline.event('playing');assert.equal(offline.calls.length,0);
 let finish;const h=tracker(()=>new Promise(resolve=>{finish=resolve;}));h.setAccount('');h.event('playing');assert.equal(h.calls.length,0);h.setAccount('first');h.event('playing');await tick();h.setAccount('second');h.event('musicmad:session');finish({ok:true,umbral_ms:5000,confirmada:false});await tick();for(let i=0;i<6;i++){h.step(1);h.event('timeupdate');}await tick();assert.equal(h.calls.length,1);
 const own=tracker(()=>({ok:true,ignorada:true}));own.event('playing');await tick();for(let i=0;i<6;i++){own.step(1);own.event('timeupdate');}assert.equal(own.calls.length,1);
});
test('Chart.js local conserva versión e integridad fijadas',()=>{const source=fs.readFileSync('assets/vendor/chart/chart-4.5.1.umd.min.js');assert.equal(require('node:crypto').createHash('sha256').update(source).digest('hex'),'48444a82d4edcb5bec0f1965faacdde18d9c17db3063d042abada2f705c9f54a');const Chart=require('../assets/vendor/chart/chart-4.5.1.umd.min.js');assert.equal(Chart.version,'4.5.1');assert.ok(Chart.registry.getController('bar'));});
