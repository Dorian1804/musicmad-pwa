(() => {
  let active=null, notices=false;
  const csrf=()=>document.querySelector('meta[name="musicmad-csrf"]')?.content || '';
  async function post(data,endpoint='chat-live.php') {
    const requestOwner=Number(document.querySelector('meta[name="musicmad-user"]')?.content||0);
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),12000);
    try {const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-MusicMad-CSRF':csrf()},body:JSON.stringify(data),signal:controller.signal});const result=await response.json();if(!response.ok || !result.ok)throw new Error(result.mensaje || 'No pudimos conectar. Reintenta; tu mensaje sigue aquí.');if(result.contadores&&requestOwner===Number(document.querySelector('meta[name="musicmad-user"]')?.content||0))badges(result.contadores);return result;}
    finally {clearTimeout(timer);}
  }
  function badges(counts) {
    for(const [page,label,key] of [['mensajes.php','Mensajes','mensajes'],['notificaciones.php','Notificaciones','avisos'],['perfil.php','Perfil','avisos']]) {
      document.querySelectorAll('a[href="'+page+'"]').forEach(link=>{
        if(!link.closest('.navbar,.mobile-dock'))return;
        const badge=link.querySelector('.badge,.dock-badge');const count=Math.max(0,Number(counts[key]) || 0);
        if(!count)badge?.remove();else{const node=badge || document.createElement('b');if(!badge){node.className=link.closest('.mobile-dock,.mobile-header-actions')?'dock-badge':'badge';(link.querySelector(':scope>span') || link).append(node);}node.textContent=count>99?'99+':count;}
        link.setAttribute('aria-label',label+(count?', '+count+' pendientes':''));
      });
    }
    document.dispatchEvent(new CustomEvent('musicmad:counts',{detail:{owner:Number(document.querySelector('meta[name="musicmad-user"]')?.content||0),mensajes:counts.mensajes,avisos:counts.avisos}}));
  }
  const atEnd=list=>list.scrollHeight-list.scrollTop-list.clientHeight<60;
  function addMessage(state,message,prepend=false) {
    if(state.ids.has(message.id))return;state.ids.add(message.id);state.list.querySelector('.vacio')?.remove();
    const node=document.createElement('div');node.className='burbuja '+(message.de===state.owner?'mia':'suya');node.dataset.messageId=message.id;node.dataset.messageFrom=message.de;
    if(state.group && message.de!==state.owner){const author=document.createElement('strong');author.className='chat-group-author';author.textContent=message.autor || 'Integrante';node.append(author);}
    const text=document.createElement('span');text.className='chat-text';text.textContent=message.texto;node.append(text);
    if(message.pista){const link=document.createElement('a');link.className='chat-track';link.href='publicacion.php?id='+Number(message.pista.id);link.textContent='♫ '+(message.pista.titulo || 'Escuchar pista');node.append(link);}
    const date=document.createElement('small');date.textContent=message.tiempo || message.fecha;date.title=message.fecha;const read=document.createElement('span');read.className='chat-read';date.append(read);node.append(date);
    if(prepend)state.list.prepend(node);else state.list.insertBefore(node,state.list.querySelector('#fin'));
  }
  async function seen(state) {
    if(document.hidden || !atEnd(state.list) || state.readBusy)return;
    const ids=[...state.list.querySelectorAll('[data-message-id]')].filter(node=>Number(node.dataset.messageFrom)!==state.owner).map(node=>Number(node.dataset.messageId));const until=Math.max(0,...ids);
    if(until<=state.seen)return;state.readBusy=true;
    try{await post({accion:'leer',con:state.con,hasta:until},state.transport);state.seen=until;}catch(_){}finally{state.readBusy=false;}
  }
  async function receive(state,result) {
    if(active!==state)return;const bottom=atEnd(state.list);
    result.mensajes.forEach(message=>{addMessage(state,message);state.after=Math.max(state.after,message.id);});if(bottom)state.list.scrollTop=state.list.scrollHeight;
    state.root.querySelector('.chat-connection').textContent=state.group?'Solo integrantes':result.en_linea?'En línea':'Conectado';state.root.querySelector('.chat-typing').hidden=!result.escribiendo;
    state.list.querySelectorAll('.mia[data-message-id]').forEach(node=>{node.querySelector('.chat-read').textContent=state.group?((result.cursores_lectura || []).filter(cursor=>cursor>=Number(node.dataset.messageId)).length>0?' · Leído por '+(result.cursores_lectura || []).filter(cursor=>cursor>=Number(node.dataset.messageId)).length:''):Number(node.dataset.messageId)<=result.leido?' · Leído':'';});
    state.delay=result.mas?100:2200;state.failures=0;await seen(state);
    if(!state.group && Date.now()-state.lastActivity>20000){state.lastActivity=Date.now();post({accion:'actividad',con:state.con,escribiendo:false}).catch(()=>{});}
  }
  function stream(state) {
    if(active!==state || document.hidden || state.halted)return;
    const source=new EventSource('chat-stream.php?con='+state.con+'&after='+state.after);state.source=source;
    const close=()=>{source.close();clearTimeout(state.streamTimeout);if(state.source===source)state.source=null;};
    const fallback=()=>{close();if(active===state){state.preferStream=false;poll(state);}};
    state.streamTimeout=setTimeout(fallback,10000);
    source.addEventListener('chat',event=>{try{receive(state,JSON.parse(event.data)).catch(fallback);}catch(_){fallback();}});
    source.addEventListener('done',()=>{close();if(active===state && !document.hidden)state.timer=setTimeout(()=>stream(state),800);});
    source.addEventListener('auth',()=>{close();state.halted=true;state.root.querySelector('.chat-connection').textContent='Inicia sesión de nuevo';});
    source.addEventListener('unavailable',fallback);source.onerror=fallback;
  }
  async function poll(state) {
    if(state!==active || document.hidden || state.polling)return;state.polling=true;
    const controller=new AbortController();state.controller=controller;const timeout=setTimeout(()=>controller.abort(),10000);
    try{
      const response=await fetch(state.transport+'?con='+state.con+'&after='+state.after,{credentials:'same-origin',cache:'no-store',signal:controller.signal});const result=await response.json();if(!response.ok || !result.ok){if(response.status===401 || response.status===404)state.halted=true;throw new Error(result.mensaje || 'Reconectando…');}
      await receive(state,result);
    }catch(error){if(active===state && !document.hidden){state.root.querySelector('.chat-connection').textContent=state.halted?'Conversación no disponible':'Reconectando…';state.delay=Math.min(30000,2200*2**Math.min(++state.failures,4));}}
    finally{clearTimeout(timeout);state.polling=false;if(state.controller===controller)state.controller=null;if(active===state && !document.hidden && !state.halted)state.timer=setTimeout(()=>poll(state),state.delay);}
  }
  function stop(){if(active){clearTimeout(active.timer);active.controller?.abort();active.source?.close();clearTimeout(active.streamTimeout);active=null;}}
  async function openNotices(){
    const node=document.querySelector('[data-open-notifications]');if(!node || document.hidden || notices || (!Number(node.dataset.openNotifications) && !Number(node.dataset.openEvents)))return;notices=true;
    try{await post({accion:'avisos',hasta:Number(node.dataset.openNotifications),eventos:Number(node.dataset.openEvents || 0)});if(node.isConnected){node.querySelectorAll('.notif-lista .nueva').forEach(row=>row.classList.remove('nueva'));node.removeAttribute('data-open-notifications');document.dispatchEvent(new CustomEvent('musicmad:notifications-read'));}}
    catch(_){}finally{notices=false;}
  }
  function init(event){
    stop();openNotices();const root=document.querySelector('[data-live-chat]');if(!root)return;const list=root.querySelector('#chat-msgs');const ids=[...list.querySelectorAll('[data-message-id]')].map(node=>Number(node.dataset.messageId));
    const state={root,list,group:root.dataset.group==='1',transport:root.dataset.transport==='banda-chat.php'?'banda-chat.php':'chat-live.php',con:Number(root.dataset.con),owner:Number(root.dataset.owner),ids:new Set(ids),after:Math.max(0,...ids),seen:0,failures:0,delay:2200,lastActivity:0,lastTyping:0,halted:false,preferStream:root.dataset.stream==='1' && 'EventSource' in window};active=state;
    if(!event?.detail?.restore)list.scrollTop=list.scrollHeight;list.addEventListener('scroll',()=>seen(state),{passive:true});if(state.preferStream)stream(state);else poll(state);
  }
  document.addEventListener('input',event=>{
    const input=event.target.closest?.('#chat-search');if(input){const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase();const query=normalize(input.value.trim());let count=0;document.querySelectorAll('.chat-conversation').forEach(row=>{row.hidden=!normalize(row.dataset.chatName || '').includes(query);if(!row.hidden)count++;});const empty=document.querySelector('#chat-no-results');if(empty)empty.hidden=count>0;}
    if(active && !active.group && event.target.matches('[data-live-composer] textarea') && Date.now()-active.lastTyping>2500){active.lastTyping=Date.now();post({accion:'actividad',con:active.con,escribiendo:!!event.target.value.trim()}).catch(()=>{});}
  });
  document.addEventListener('submit',async event=>{
    const form=event.target;
    if(form.matches('[data-chat-preferences]')){event.preventDefault();if(form.dataset.busy)return;form.dataset.busy='1';try{await post({accion:'preferencias',...Object.fromEntries(['mostrar_presencia','mostrar_escribiendo','confirmar_lectura'].map(key=>[key,form.elements[key].checked]))});form.querySelector('[role=status]').textContent='Preferencias guardadas.';}catch(error){form.querySelector('[role=status]').textContent=error.message;}finally{delete form.dataset.busy;}return;}
    if(!form.matches('[data-live-composer]') || !active)return;event.preventDefault();const state=active;if(form.dataset.busy)return;
    const text=form.elements.texto.value.trim(),track=Number(form.elements.pista?.value || 0);if(!text && !track)return;
    const payload=JSON.stringify([text,track]);if(form.dataset.payload!==payload){const initial=!form.dataset.payload;form.dataset.payload=payload;form.dataset.request=(initial?form.elements.solicitud?.value:'') || Array.from(crypto.getRandomValues(new Uint8Array(16)),byte=>byte.toString(16).padStart(2,'0')).join('');}
    form.dataset.busy='1';form.setAttribute('aria-busy','true');const button=form.querySelector('button[type=submit]');button.disabled=true;const error=state.root.querySelector('.chat-error');error.hidden=true;
    try{const result=await post({accion:'enviar',con:state.con,texto:text,pista:track,solicitud:form.dataset.request},state.transport);if(active===state){addMessage(state,result.mensaje);state.list.scrollTop=state.list.scrollHeight;if(JSON.stringify([form.elements.texto.value.trim(),Number(form.elements.pista?.value || 0)])===payload){form.elements.texto.value='';form.elements.texto.defaultValue='';if(form.elements.pista)form.elements.pista.value='';delete form.dataset.payload;delete form.dataset.request;if(form.elements.solicitud)form.elements.solicitud.value='';}form.elements.texto.focus();if(!state.group)post({accion:'actividad',con:state.con,escribiendo:false}).catch(()=>{});}}
    catch(failure){if(active===state){error.textContent=failure.message;error.hidden=false;}}
    finally{delete form.dataset.busy;form.removeAttribute('aria-busy');button.disabled=false;}
  },true);
  document.addEventListener('click',async event=>{
    const button=event.target.closest?.('.chat-older');if(!button || !active)return;const state=active;button.disabled=true;
    try{const before=Math.min(...state.ids);const response=await fetch('chat-live.php?con='+state.con+'&before='+before,{credentials:'same-origin',cache:'no-store'});const result=await response.json();if(!response.ok || !result.ok)throw new Error();if(active!==state)return;const height=state.list.scrollHeight;[...result.mensajes].reverse().forEach(message=>addMessage(state,message,true));state.list.scrollTop+=state.list.scrollHeight-height;button.hidden=!result.mas;}
    catch(_){document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{message:'No pudimos cargar los mensajes anteriores. Reintenta.',type:'error'}}));}finally{button.disabled=false;}
  });
  document.addEventListener('musicmad:beforepage',stop);document.addEventListener('musicmad:page',init);
  document.addEventListener('visibilitychange',()=>{if(document.hidden){if(active){clearTimeout(active.timer);active.controller?.abort();active.source?.close();clearTimeout(active.streamTimeout);}}else{openNotices();if(active && !active.halted){clearTimeout(active.timer);if(active.preferStream)stream(active);else poll(active);}}});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
