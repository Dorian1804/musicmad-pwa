(() => {
 const store=window.MusicMadPending;if(!store)return;let generation=0;
 const owner=()=>{const meta=document.querySelector('meta[name=musicmad-user]');try{return meta?Number(meta.content):Number(localStorage.getItem('musicmad.offline.owner')||0);}catch(_){return 0;}};
 const labels={aplaudir:'Aplauso',guardar:'Guardado',comentar:'Comentario',responder:'Respuesta'};
 async function render(){
  const list=document.querySelector('[data-pending-list]'),status=document.querySelector('[data-pending-status]');if(!list||!status)return;
  const current=++generation;try{
   const uid=owner(),items=uid?await store.list(uid):[];if(current!==generation||!list.isConnected)return;
   list.replaceChildren();status.textContent=items.length?items.length+' '+(items.length===1?'acción en este navegador.':'acciones en este navegador.'):'No hay acciones pendientes.';
   for(const item of items){
    const row=document.createElement('li'),copy=document.createElement('div'),title=document.createElement('strong'),message=document.createElement('p'),button=document.createElement('button');
    row.className='card';title.textContent=(labels[item.action.accion]||'Acción')+' · publicación '+item.action.publicacion;
    message.textContent=item.status==='error'?item.message:(item.action.texto||((item.action.estado?'Añadir':'Quitar')+' · se enviará al reconectar'));
    button.type='button';button.className='btn btn-ghost';button.textContent='Descartar';button.addEventListener('click',async()=>{button.disabled=true;try{await store.remove(item.key);await render();}catch(_){status.textContent='No se pudo descartar. Reintenta.';}});
    const link=document.createElement('a'),date=document.createElement('small');link.href='publicacion.php?id='+item.action.publicacion;link.textContent='Ver publicación';date.textContent=new Intl.DateTimeFormat('es-MX',{dateStyle:'medium',timeStyle:'short'}).format(new Date(item.created));copy.append(title,message,date,document.createElement('br'),link);row.append(copy,button);list.append(row);
   }
  }catch(_){status.textContent='Este navegador no permite guardar acciones sin conexión.';}
 }
 document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-review-sync]');if(!button)return;button.disabled=true;const status=document.querySelector('[data-pending-status]');
  try{const result=await store.sync();await render();status.textContent=result.sent+' '+(result.sent===1?'acción enviada. ':'acciones enviadas. ')+result.remaining+' pendientes.';}
  catch(error){status.textContent=error instanceof TypeError?'No hay conexión. Tus acciones siguen guardadas.':error.message;}
  finally{button.disabled=false;}
 });
 document.addEventListener('musicmad:page',render);navigator.serviceWorker?.addEventListener('message',event=>{if(event.data?.type==='PENDING_SYNCED')render();});render();
})();
