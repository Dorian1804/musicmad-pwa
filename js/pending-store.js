/* Shared by the page and service worker. No REPLACE_WITH_LOCAL_VALUEs, cookies or CSRF are stored. */
((root) => {
 const fetchBounded=async(...args)=>{const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);try{return await fetch(args[0],{...(args[1]||{}),signal:controller.signal});}finally{clearTimeout(timer);}};
 const database='musicmad-pending-actions',storeName='actions';let syncing=null;
 const open=()=>new Promise((resolve,reject)=>{
  const request=indexedDB.open(database,1);let finished=false;
  const timer=setTimeout(()=>{finished=true;const error=new Error('No se pudo abrir el almacenamiento local.');error.name='InvalidStateError';reject(error);},8000);
  request.onupgradeneeded=()=>{const store=request.result.createObjectStore(storeName,{keyPath:'key'});store.createIndex('owner','owner');};
  request.onsuccess=()=>{clearTimeout(timer);if(finished){request.result.close();return;}finished=true;resolve(request.result);};
  request.onerror=()=>{clearTimeout(timer);if(!finished){finished=true;reject(request.error);}};
 });
 async function operation(write,callback){
  const db=await open();try{return await new Promise((resolve,reject)=>{const tx=db.transaction(storeName,write?'readwrite':'readonly');let value;const request=callback(tx.objectStore(storeName));if(request)request.onsuccess=()=>value=request.result;tx.oncomplete=()=>resolve(value);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error||new Error('No se pudo guardar.'));});}finally{db.close();}
 }
 const list=owner=>operation(false,store=>owner?store.index('owner').getAll(owner):store.getAll());
 const remove=key=>operation(true,store=>store.delete(key));
 async function enqueue(owner,action){
  if(!Number.isInteger(owner)||owner<1)throw new Error('Inicia sesión antes de guardar una acción.');
  const items=await list(owner);if(items.length>=50)throw new Error('Tienes 50 acciones pendientes. Conecta o elimina algunas antes de continuar.');
  const item={key:owner+':'+action.solicitud,owner,action,created:Date.now(),status:'pending'};
  await operation(true,store=>store.put(item));return item;
 }
 async function synchronize(){
  const authResponse=await fetchBounded('acciones-pendientes.php',{credentials:'same-origin',cache:'no-store'});
  if(!authResponse.ok){let detail;try{detail=await authResponse.json();}catch(_){}throw new Error(authResponse.status===401?'Inicia sesión para sincronizar tus acciones.':detail?.mensaje || 'La conexión está interrumpida. Tus acciones siguen pendientes.');}
  const auth=await authResponse.json();if(!auth.ok||!Number.isInteger(auth.usuario))throw new Error('La sesión no está disponible.');
  const items=await list(auth.usuario),ready=[];
  for(const item of items){
   if(item.created<Date.now()-7*86400000){item.status='error';item.message='Pasaron 7 días. Revisa esta acción antes de volver a enviarla.';await operation(true,store=>store.put(item));}
   else if(item.status==='pending')ready.push(item);
  }
  let sent=0;const confirmed=[];
  for(let index=0;index<ready.length;index+=20){
   const batch=ready.slice(index,index+20),response=await fetchBounded('acciones-pendientes.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-MusicMad-CSRF':auth.csrf},body:JSON.stringify({scope:auth.scope,owner:auth.usuario,acciones:batch.map(item=>item.action)})});
   const result=await response.json();
   for(const receipt of result.resultados||[]){
    const item=batch.find(item=>item.action.solicitud===receipt.solicitud);if(!item)continue;
    if(receipt.ok){await remove(item.key);confirmed.push({key:item.key,receipt});sent++;}
    else if(receipt.definitivo){item.status='error';item.message=receipt.mensaje;await operation(true,store=>store.put(item));}
   }
   if(!response.ok||!result.ok)throw new Error(result.mensaje||'La conexión se interrumpió. Reintentaremos sin duplicar.');
  }
  return {sent,confirmed,owner:auth.usuario,remaining:(await list(auth.usuario)).length};
 }
 async function sendDirect(owner,action){
  const authResponse=await fetchBounded('acciones-pendientes.php',{credentials:'same-origin',cache:'no-store'});
  if(!authResponse.ok)throw new Error('Actualiza tu sesión antes de enviar.');const auth=await authResponse.json();
  if(!auth.ok || auth.usuario!==owner)throw new Error('La cuenta cambió. Revisa tu acción.');
  const response=await fetchBounded('acciones-pendientes.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-MusicMad-CSRF':auth.csrf},body:JSON.stringify({scope:auth.scope,owner,acciones:[action]})});
  const result=await response.json(),receipt=result.resultados?.find(item=>item.solicitud===action.solicitud);
  if(!response.ok || !result.ok || !receipt?.ok)throw new Error(receipt?.mensaje || result.mensaje || 'No pudimos enviar. Conservamos tu borrador para que reintentes.');
  return {owner,sent:1,confirmed:[{key:owner+':'+action.solicitud,receipt}]};
 }
 const sync=()=>{if(!syncing)syncing=synchronize().finally(()=>syncing=null);return syncing;};
 root.MusicMadPending={list,enqueue,remove,sync,sendDirect,clear:()=>operation(true,store=>store.clear())};
})(typeof self!=='undefined'?self:window);
