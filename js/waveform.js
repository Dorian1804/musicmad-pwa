/* No audio is downloaded for a waveform until the listener requests it. */
(() => {
 const states=new WeakMap();let controllers=new Set();
 const csrf=()=>document.querySelector('meta[name=musicmad-csrf]')?.content||'';
 const audio=()=>document.querySelector('[data-global-audio]');
 const matches=state=>document.querySelector('[data-global-player]')?.dataset.trackId===String(state.id);
 const label=seconds=>{const ms=Math.round(seconds*1000);return Math.floor(ms/60000)+':'+String(Math.floor(ms/1000)%60).padStart(2,'0')+'.'+String(ms%1000).padStart(3,'0');};
 async function json(url,options={}){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),12000);controllers.add(controller);try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,...options});const body=await r.json();if(!r.ok||!body.ok)throw new Error(body.mensaje||'No se pudo cargar la pista.');return body;}finally{controllers.delete(controller);clearTimeout(timer);}
 }
 function draw(state){
  if(!state.root.isConnected||!state.peaks)return;const canvas=state.root.querySelector('canvas'),ctx=canvas.getContext('2d');if(!ctx)return;
  const width=Math.max(1,canvas.clientWidth),height=72,dpr=Math.min(2,devicePixelRatio||1);canvas.width=width*dpr;canvas.height=height*dpr;ctx.scale(dpr,dpr);ctx.clearRect(0,0,width,height);
  const progress=matches(state)?Math.min(1,audio().currentTime/state.duration):0;const bars=Math.min(160,Math.floor(width/4));
  for(let i=0;i<bars;i++){let amplitude=0;const a=Math.floor(i*512/bars),b=Math.max(a+1,Math.floor((i+1)*512/bars));for(let k=a;k<b;k++)amplitude=Math.max(amplitude,state.peaks[k]);const h=Math.max(1,amplitude/1000*64);ctx.fillStyle=i/bars<=progress?'#159b83':'#8a789d';ctx.fillRect(i*width/bars,(height-h)/2,Math.max(1,width/bars-2),h);}
 }
 function select(state,seconds){state.selected=Math.max(0,Math.min(state.duration,seconds));state.root.querySelector('[data-wave-position]').textContent=label(state.selected)+' / '+label(state.duration);state.root.querySelector('input[type=range]').value=String(Math.round(state.selected/state.duration*1000));state.root.querySelector('[data-wave-jump]').disabled=false;const value=state.root.closest('.post')?.querySelector('[data-audio-time-value]');if(value){value.value=state.selected.toFixed(3);const capture=value.closest('form')?.querySelector('[data-capture-audio-time]');if(capture)capture.textContent='✓ Momento '+label(state.selected);} }
 async function samples(state,info){
  if(!info.calculo_local)throw new Error('Esta pista necesita una forma de onda preparada en el servidor. Puedes seguir escuchando y marcar un momento.');
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);controllers.add(controller);
  try{
   const r=await fetch('audio.php?id='+state.id,{credentials:'same-origin',cache:'no-store',signal:controller.signal});if(!r.ok||!r.body||!(r.headers.get('Content-Type')||'').startsWith('audio/')||(r.redirected&&new URL(r.url).origin!==location.origin))throw new Error('No se pudo descargar el audio.');const length=Number(r.headers.get('Content-Length'));if(length>10*1024*1024)throw new Error('Pista demasiado grande para calcular en este teléfono.');
   const reader=r.body.getReader(),parts=[];let bytes=0;while(true){const part=await reader.read();if(part.done)break;bytes+=part.value.length;if(bytes>10*1024*1024){await reader.cancel();throw new Error('Pista demasiado grande para calcular aquí.');}parts.push(part.value);}const data=new Uint8Array(bytes);let offset=0;for(const part of parts){data.set(part,offset);offset+=part.length;}
   const Offline=window.OfflineAudioContext||window.webkitOfflineAudioContext;if(!Offline)throw new Error('Tu navegador no permite calcular esta forma de onda.');const context=new Offline(1,1,16000);const decoded=await context.decodeAudioData(data.buffer.slice(0));if(decoded.duration>300||decoded.numberOfChannels>2)throw new Error('Esta pista requiere el cálculo en servidor.');if(!state.root.isConnected)return null;
   const channels=[];for(let c=0;c<decoded.numberOfChannels;c++)channels.push(decoded.getChannelData(c));const peaks=window.MusicMadWaveMath.peaks(channels);const wave={picos:peaks,duracion:decoded.duration};
   if(info.propia&&crypto.subtle){const digest=await crypto.subtle.digest('SHA-256',data);const sha=[...new Uint8Array(digest)].map(b=>b.toString(16).padStart(2,'0')).join('');if(sha===info.sha256){try{await json('onda.php',{method:'POST',headers:{'Content-Type':'application/json','X-MusicMad-CSRF':csrf()},body:JSON.stringify({id:state.id,picos:peaks,duracion:decoded.duration,sha256:sha})});}catch(_){state.root.querySelector('[data-wave-status]').textContent='Forma de onda calculada. No se guardó; puedes reintentar.';}}}
   return wave;
  }finally{clearTimeout(timer);controllers.delete(controller);}
 }
 async function load(root){
  if(states.get(root)?.busy)return;const state=states.get(root)||{root,id:Number(root.dataset.waveform),busy:false};states.set(root,state);state.busy=true;const button=root.querySelector('[data-wave-load]'),status=root.querySelector('[data-wave-status]');button.disabled=true;root.setAttribute('aria-busy','true');status.textContent='Leyendo la forma de onda…';
  try{const info=await json('onda.php?id='+state.id);const wave=info.onda||await samples(state,info);if(!root.isConnected||!wave)return;state.peaks=wave.picos;state.duration=wave.duracion;root.querySelector('[data-wave-view]').hidden=false;button.hidden=true;status.textContent='Toca la onda o mueve el control para elegir un momento.';select(state,0);draw(state);}
  catch(error){if(root.isConnected)status.textContent=navigator.onLine?error.message:'Sin conexión. Reintenta cuando vuelva internet.';}finally{state.busy=false;button.disabled=false;root.removeAttribute('aria-busy');}
 }
 document.addEventListener('click',event=>{const loadButton=event.target.closest('[data-wave-load]');if(loadButton){load(loadButton.closest('[data-waveform]'));return;}const root=event.target.closest('[data-waveform]'),state=root&&states.get(root);if(!state?.peaks)return;if(event.target.matches('canvas')){const box=event.target.getBoundingClientRect();select(state,(event.clientX-box.left)/box.width*state.duration);}if(event.target.closest('[data-wave-jump]'))document.dispatchEvent(new CustomEvent('musicmad:seek',{detail:{id:String(state.id),second:state.selected}}));});
 document.addEventListener('input',event=>{if(!event.target.matches('[data-waveform] input[type=range]'))return;const state=states.get(event.target.closest('[data-waveform]'));if(state?.peaks)select(state,Number(event.target.value)*state.duration/1000);});
 document.addEventListener('timeupdate',event=>{if(event.target!==audio())return;document.querySelectorAll('[data-waveform]').forEach(root=>{const state=states.get(root);if(state?.peaks&&matches(state))draw(state);});},true);
 let resizeTimer;addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>document.querySelectorAll('[data-waveform]').forEach(root=>{const s=states.get(root);if(s?.peaks)draw(s);}),150);});
 const stop=()=>{controllers.forEach(c=>c.abort());controllers.clear();};document.addEventListener('musicmad:beforepage',stop);document.addEventListener('musicmad:session',stop);
})();
