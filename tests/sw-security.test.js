const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
function environment() {
  const handlers={},stores=new Map(),notifications=[];let pushBinding=null;
  const cache=(name)=>{if(!stores.has(name))stores.set(name,new Map()); const items=stores.get(name);return {
    async add(){},async put(key,value){items.set(typeof key==='string'?key:key.url,value.clone());},
    async match(key){return items.get(typeof key==='string'?key:key.url)?.clone();},
    async keys(){return [...items.keys()].map(url=>({url}));},async delete(key){return items.delete(typeof key==='string'?key:key.url);}
  };};
  const context={URL,Headers,Request,Response,Map,Promise,Error,
    self:{registration:{scope:'https://fixture.invalid/MusicMad_PWA/',getNotifications:async()=>[],showNotification:async(title,options)=>notifications.push({title,options})},location:{origin:'https://fixture.invalid'},
      addEventListener:(type,fn)=>handlers[type]=fn,clients:{claim:async()=>{},matchAll:async()=>[],openWindow:async value=>notifications.push({url:value})},skipWaiting(){}},
    caches:{open:async name=>cache(name),keys:async()=>[...stores.keys()],delete:async name=>stores.delete(name),
      match:async(key,options)=>cache(options.cacheName).match(key)},
    fetch:async()=>{throw Error('offline');}
  };
  vm.createContext(context);context.importScripts=(...paths)=>paths.forEach(path=>vm.runInContext(fs.readFileSync(path,'utf8'),context));
  vm.runInContext(fs.readFileSync(process.env.MM_QA_SW_FILE||'sw.js','utf8'),context);
  context.self.MusicMadPushState={write:async value=>{pushBinding=value;},read:async()=>pushBinding};
  return {context,handlers,stores,notifications};
}
test('push vinculado marca el ícono y logout limpia el marcador nativo',async()=>{
 const q=environment(),calls=[];q.context.self.navigator={setAppBadge:async()=>calls.push('flag'),clearAppBadge:async()=>calls.push('clear')};
 const token='b'.repeat(64);await q.context.self.MusicMadPushState.write({token});let pending;
 q.handlers.push({data:{json:()=>({token,destino:'mensajes.php'})},waitUntil(promise){pending=promise}});await pending;assert.deepEqual(calls,['flag']);
 const jobs=[];q.handlers.message({data:{type:'CLEAR_PAGES'},source:{id:'fixture'},waitUntil(promise){jobs.push(promise)}});await Promise.all(jobs);assert.deepEqual(calls,['flag','clear']);
});
test('PHP/API/medios nunca pasan por la caché de estáticos',()=>{
  const {context}=environment();
  for(const path of ['feed.php','mensajes.php','api/v1/feed','perfil-estilos.php?ids=1','uploads/avatars/1.jpg','video.php?id=1','@musico_qa','portafolio.php?usuario=musico_qa','pista-publica.php?id=1','embed.php?usuario=musico_qa','exportar-datos.php']) {
    assert.equal(context.staticRequest(new Request('https://fixture.invalid/MusicMad_PWA/'+path)),false,path);
  }
  assert.equal(context.staticRequest(new Request('https://fixture.invalid/MusicMad_PWA/CSS/mobile.css')),true);
});
test('no-store, HTML disfrazado y respuestas redirigidas no se cachean',()=>{
  const {context}=environment();
  for(const headers of [{'Content-Type':'text/css','Cache-Control':'no-store'},{'Content-Type':'text/html'},{'Content-Type':'image/png','Cache-Control':'private'}]) {
    const res=new Response('data',{headers});Object.defineProperty(res,'type',{value:'basic'});
    assert.equal(context.cacheableStatic(res),false);
  }
  const safe=new Response('body{}',{headers:{'Content-Type':'text/css'}});Object.defineProperty(safe,'type',{value:'basic'});
  assert.equal(context.cacheableStatic(safe),true);
});
test('otra sesión no puede leer una pista offline y Range conserva sus bytes',async()=>{
  const {context}=environment(),a='a'.repeat(64),b='b'.repeat(64);
  context.fetch=async()=>new Response('abcdef',{headers:{'Content-Type':'audio/mpeg'}});
  await context.cacheAudioTrack('5',a,{postMessage(){}});
  const request=new Request('https://fixture.invalid/MusicMad_PWA/audio.php?id=5',{headers:{Range:'bytes=1-3'}});
  assert.equal((await context.audioFromCache(request,b)).status,503);
  const res=await context.audioFromCache(request,a);
  assert.equal(res.status,206);assert.equal(res.headers.get('Content-Range'),'bytes 1-3/6');assert.equal(await res.text(),'bcd');
  const invalid=new Request(request.url,{headers:{Range:'bytes=-0'}});
  assert.equal((await context.audioFromCache(invalid,a)).status,416);
});

