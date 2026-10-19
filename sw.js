// MusicMad: únicamente estáticos públicos en caché. HTML/API siempre en red.
importScripts('js/pending-store.js','js/push-store.js','js/push-texts.js');
const VERSION = 'v107';
const CACHE_SHELL = `musicmad-shell-${VERSION}`;
const CACHE_RUNTIME = `musicmad-runtime-${VERSION}`;
const CACHE_TRACKS = 'musicmad-tracks-v2';
const CURRENT = [CACHE_SHELL, CACHE_RUNTIME, CACHE_TRACKS];
const url = path => new URL(path, self.registration.scope).href;
const SHELL = ['offline.html','biblioteca.html','pendientes.html','manifest.json','CSS/app.css','CSS/social.css','CSS/pwa.css','CSS/enhancements.css','CSS/mobile.css','CSS/navigation.css','CSS/language.css','CSS/push-settings.css','CSS/offline.css','CSS/bands.css','CSS/discover.css','CSS/map.css','CSS/events.css','CSS/account.css','CSS/portfolio.css','CSS/embed.css','CSS/rooms.css','CSS/waveform.css','CSS/creative.css','CSS/artist.css','CSS/challenges.css','CSS/endorsements.css','CSS/onboarding.css','CSS/error.css','CSS/refresh.css','assets/vendor/leaflet/leaflet.css','assets/vendor/leaflet/leaflet.js',
  'assets/vendor/qrcode/qrcodegen-1.8.0.js','js/qr.js','js/portfolio.js','js/rooms.js','js/waveform.js','js/waveform-math.js','js/listening-math.js','js/listening-statistics.js','js/artist-dashboard.js','assets/vendor/chart/chart-4.5.1.umd.min.js','js/studio-mixer.js','js/recorder-math.js','js/studio-recorder.js','js/clip-player.js','js/scene-map.js','js/global-search.js','js/push-store.js','js/push.js','js/route-catalogue.js','js/app.js','js/playback-errors.js','js/player.js','js/navigation.js','js/refresh-math.js','js/refresh.js','js/session-state.js','js/mobile-ui.js','js/sheets.js','js/comments.js','js/pending-store.js','js/pending-actions.js','js/reaction-menu.js','js/reaction-burst.js','js/app-badge.js','js/pending-review.js','js/crop-photo.js','js/pwa.js','js/perfil.js','js/chat.js','js/market.js','js/actividad.js','js/infinite-feed.js','js/upload-progress.js','js/offline.js','js/offline-library.js',
  'img/icon-192.png','img/icon-512.png','img/icon-512-maskable.png'];
