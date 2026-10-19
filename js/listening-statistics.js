/* Playback progress only. No download/preload counters, fingerprints or offline queue. */
(() => {
 let states=new WeakMap(),generation=0;const active=new Set();
 const scope=()=>document.querySelector('meta[name="musicmad-account"]')?.content||'';
 const csrf=()=>document.querySelector('meta[name="musicmad-csrf"]')?.content||'';
 const nonce=()=>[...crypto.getRandomValues(new Uint8Array(16))].map(x=>x.toString(16).padStart(2,'0')).join('');
 function source(media){try{const url=new URL(media.currentSrc||media.src,location.href);if(url.origin!==location.origin)return null;const supplied=media.dataset.analyticsPub;if(supplied&&/^[1-9]\d{0,9}$/.test(supplied)&&url.pathname.endsWith('/clip-medio.php'))return {id:Number(supplied),url:url.href};if(!/\/(?:audio|video|pista-publica)\.php$/.test(url.pathname)||!/^\d{1,10}$/.test(url.searchParams.get('id')||''))return null;return {id:Number(url.searchParams.get('id')),url:url.href};}catch(_){return null;}}
 function allowed(s){return s.generation===generation&&s.scope===scope()&&!!s.scope;}
 function resetClock(s){s.position=s.media.currentTime;s.wall=performance.now();}
 function sample(s){const now=performance.now();if(!s.done&&s.playing&&!s.media.seeking&&!s.media.muted&&s.media.volume>0){s.elapsed+=window.MusicMadListeningMath.advance(s.position,s.media.currentTime,now-s.wall,s.media.playbackRate);s.elapsed=Math.min(s.elapsed,21600000);}s.position=s.media.currentTime;s.wall=now;}
 async function request(s,action){
  if(!allowed(s)||!navigator.onLine)throw new Error('offline');const controller=new AbortController();s.controller=controller;const timeout=setTimeout(()=>controller.abort(),10000);
  try{const response=await fetch('escucha.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-MusicMad-CSRF':csrf(),'X-MusicMad-Account':s.scope},body:JSON.stringify({accion:action,publicacion:s.id,solicitud:s.nonce,escuchados_ms:Math.floor(s.elapsed)}),signal:controller.signal});const result=await response.json();if(!allowed(s))throw new Error('account');if(!response.ok||!result.ok){const error=new Error('unavailable');error.status=response.status;throw error;}return result;}finally{clearTimeout(timeout);if(s.controller===controller)s.controller=null;}
 }
 function close(s){clearTimeout(s.timer);s.controller?.abort();s.done=true;active.delete(s);}
 async function confirm(s){
  if(s.done||s.confirming||!allowed(s)||!s.ready||s.elapsed<s.threshold)return;
  s.confirming=true;try{const result=await request(s,'confirmar');if(result.confirmada)close(s);else if(Number.isInteger(result.esperar_ms)&&result.esperar_ms>0)s.timer=setTimeout(()=>confirm(s),Math.max(250,Math.min(30000,result.esperar_ms)));}
  catch(_){if(allowed(s)&&++s.failures<4)s.timer=setTimeout(()=>confirm(s),8000);else close(s);}
  finally{s.confirming=false;}
 }
 async function begin(s){
  if(s.starting||s.ready||s.done||!allowed(s))return;s.starting=true;
  try{const result=await request(s,'iniciar');if(result.ignorada||result.confirmada){close(s);return;}if(!Number.isInteger(result.umbral_ms)||result.umbral_ms<250||result.umbral_ms>5000)throw new Error('threshold');s.threshold=result.umbral_ms;s.ready=true;confirm(s);}
  catch(_){if(allowed(s)&&++s.starts<3)s.timer=setTimeout(()=>begin(s),30000);else close(s);}
  finally{s.starting=false;}
 }
 document.addEventListener('playing',event=>{
  const media=event.target;if(!media.matches?.('audio,video')||!scope()||!navigator.onLine)return;const src=source(media);if(!src?.id)return;
  let s=states.get(media);if(!s||s.url!==src.url||!allowed(s)||performance.now()-s.created>600000){if(s)close(s);s={media,id:src.id,url:src.url,nonce:nonce(),scope:scope(),generation,created:performance.now(),elapsed:0,playing:true,done:false,ready:false,starts:0,failures:0};states.set(media,s);active.add(s);}
  if(s.done)return;s.playing=true;resetClock(s);begin(s);
 },true);
 document.addEventListener('timeupdate',event=>{const s=states.get(event.target);if(!s)return;sample(s);confirm(s);},true);
 for(const eventName of ['pause','ended','waiting'])document.addEventListener(eventName,event=>{const s=states.get(event.target);if(!s)return;sample(s);s.playing=false;confirm(s);},true);
 for(const eventName of ['seeking','seeked','ratechange','volumechange'])document.addEventListener(eventName,event=>{const s=states.get(event.target);if(s)resetClock(s);},true);
 document.addEventListener('emptied',event=>{const s=states.get(event.target);if(s&&!s.ready)close(s);},true);
 document.addEventListener('musicmad:beforepage',()=>{for(const s of active)if(!s.media.matches('[data-global-audio]'))close(s);});
 document.addEventListener('musicmad:session',()=>{generation++;for(const s of active)close(s);states=new WeakMap();});
})();