test('la biblioteca lista solo la cuenta elegida y conserva metadatos escapados',async()=>{
  const {context}=environment(),a='a'.repeat(64),b='b'.repeat(64);
  context.fetch=async()=>new Response('abcdef',{headers:{'Content-Type':'audio/mpeg'}});
  await context.cacheAudioTrack('5',a,{postMessage(){}},{title:'Canción <b>propia</b>',artist:'José'});
  assert.equal((await context.listSavedTracks(b)).length,0);
  const tracks=await context.listSavedTracks(a);
  assert.equal(tracks.length,1);assert.equal(tracks[0].title,'Canción <b>propia</b>');assert.equal(tracks[0].artist,'José');
});
test('logout elimina runtime, cachés antiguas y pistas; conserva solo shell público',async()=>{
  const {context,handlers,stores}=environment();
  const shell=vm.runInContext('CACHE_SHELL',context);
  await context.caches.open('musicmad-runtime-v28');await context.caches.open('musicmad-tracks-v2');await context.caches.open(shell);
  let done;handlers.message({data:{type:'CLEAR_PAGES'},waitUntil:p=>done=p});await done;
  assert.deepEqual([...stores.keys()],[shell]);
});
test('una descarga en curso no vuelve a guardar bytes después de logout',async()=>{
  const {context,handlers,stores}=environment();let finish;
  context.fetch=()=>new Promise(resolve=>finish=resolve);
  const pending=context.cacheAudioTrack('5','a'.repeat(64),{postMessage(){}});
  let done;handlers.message({data:{type:'CLEAR_PAGES'},waitUntil:p=>done=p});await done;
  finish(new Response('secret',{headers:{'Content-Type':'audio/mpeg'}}));await pending;
  assert.equal(stores.has('musicmad-tracks-v2'),false);
});


test('push exige binding del dispositivo y no muestra mensajes privados',async()=>{
 const {context,handlers,notifications}=environment(),token='a'.repeat(64);
 let done;handlers.push({data:{json:()=>({token,texto:'PRIVATE',destino:'mensajes.php'})},waitUntil:p=>done=p});await done;assert.equal(notifications.length,0);
 await context.self.MusicMadPushState.write({token});
 handlers.push({data:{json:()=>({token,texto:'PRIVATE',destino:'mensajes.php'})},waitUntil:p=>done=p});await done;
 assert.equal(notifications.length,1);assert.equal(notifications[0].options.body,'Tienes un nuevo mensaje.');assert.equal(JSON.stringify(notifications).includes('PRIVATE'),false);
});
test('logout descarta push de una cuenta anterior',async()=>{
 const {context,handlers,notifications}=environment(),token='a'.repeat(64);await context.self.MusicMadPushState.write({token});
 const tasks=[];handlers.message({data:{type:'CLEAR_PAGES'},waitUntil:p=>tasks.push(p)});await Promise.all(tasks);
 let done;handlers.push({data:{json:()=>({token,destino:'mensajes.php'})},waitUntil:p=>done=p});await done;assert.equal(notifications.length,0);
});
test('una notificación no puede abrir una URL externa',async()=>{
 const {handlers,notifications}=environment();let done,closed=false;handlers.notificationclick({notification:{data:{destination:'https://evil.invalid'},close:()=>closed=true},waitUntil:p=>done=p});await done;
 assert.equal(closed,true);assert.equal(notifications[0].url,'https://fixture.invalid/MusicMad_PWA/notificaciones.php');
});

test('push abre conversación específica dentro del scope y rechaza consultas extra',async()=>{
 const {context,handlers,notifications}=environment(),token='a'.repeat(64);await context.self.MusicMadPushState.write({token});
 let done;handlers.push({data:{json:()=>({token,destino:'mensajes.php?con=42'})},waitUntil:p=>done=p});await done;
 assert.equal(notifications[0].options.data.destination,'mensajes.php?con=42');
 handlers.notificationclick({notification:{data:{destination:'mensajes.php?con=42'},close(){}},waitUntil:p=>done=p});await done;
 assert.equal(notifications[1].url,'https://fixture.invalid/MusicMad_PWA/mensajes.php?con=42');
 handlers.notificationclick({notification:{data:{destination:'mensajes.php?con=42&next=https://evil.invalid'},close(){}},waitUntil:p=>done=p});await done;
 assert.equal(notifications[2].url,'https://fixture.invalid/MusicMad_PWA/notificaciones.php');
});

test('push traduce texto fijo y nunca utiliza contenido privado del payload',async()=>{
 const {context,handlers,notifications}=environment(),token='a'.repeat(64);await context.self.MusicMadPushState.write({token});
 let done;handlers.push({data:{json:()=>({token,idioma:'ja',destino:'mensajes.php',texto:'PRIVATE'})},waitUntil:p=>done=p});await done;
 assert.equal(notifications[0].options.body,'新しいメッセージがあります。');
 handlers.push({data:{json:()=>({token,idioma:'../../secret',destino:'mensajes.php',texto:'PRIVATE'})},waitUntil:p=>done=p});await done;
 assert.equal(notifications[1].options.body,'Tienes un nuevo mensaje.');assert.equal(JSON.stringify(notifications).includes('PRIVATE'),false);
});

test('fuentes locales explícitas se pueden guardar sin cachear otros archivos',()=> {
 const {context}=environment();
 assert.equal(context.staticRequest(new Request('https://fixture.invalid/MusicMad_PWA/assets/fonts/manrope-latin.woff2')),true);
 assert.equal(context.staticRequest(new Request('https://fixture.invalid/MusicMad_PWA/uploads/audio/evil.woff2')),false);
 const font=new Response('font',{headers:{'Content-Type':'font/woff2'}});Object.defineProperty(font,'type',{value:'basic'});
 assert.equal(context.cacheableStatic(font),true);
 const privateFont=new Response('font',{headers:{'Content-Type':'font/woff2','Cache-Control':'private, no-store'}});Object.defineProperty(privateFont,'type',{value:'basic'});
 assert.equal(context.cacheableStatic(privateFont),false);
});