SHELL.push('CSS/core.css','CSS/shell.css','CSS/tokens.css','CSS/stage.css','assets/fonts/manrope-latin.woff2','assets/fonts/space-grotesk-latin.woff2');
const scopes = new Map();
SHELL.push('CSS/email-verification.css','js/route-catalogue.js');
SHELL.push('CSS/verification.css');
SHELL.push('js/onboarding.js');
SHELL.push('CSS/public-share.css');
let privateEpoch = 0;
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE_SHELL).then(cache => Promise.all(SHELL.map(path => cache.add(url(path)).catch(() => null))))));
self.addEventListener('activate', event => event.waitUntil(
  caches.keys().then(names=>Promise.all(names.filter(name=>name.startsWith('musicmad-')&&!CURRENT.includes(name)).map(name=>caches.delete(name)))).then(()=>self.clients.claim())
));
self.addEventListener('message', event => {
  const { type, scope } = event.data || {};
  if (type === 'SKIP_WAITING') self.skipWaiting();
  if (type === 'SET_ACCOUNT' && event.source?.id) {
    if (/^[a-f0-9]{64}$/.test(scope || '')) scopes.set(event.source.id, scope);
    else scopes.delete(event.source.id);
  }
  if(type==='PUSH_BIND' && scopes.get(event.source?.id)===scope && /^[a-f0-9]{64}$/.test(event.data.token || ''))event.waitUntil(self.MusicMadPushState.write({token:event.data.token}).then(()=>event.ports?.[0]?.postMessage({ok:true})).catch(()=>event.ports?.[0]?.postMessage({ok:false})));
  if(type==='PUSH_CLEAR')event.waitUntil(self.MusicMadPushState.write(null).then(()=>event.ports?.[0]?.postMessage({ok:true})).catch(()=>event.ports?.[0]?.postMessage({ok:false})));
  if (type === 'CLEAR_PAGES') {
    if(self.navigator?.clearAppBadge)event.waitUntil(self.navigator.clearAppBadge().catch(()=>{}));
    event.waitUntil(self.MusicMadPushState.write(null).catch(()=>{}));
    event.waitUntil(self.registration.getNotifications().then(notifications=>notifications.forEach(notification=>notification.close())).catch(()=>{}));
    privateEpoch++; scopes.clear();
    event.waitUntil(self.MusicMadPending.clear().catch(()=>{}));
    event.waitUntil(caches.keys().then(names=>Promise.all(names.filter(name=>name.startsWith('musicmad-')&&name!==CACHE_SHELL).map(name=>caches.delete(name)))));
  }
  if (type === 'LIST_TRACKS' && /^[a-f0-9]{64}$/.test(scope || '')) event.waitUntil(listSavedTracks(scope).then(tracks=>event.ports?.[0]?.postMessage({ok:true,tracks})));
  if (type === 'REMOVE_TRACK' && /^[a-f0-9]{64}$/.test(scope || '') && /^\d{1,10}$/.test(String(event.data.id || ''))) event.waitUntil(caches.open(CACHE_TRACKS).then(cache=>cache.delete(trackCacheKey(url('audio.php?id='+event.data.id),scope))).then(()=>event.ports?.[0]?.postMessage({ok:true})));
  if (type === 'CACHE_TRACK') event.waitUntil(cacheAudioTrack(String(event.data.id || ''), scopes.get(event.source?.id), event.ports?.[0], event.data));
});
function trackCacheKey(value, scope) {
  const key = new URL(value); key.searchParams.delete('_scope'); key.searchParams.set('_account', scope); return key.href;
}
async function cacheAudioTrack(id, scope, replyPort, metadata = {}) {
  const reply = (ok,message)=>replyPort?.postMessage({ok,message});
  if (!scope || !/^\d{1,10}$/.test(id)) { reply(false,'Inicia sesión para guardar esta pista.'); return; }
  const epoch = privateEpoch;
  try {
    const trackUrl = url(`audio.php?id=${id}`);
    const response = await fetch(new Request(trackUrl,{credentials:'same-origin',cache:'no-store'}));
    if (!response.ok || !(response.headers.get('Content-Type')||'').startsWith('audio/')) throw Error();
    const bytes = await response.arrayBuffer();
    if (!bytes.byteLength || bytes.byteLength>64*1024*1024) { reply(false,'La pista supera 64 MB.'); return; }
    if (epoch!==privateEpoch) { reply(false,'La sesión cambió; vuelve a intentarlo.'); return; }
    const headers = new Headers(response.headers); headers.set('Content-Length',String(bytes.byteLength)); headers.set('Accept-Ranges','bytes');
    headers.set('X-MusicMad-Title',encodeURIComponent(Array.from(String(metadata.title || 'Pista '+id)).slice(0,160).join('')));
    headers.set('X-MusicMad-Artist',encodeURIComponent(Array.from(String(metadata.artist || 'MusicMad')).slice(0,160).join('')));
    const cache = await caches.open(CACHE_TRACKS);
    const key = trackCacheKey(trackUrl,scope);
    await cache.put(key,new Response(bytes,{status:200,headers}));
    if (epoch!==privateEpoch) { await cache.delete(key); reply(false,'La sesión cambió.'); return; }
    const keys = (await cache.keys()).filter(request=>new URL(request.url).searchParams.get('_account')===scope);
    let total=0; const sizes=[];
    for(const request of keys){const item=await cache.match(request);const size=Number(item?.headers.get('Content-Length') || 0);sizes.push(size);total+=size;}
    while(keys.length>8 || total>200*1024*1024){total-=sizes.shift();await cache.delete(keys.shift());}
    reply(true,'Pista guardada en este dispositivo.');
  } catch (_) { reply(false,'No se pudo guardar la pista. Revisa tu conexión.'); }
}
async function listSavedTracks(scope) {
  const cache=await caches.open(CACHE_TRACKS),tracks=[];
  const decode=value=>{try{return decodeURIComponent(value || '');}catch(_){return '';}};
  for(const request of await cache.keys()){
    const parsed=new URL(request.url); if(parsed.searchParams.get('_account')!==scope)continue;
    const item=await cache.match(request);if(!item)continue;
    tracks.push({id:parsed.searchParams.get('id'),title:decode(item.headers.get('X-MusicMad-Title')) || 'Pista guardada',artist:decode(item.headers.get('X-MusicMad-Artist')) || 'MusicMad',bytes:Number(item.headers.get('Content-Length') || 0)});
  }
  return tracks;
}
async function audioFromCache(request, scope) {
  if (!scope) return new Response('Inicia sesión para escuchar pistas guardadas.', { status: 503 });
  const cache = await caches.open(CACHE_TRACKS);
  const response = await cache.match(trackCacheKey(request.url, scope));
  if (!response) return new Response('Pista no guardada sin conexión.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  const range = request.headers.get('Range');
  if (!range) return response;
  const data = await response.arrayBuffer();
  const size = data.byteLength;
  const match = /^bytes=(\d*)-(\d*)$/.exec(range.trim());
  if (!match || (!match[1] && !match[2])) return new Response(null, { status: 416, headers: { 'Content-Range': `bytes */${size}` } });
  let start = match[1] ? Number(match[1]) : Math.max(0, size - Number(match[2]));
  let end = match[1] ? (match[2] ? Math.min(size - 1, Number(match[2])) : size - 1) : size - 1;
  if ((!match[1] && Number(match[2]) < 1) || start >= size || end < start) return new Response(null, { status: 416, headers: { 'Content-Range': `bytes */${size}` } });
  const headers = new Headers(response.headers);
  headers.set('Accept-Ranges', 'bytes');
  headers.set('Content-Range', `bytes ${start}-${end}/${size}`);
  headers.set('Content-Length', String(end - start + 1));
  return new Response(data.slice(start, end + 1), { status: 206, headers });
}


function staticRequest(request) {
  const target=new URL(request.url), base=new URL(self.registration.scope);
  if (request.method!=='GET'||target.origin!==base.origin||!target.pathname.startsWith(base.pathname)) return false;
  const path=target.pathname.slice(base.pathname.length);
  return SHELL.includes(path) || /^(?:CSS|js|img)\/[a-zA-Z0-9_./-]+\.(?:css|js|png|jpe?g|webp|avif|svg|ico)$/.test(path);
}
function cacheableStatic(response) {
  const type=response?.headers.get('Content-Type')||'';
  const policy=response?.headers.get('Cache-Control')||'';
  return response?.status===200&&!response.redirected&&response.type==='basic'
    && !/(?:no-store|private)/i.test(policy)
    && /^(?:text\/css|(?:application|text)\/javascript|image\/|font\/woff2|application\/manifest\+json)/i.test(type);
}
self.addEventListener('fetch', event => {
  const request=event.request, target=new URL(request.url);
  if (request.method!=='GET'||target.origin!==self.location.origin) return;
  if (target.pathname.endsWith('/audio.php')) {
    event.respondWith(fetch(request).catch(()=>audioFromCache(request,/^[a-f0-9]{64}$/.test(target.searchParams.get('_scope')||'') ? target.searchParams.get('_scope') : scopes.get(event.clientId)))); return;
  }
  if (request.mode==='navigate') {
    event.respondWith(fetch(request).catch(async()=>await caches.match(url(target.pathname.endsWith('/biblioteca.html') ? 'biblioteca.html' : (target.pathname.endsWith('/pendientes.html')?'pendientes.html':'offline.html')),{cacheName:CACHE_SHELL})||new Response('Sin conexión',{status:503}))); return;
  }
  // PHP, API, uploads y cualquier ruta no explícitamente pública no se guardan.
  if (!staticRequest(request)) return;
  event.respondWith((async()=>{
    const cache=await caches.open(CACHE_RUNTIME);
    const saved=await caches.match(request,{cacheName:CACHE_SHELL})||await cache.match(request);
    const network=fetch(request).then(async response=>{
      if (cacheableStatic(response)) {
        await cache.put(request,response.clone());
        const keys=await cache.keys();
        while(keys.length>80) await cache.delete(keys.shift());
      }
      return response;
    }).catch(()=>null);
    event.waitUntil(network.then(()=>{}));
    return saved||await network||new Response('',{status:504});
  })());
});


self.addEventListener('sync',event=>{if(event.tag==='musicmad-actions')event.waitUntil(self.MusicMadPending.sync().then(async result=>{for(const client of await self.clients.matchAll({type:'window'}))client.postMessage({type:'PENDING_SYNCED',result});}));});


function safePushDestination(value){return typeof value==='string' && /^(?:mensajes\.php(?:\?con=[1-9][0-9]{0,9})?|notificaciones\.php|avisos-dispositivo\.php|mensajes(?:\/[a-f0-9]{32})?|notificaciones|avisos-dispositivo)$/.test(value)?value:'notificaciones.php';}
self.addEventListener('push',event=>{
 event.waitUntil((async()=>{
  let data;try{data=event.data?.json();}catch(_){return;}
  const binding=await self.MusicMadPushState.read().catch(()=>null);
  if(!binding || !/^[a-f0-9]{64}$/.test(data?.token || '') || binding.token!==data.token)return;
  const destination=safePushDestination(data.destino);
  const pushLocale=Object.prototype.hasOwnProperty.call(self.MusicMadPushTexts,data.idioma)?data.idioma:'es';
  const pushText=key=>self.MusicMadPushTexts[pushLocale][key];
  await self.registration.showNotification('MusicMad',{body:pushText(destination==='avisos-dispositivo.php'?'Tu prueba de avisos está lista.':destination.startsWith('mensajes')?'Tienes un nuevo mensaje.':'Hay novedades en tu comunidad.'),icon:url('img/icon-192.png'),badge:url('img/icon-192.png'),tag:destination.startsWith('mensajes')?'musicmad-mensajes':'musicmad-avisos',data:{destination},renotify:false});
  const currentBinding=await self.MusicMadPushState.read().catch(()=>null);
  if(currentBinding?.token===data.token&&self.navigator?.setAppBadge)await self.navigator.setAppBadge().catch(()=>{});
 })());
});
self.addEventListener('notificationclick',event=>{
 event.notification.close();event.waitUntil((async()=>{
  const destination=safePushDestination(event.notification.data?.destination);
  const target=url(destination);const windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  const same=windows.find(client=>client.url.startsWith(self.registration.scope));
  if(same){await same.navigate(target);await same.focus();}else await self.clients.openWindow(target);
 })());
});

SHELL.push('js/i18n.js','assets/i18n/es.js','assets/i18n/en.js','assets/i18n/pt-BR.js','assets/i18n/fr.js','assets/i18n/de.js','assets/i18n/it.js','assets/i18n/ja.js','assets/i18n/ko.js','assets/i18n/zh-CN.js');
