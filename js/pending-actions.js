(() => {
 const store=window.MusicMadPending;if(!store)return;
 const owner=()=>Number(document.querySelector('meta[name=musicmad-user]')?.content||0);
 const toast=message=>document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{message}}));
 const id=()=>[...crypto.getRandomValues(new Uint8Array(16))].map(value=>value.toString(16).padStart(2,'0')).join('');
 let flushRunning=false,previousOwner=owner();const attempts=new WeakMap();
 function confirmed(result){
  if(result.owner!==owner())return;
  for(const {receipt} of result.confirmed||[]){
   const count=document.querySelector('#pub-'+receipt.publicacion+' [data-applause-count]');
   if(count&&Number.isInteger(receipt.aplausos))count.textContent=String(receipt.aplausos);
   const row=document.querySelector('[data-pending-request="'+receipt.solicitud+'"]');
   if(row){row.classList.remove('is-pending');const status=row.querySelector('[data-pending-label]');if(status)status.textContent='Publicado';}
  }
 }
 async function update(){
  const uid=owner();let count=0;try{count=uid?(await store.list(uid)).length:0;}catch(_){}
  document.querySelectorAll('[data-pending-count]').forEach(node=>{node.textContent=String(count);node.hidden=!count;});
 }
 async function flush(){
  if(flushRunning||!navigator.onLine||!owner())return;
  flushRunning=true;
  try{if(!(await store.list(owner())).some(item=>item.status==='pending'))return;const result=await store.sync();confirmed(result);}
  catch(error){if(['SecurityError','InvalidStateError','UnknownError','QuotaExceededError','NotFoundError','ReferenceError','NotSupportedError'].includes(error?.name))return;if(navigator.onLine)toast(error instanceof TypeError||error.name==='AbortError'?'Se interrumpió la conexión. Las acciones siguen pendientes.':error.message);}
  finally{flushRunning=false;update();}
 }
 document.addEventListener('submit',async event=>{
  const form=event.target;if(!form.matches('form[data-sync-action]')||!form.reportValidity())return;
  if(document.querySelector('meta[name=musicmad-sync]')?.content!=='1')return;
  event.preventDefault();event.stopImmediatePropagation();
  if(form.dataset.syncBusy==='1')return;form.dataset.syncBusy='1';
  const submit=event.submitter||form.querySelector('button[type=submit]'),oldDisabled=submit?.disabled;if(submit)submit.disabled=true;
  try{
   const fields=new FormData(form),kind=form.dataset.syncAction,action={solicitud:id(),accion:kind,publicacion:Number(fields.get('id'))};
   if(['aplaudir','guardar'].includes(kind))action.estado=submit?.getAttribute('aria-pressed')!=='true';
   else{action.texto=String(fields.get('texto')||'');if(kind==='responder')action.comentario=Number(fields.get('comentario'));}
   const payload=JSON.stringify({...action,solicitud:undefined}),previous=attempts.get(form);if(previous?.payload===payload && previous.owner===owner())action.solicitud=previous.id;else attempts.set(form,{payload,owner:owner(),id:action.solicitud});
   let direct=null;
   try{await store.enqueue(owner(),action);}
   catch(error){
    const storageFailure=['SecurityError','InvalidStateError','UnknownError','QuotaExceededError','NotFoundError','ReferenceError','NotSupportedError'].includes(error?.name);
    if(!navigator.onLine || !storageFailure)throw error;
    direct=await store.sendDirect(owner(),action);confirmed(direct);
   }
   attempts.delete(form);
   if(['aplaudir','guardar'].includes(kind)){submit.setAttribute('aria-pressed',String(action.estado));submit.classList.toggle('on',action.estado);submit.setAttribute('aria-label',kind==='aplaudir'?(action.estado?'Quitar aplauso':'Aplaudir publicación'):(action.estado?'Quitar de guardados':'Guardar publicación'));if(kind==='aplaudir'&&action.estado)document.dispatchEvent(new CustomEvent('musicmad:applause',{detail:{button:submit}}));}
   else{
    const input=form.querySelector('[name=texto]');if(input && input.value===action.texto)input.value='';
    const root=kind==='responder'?form.parentElement.querySelector('[data-parent-id] [data-comment-list]'):form.parentElement.querySelector('[data-comment-thread] [data-comment-list]');
    if(root){const row=document.createElement(kind==='responder'?'div':'li'),copy=document.createElement('div'),name=document.createElement('strong'),text=document.createElement('span'),status=document.createElement('small');
     row.className='pending-comment'+(direct?'':' is-pending');row.dataset.pendingRequest=action.solicitud;name.textContent='Tú';text.textContent=' '+action.texto;status.dataset.pendingLabel='';status.textContent=direct?'Publicado':'Pendiente de envío';copy.append(name,text,status);row.append(copy);root.append(row);
    }
   }
   if(direct)return;
   if(!navigator.onLine)toast('Acción pendiente. Se enviará cuando vuelva internet.');update();
   navigator.serviceWorker?.ready.then(registration=>registration.sync?.register('musicmad-actions')).catch(()=>{});
   await flush();
  }catch(error){toast(error instanceof TypeError||error.name==='AbortError'?'No pudimos enviar. Conservamos tu borrador; reintenta con conexión.':error.message || 'Este navegador no puede guardar acciones. Conservamos tu borrador.');}
  finally{form.dataset.syncBusy='0';if(submit)submit.disabled=Boolean(oldDisabled);}
 },true);
 document.addEventListener('musicmad:page',()=>{update();flush();});
 document.addEventListener('musicmad:session',()=>{const next=owner();if(previousOwner && next && previousOwner!==next)store.clear().then(update).catch(()=>{});previousOwner=next;});
 document.addEventListener('submit',event=>{if(event.target.action?.includes('logout.php'))store.clear().catch(()=>{});},true);
 document.addEventListener('click',event=>{if(event.target.closest('[data-pending-sync]'))flush();});
 navigator.serviceWorker?.addEventListener('message',event=>{if(event.data?.type==='PENDING_SYNCED'){confirmed(event.data.result);update();}});
 addEventListener('online',flush);addEventListener('pageshow',()=>{update();flush();});
 setInterval(()=>{if(document.visibilityState==='visible')flush();},60000);
 update();
})();
