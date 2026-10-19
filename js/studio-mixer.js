/* Streaming mix preview. Every source still uses its authenticated media endpoint. */
(() => {
 let current=null;
 function stop(){if(!current)return;current.playing=false;for(const media of current.root.querySelectorAll('audio'))media.pause();}
 function start(){stop();const root=document.querySelector('[data-studio-mix]');current=root?{root,base:root.querySelector('[data-mix-base]'),playing:false,busy:false}:null;}
 const selected=state=>[...state.root.querySelectorAll('[data-mix-selected]:checked')].map(box=>box.closest('[data-stem-id]').querySelector('audio'));
 function dispose(){stop();if(current?.context)current.context.close().catch(()=>{});current=null;}
 const status=(state,text)=>{state.root.querySelector('[data-mix-status]').textContent=text;};
 async function prepareSound(state,stems){
  const Context=window.AudioContext||window.webkitAudioContext;if(!Context)return;
  if(!state.context){state.context=new Context();state.master=state.context.createGain();state.master.connect(state.context.destination);state.nodes=new WeakMap();}
  const resume=state.context.resume();state.master.gain.value=1/(stems.length+1);
  for(const media of [state.base,...stems]){let gain=state.nodes.get(media);if(!gain){const source=state.context.createMediaElementSource(media);gain=state.context.createGain();source.connect(gain);gain.connect(state.master);state.nodes.set(media,gain);}gain.gain.value=media===state.base?1:Math.min(1,Math.max(0,Number(media.dataset.volume)/100));media.volume=1;}
  // Unlock selected media during the explicit gesture before awaiting metadata.
  for(const media of [state.base,...stems])if(media.readyState<1){const p=media.play();media.pause();p?.catch(()=>{});}
  await resume;
 }
 function sync(state){if(!state.playing)return;for(const stem of selected(state)){const pos=state.base.currentTime-Number(stem.dataset.offsetMs)/1000;if(pos<0||!Number.isFinite(stem.duration)||pos>=stem.duration){stem.pause();continue;}if(Math.abs(stem.currentTime-pos)>.18)stem.currentTime=pos;if(!state.context)stem.volume=Math.min(1,Math.max(0,Number(stem.dataset.volume)/100))*.5;if(stem.paused)stem.play().catch(()=>status(state,'No se pudo reproducir un aporte. Puedes abrir su pista por separado.'));}}
 async function metadata(media){if(media.readyState>=1)return;media.preload='metadata';await new Promise((resolve,reject)=>{let timer;const clear=()=>{clearTimeout(timer);media.removeEventListener('loadedmetadata',ready);media.removeEventListener('error',error);};const ready=()=>{clear();resolve();};const error=()=>{clear();reject(new Error('Una pista ya no está disponible para tu cuenta.'));};timer=setTimeout(error,12000);media.addEventListener('loadedmetadata',ready,{once:true});media.addEventListener('error',error,{once:true});media.load();});}
 document.addEventListener('click',async event=>{const state=current;if(!state)return;if(event.target.closest('[data-mix-stop]')){stop();status(state,'Mezcla en pausa.');return;}if(!event.target.closest('[data-mix-play]')||state.busy)return;state.busy=true;state.root.setAttribute('aria-busy','true');try{stop();const stems=selected(state);if(stems.length>4)throw new Error('Elige hasta cuatro aportes.');await prepareSound(state,stems);await Promise.all([state.base,...stems].map(metadata));if(current!==state||!state.root.isConnected)return;document.querySelector('[data-global-audio]')?.pause();state.base.currentTime=0;if(!state.context)state.base.volume=.5;state.playing=true;await state.base.play();sync(state);status(state,'Escuchando la base con '+stems.length+' aporte'+(stems.length===1?'':'s')+'.');}catch(error){if(current===state){stop();status(state,error.message||'No pudimos iniciar la mezcla. Reintenta.');}}finally{state.busy=false;state.root.removeAttribute('aria-busy');}});
 document.addEventListener('change',event=>{if(!event.target.matches('[data-mix-selected]')||!current)return;if(selected(current).length>4){event.target.checked=false;status(current,'Puedes mezclar hasta cuatro aportes a la vez.');}stop();});
 document.addEventListener('play',event=>{
  const state=current;if(!state||event.target.paused)return;
  if(event.target===document.querySelector('[data-global-audio]')){if(state.playing){stop();status(state,'La mezcla se pausó al elegir otra pista.');}return;}
  if(!state.root.contains(event.target)||!event.target.matches('audio')||state.playing)return;
  document.querySelector('[data-global-audio]')?.pause();
  for(const other of state.root.querySelectorAll('audio'))if(other!==event.target)other.pause();
  if(state.context){state.master.gain.value=1;const gain=state.nodes.get(event.target);if(gain)gain.gain.value=1;}
 },true);
 document.addEventListener('timeupdate',event=>{if(current&&event.target===current.base)sync(current);},true);
 document.addEventListener('pause',event=>{if(current?.playing&&event.target===current.base){stop();status(current,'Mezcla en pausa.');}},true);
 document.addEventListener('ended',event=>{if(current&&event.target===current.base){stop();status(current,'Terminó la mezcla. Conservamos todas las pistas.');}},true);
 document.addEventListener('error',event=>{if(current?.playing&&event.target.closest?.('[data-studio-mix]')){stop();status(current,'Una pista dejó de estar disponible. Revisa sus permisos.');}},true);
 document.addEventListener('musicmad:beforepage',dispose);document.addEventListener('musicmad:session',dispose);document.addEventListener('musicmad:page',start);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
