const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),crypto=require('node:crypto').webcrypto;
function fixture({online=true,failure=new DOMException('Storage unavailable','SecurityError'),send=async()=>({owner:1,sent:1,confirmed:[]}),queue=false}={}){
 const events=[],handlers=[],attrs={'aria-pressed':'false'},calls=[];
 const button={disabled:false,getAttribute:key=>attrs[key],setAttribute:(key,value)=>attrs[key]=value,classList:{toggle(){}}};
 const form={dataset:{syncAction:'aplaudir'},matches:()=>true,reportValidity:()=>true,querySelector:()=>button};
 let pending=[];const store={enqueue:async(owner,action)=>{if(!queue)throw failure;pending.push({owner,action,status:'pending'});},list:async()=>pending,sync:async()=>{calls.push({sync:true});pending=[];return {owner:1,sent:1,confirmed:[]};},sendDirect:async(owner,action)=>{calls.push({owner,action});return send(owner,action);}};
 const document={querySelector:selector=>selector.includes('musicmad-user')?{content:'1'}:selector.includes('musicmad-sync')?{content:'1'}:null,querySelectorAll:()=>[],addEventListener:(type,fn)=>{if(type==='submit')handlers.push(fn);},dispatchEvent:event=>events.push(event)};
 const context={window:{MusicMadPending:store},document,navigator:{onLine:online},crypto,DOMException,TypeError,FormData:class{get(key){return key==='id'?'9':'';}},CustomEvent:class{constructor(type,data){this.type=type;this.detail=data.detail;}},setInterval(){},addEventListener(){}};
 vm.createContext(context);vm.runInContext(fs.readFileSync('js/pending-actions.js','utf8'),context);
 const submit=()=>handlers[0]({target:form,submitter:button,preventDefault(){},stopImmediatePropagation(){}});
 return {submit,form,button,attrs,calls,events};
}
test('sin IndexedDB publica online y confirma el estado solo tras respuesta',async()=>{
 const state=fixture();await state.submit();assert.equal(state.calls.length,1);assert.equal(state.calls[0].owner,1);assert.equal(state.attrs['aria-pressed'],'true');assert.equal(state.button.disabled,false);assert.equal(state.form.dataset.syncBusy,'0');
 assert.equal(state.events.filter(event=>event.type==='musicmad:toast').length,0);
});
test('acciones online con cola confirman sin avisos de envío o éxito',async()=>{
 const state=fixture({queue:true});await state.submit();assert.equal(state.attrs['aria-pressed'],'true');assert.equal(state.calls[0].sync,true);assert.equal(state.events.filter(event=>event.type==='musicmad:toast').length,0);
});
test('acciones offline conservan el aviso de pendiente sin presentarlas como confirmadas',async()=>{
 const state=fixture({queue:true,online:false});await state.submit();assert.equal(state.calls.length,0);const messages=state.events.filter(event=>event.type==='musicmad:toast').map(event=>event.detail.message);assert.deepEqual(messages,['Acción pendiente. Se enviará cuando vuelva internet.']);
});
test('un fallo de envío conserva solicitud y estado para reintentar sin duplicación',async()=>{
 let attempts=0;const state=fixture({send:async()=>{if(++attempts===1)throw new TypeError('Network failure');return {owner:1,sent:1,confirmed:[]};}});
 await state.submit();assert.equal(state.attrs['aria-pressed'],'false');await state.submit();assert.equal(state.attrs['aria-pressed'],'true');assert.equal(state.calls[0].action.solicitud,state.calls[1].action.solicitud);
});
test('sin conexión no convierte una acción sin almacenamiento en éxito',async()=>{
 const state=fixture({online:false});await state.submit();assert.equal(state.calls.length,0);assert.equal(state.attrs['aria-pressed'],'false');assert.equal(state.button.disabled,false);
});
test('el límite de la cola no se salta silenciosamente mediante envío directo',async()=>{
 const state=fixture({failure:new Error('Tienes 50 acciones pendientes.')});await state.submit();assert.equal(state.calls.length,0);assert.equal(state.attrs['aria-pressed'],'false');
});
function transport(fetch){const context={self:{},fetch,AbortController,setTimeout,clearTimeout};vm.createContext(context);vm.runInContext(fs.readFileSync('js/pending-store.js','utf8'),context);return context.self.MusicMadPending;}
test('envío directo renueva CSRF y comprueba dueño sin guardar secretos',async()=>{
 const requests=[],action={solicitud:'a'.repeat(32),accion:'guardar',publicacion:9,estado:true};
 const store=transport(async(url,options)=>{requests.push({url,options});return requests.length===1?{ok:true,json:async()=>({ok:true,usuario:1,csrf:'fresh',scope:'b'.repeat(64)})}:{ok:true,json:async()=>({ok:true,resultados:[{ok:true,solicitud:action.solicitud}]})};});
 const result=await store.sendDirect(1,action);assert.equal(result.sent,1);assert.equal(requests[1].options.headers['X-MusicMad-CSRF'],'fresh');assert.equal(JSON.parse(requests[1].options.body).owner,1);assert.equal(requests[1].options.signal.aborted,false);
});
test('envío directo rechaza cambio de cuenta antes del POST',async()=>{
 let requests=0;const store=transport(async()=>{requests++;return {ok:true,json:async()=>({ok:true,usuario:2,csrf:'fresh',scope:'b'.repeat(64)})};});
 await assert.rejects(store.sendDirect(1,{}),/cuenta cambió/);assert.equal(requests,1);
});
