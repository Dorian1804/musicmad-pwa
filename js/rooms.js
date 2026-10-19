/* Short HTTP requests: PHP/MySQL hosting needs no persistent socket process. */
(() => {
 let current=null;
 const nonce=()=>[...crypto.getRandomValues(new Uint8Array(16))].map(x=>x.toString(16).padStart(2,'0')).join('');
 const audio=()=>document.querySelector('[data-global-audio]');
 const matches=state=>document.querySelector('[data-global-player]')?.dataset.trackId===String(state.snapshot?.pista.id);
 const status=(state,message)=>{if(state.root.isConnected)state.root.querySelector('[data-room-status]').textContent=message;};
 function disconnect(state,message){state.armed=false;if(audio())delete audio().dataset.roomSync;if(matches(state))audio()?.pause();status(state,message);}
 function stop(){if(!current)return;clearTimeout(current.timer);current.controllers.forEach(controller=>controller.abort());if(audio())delete audio().dataset.roomSync;current=null;}
 async function request(state,data=null,before=0){
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),8000);state.controllers.add(controller);
  const url=new URL('sala-datos.php',document.baseURI || location.href);url.searchParams.set('id',String(state.id));if(before)url.searchParams.set('antes',String(before));else if(state.after)url.searchParams.set('despues',String(state.after));const started=performance.now();
  try{const response=await fetch(url,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:data?{'Content-Type':'application/json','X-MusicMad-CSRF':document.querySelector('meta[name=musicmad-csrf]')?.content||''}:{},body:data?JSON.stringify({id:state.id,despues:state.after,...data}):undefined});let result;try{result=await response.json();}catch(_){result={mensaje:[403,419].includes(response.status)?'Tu sesión cambió. Recarga la sala.':'No se pudo consultar la sala.'};}if(!response.ok||!result.ok){const error=new Error(result.mensaje||'No se pudo consultar la sala.');error.status=response.status;throw error;}if(current!==state||state.closed||!state.root.isConnected)return null;return {sala:result.sala,delay:Math.min(2,(performance.now()-started)/2000)};}finally{clearTimeout(timeout);state.controllers.delete(controller);}
 }
 const clock=value=>{const sec=Math.max(0,Math.floor(value));return Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0');};
 function target(state){if(!state.snapshot)return 0;return Math.max(0,Math.min(state.snapshot.pista.duracion,state.base+(state.snapshot.reproduccion==='reproduciendo'?(performance.now()-state.received)/1000:0)));}
 function sync(state){
  if(!state.snapshot)return;state.root.querySelector('[data-room-clock]').textContent=clock(target(state))+' / '+clock(state.snapshot.pista.duracion);
  if(!state.armed)return;if(!matches(state)){disconnect(state,'Elegiste otra pista. Toca Conectarme al sonido para volver.');return;}const a=audio();if(!a||a.readyState<1)return;
  const pos=target(state);if(Math.abs(a.currentTime-pos)>1.1)a.currentTime=pos;
  if(state.snapshot.reproduccion==='pausada'){if(!a.paused){state.expectedPause=true;a.pause();}status(state,'Conectado. El anfitrión tiene la sala en pausa.');}
  else{status(state,'Sonido sincronizado con la sala.');if(a.paused)a.play().catch(()=>{state.armed=false;status(state,'Toca Conectarme al sonido para activar la reproducción.');});}
 }
 function messages(state,list,older=false){
  const container=state.root.querySelector('[data-room-messages]'),nearEnd=container.scrollHeight-container.scrollTop-container.clientHeight<70,oldHeight=container.scrollHeight,fragment=document.createDocumentFragment();
  for(const msg of list){if(state.ids.has(msg.id))continue;state.ids.add(msg.id);state.root.querySelector('[data-room-empty]')?.remove();const node=document.createElement('article');const author=document.createElement('strong');author.textContent=msg.autor;const body=document.createElement('p');body.textContent=msg.texto;const date=document.createElement('time');date.dateTime=msg.created_at.replace(' ','T')+'Z';const instant=new Date(date.dateTime);date.textContent=Number.isNaN(instant.getTime())?'':new Intl.DateTimeFormat('es-MX',{hour:'2-digit',minute:'2-digit'}).format(instant);node.dataset.roomMessageId=String(msg.id);node.append(author,body,date);fragment.append(node);}
  if(older){container.prepend(fragment);container.scrollTop+=container.scrollHeight-oldHeight;}else{container.append(fragment);if(nearEnd)container.scrollTop=container.scrollHeight;}
  let more=state.root.querySelector('[data-room-older]');if(!more){more=document.createElement('button');more.type='button';more.dataset.roomOlder='';more.className='btn btn-ghost';more.textContent='Ver mensajes anteriores';container.before(more);}if(older||state.after===0)more.hidden=list.length<50;
 }
 function receive(state,result,older=false){if(!result)return;const room=result.sala;messages(state,room.mensajes,older);state.after=Math.max(state.after,Number(room.hasta)||0);if(state.snapshot&&(room.revision<state.snapshot.revision||(room.revision===state.snapshot.revision&&room.servidor_ms<state.snapshot.servidor_ms)))return;state.snapshot=room;state.received=performance.now();state.base=Math.min(room.pista.duracion,room.posicion+(room.reproduccion==='reproduciendo'?result.delay:0));const names=room.integrantes.slice(0,3).map(member=>member.nombre.length>24?member.nombre.slice(0,24)+'…':member.nombre);state.root.querySelector('[data-room-members]').textContent=names.length?room.integrantes.length+' en la sala · '+names.join(', ')+(room.integrantes.length>3?' y '+(room.integrantes.length-3)+' más':''):'La sala está lista.';sync(state);}
 async function poll(state){
  if(current!==state||state.closed||state.polling)return;state.polling=true;try{receive(state,await request(state));state.failures=0;}catch(error){if(current!==state)return;if([401,403,404].includes(error.status)){state.closed=true;disconnect(state,error.message);return;}state.failures=Math.min(5,state.failures+1);status(state,navigator.onLine?'Reconectando la sala…':'Sin conexión. Al volver, el sonido se ajustará a la sala.');}finally{state.polling=false;if(current===state&&!state.closed)state.timer=setTimeout(()=>poll(state),state.failures?Math.min(30000,2500*2**state.failures):(document.hidden?12000:2500));}
 }
 function start(){stop();const root=document.querySelector('[data-listening-room]');if(!root)return;const state={root,id:Number(root.dataset.roomId),host:root.dataset.roomHost==='1',after:0,ids:new Set(),snapshot:null,armed:false,connecting:false,expectedPause:false,closed:false,failures:0,timer:null,controllers:new Set(),busy:false,polling:false};current=state;poll(state);}
 async function control(state,playing,pos){if(!state.host||!state.snapshot||state.busy)return;state.busy=true;state.root.querySelector('.room-host-controls')?.setAttribute('aria-busy','true');try{receive(state,await request(state,{accion:'control',revision:state.snapshot.revision,reproduccion:playing,posicion:pos}));}catch(error){status(state,error.message);if(error.status===409){try{receive(state,await request(state));}catch(_){}}}finally{state.busy=false;state.root.querySelector('.room-host-controls')?.removeAttribute('aria-busy');}}
 document.addEventListener('click',event=>{
  const state=current;if(!state)return;
  if(event.target.closest('[data-room-listen]')){state.armed=true;state.connecting=true;if(audio())audio().dataset.roomSync=String(state.id);queueMicrotask(()=>{if(current===state&&matches(state)&&audio()?.readyState>=1){state.connecting=false;sync(state);}});return;}
  const button=event.target.closest('[data-room-control]');if(button){event.preventDefault();control(state,button.dataset.roomControl,matches(state)?audio().currentTime:target(state));}
  if(event.target.closest('[data-room-seek-button]')){const value=Number(state.root.querySelector('[data-room-seek]').value);if(!Number.isFinite(value)||value<0||value>state.snapshot?.pista.duracion){status(state,'Elige un momento dentro de la pista.');return;}control(state,state.snapshot?.reproduccion||'pausada',value);}
  if(event.target.closest('[data-room-older]')){const ids=[...state.ids];if(!ids.length||state.historyBusy)return;state.historyBusy=true;request(state,null,Math.min(...ids)).then(result=>receive(state,result,true)).catch(error=>status(state,error.message)).finally(()=>state.historyBusy=false);}
 });
 document.addEventListener('submit',async event=>{
  const form=event.target;if(!form.matches?.('[data-room-message-form]')||!current)return;event.preventDefault();const state=current;if(state.sending)return;const text=form.querySelector('[name=texto]'),id=form.querySelector('[name=solicitud]');state.sending=true;form.setAttribute('aria-busy','true');const sent=text.value;
  try{receive(state,await request(state,{accion:'mensaje',texto:sent,solicitud:id.value}));if(text.value===sent)text.value='';id.value=nonce();}catch(error){status(state,error.message+' Tu mensaje se conserva para reintentar.');}finally{state.sending=false;form.removeAttribute('aria-busy');}
 });
 document.addEventListener('loadedmetadata',event=>{if(current&&event.target===audio()&&matches(current)){current.connecting=false;sync(current);}},true);
 document.addEventListener('pause',event=>{const state=current;if(!state||event.target!==audio()||!matches(state))return;if(state.expectedPause){state.expectedPause=false;return;}if(event.target.ended||(Number.isFinite(event.target.duration)&&event.target.currentTime>=event.target.duration-.15))return;if(state.armed&&!state.connecting){state.armed=false;delete event.target.dataset.roomSync;status(state,'Pausaste tu sonido. Toca Conectarme al sonido para volver a seguir la sala.');}},true);
 document.addEventListener('error',event=>{if(current&&event.target===audio()&&matches(current))disconnect(current,'No se pudo reproducir esta pista. Revisa si el archivo sigue disponible.');},true);
 document.addEventListener('visibilitychange',()=>{if(current&&!document.hidden){clearTimeout(current.timer);poll(current);}});
 document.addEventListener('musicmad:beforepage',stop);document.addEventListener('musicmad:session',stop);document.addEventListener('musicmad:page',start);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
