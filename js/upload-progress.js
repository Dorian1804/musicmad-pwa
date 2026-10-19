/* Resumable media transport and common file-form feedback. */
(() => {
  const states = new WeakMap(), active = new Set();
  const scope = () => document.querySelector('meta[name="musicmad-account"]')?.content || '';
  const csrf = () => document.querySelector('meta[name="musicmad-csrf"]')?.content || '';
  const toast = (message, type='error') => document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{message,type}}));
  const mb = n => (n / 1048576).toFixed(1);
  const hex = bytes => [...new Uint8Array(bytes)].map(n=>n.toString(16).padStart(2,'0')).join('');
  const hash = async blob => hex(await crypto.subtle.digest('SHA-256',await blob.arrayBuffer()));
  const storage = {
    get(key) { try { return localStorage.getItem(key); } catch (_) { return null; } },
    put(key,value) { try { localStorage.setItem(key,value); } catch (_) {} },
    remove(key) { try { localStorage.removeItem(key); } catch (_) {} }
  };
  function ui(state, message, percent=null) {
    const form=state.form;
    let box=form.querySelector('[data-upload-progress]');
    if (!box) {
      box=document.createElement('div'); box.className='upload-progress'; box.dataset.uploadProgress=''; box.setAttribute('role','status'); box.setAttribute('aria-live','polite');
      const text=document.createElement('strong'); text.dataset.uploadMessage='';
      const bar=document.createElement('progress'); bar.max=100; bar.value=0; bar.dataset.uploadBar='';
      box.append(text,bar); form.append(box);
    }
    box.hidden=false;
    const text=box.querySelector('[data-upload-message]'), bar=box.querySelector('[data-upload-bar]'), label=box.querySelector('[data-upload-percent]');
    if(text) text.textContent=message;
    if(percent!==null) { if(bar) bar.value=percent; if(label) label.textContent=Math.round(percent)+'%'; }
    box.querySelector('[data-upload-pause]')?.toggleAttribute('hidden',!state.running);
    box.querySelector('[data-upload-resume]')?.toggleAttribute('hidden',state.running || !state.id);
    box.querySelector('[data-upload-cancel]')?.toggleAttribute('hidden',!state.id);
  }
  function busy(state, yes) {
    state.running=yes; state.form.setAttribute('aria-busy',String(yes));
    document.querySelectorAll('button[type=submit],input[type=submit]').forEach(button=>{ if(button.form===state.form) button.disabled=yes; });
    state.form.querySelectorAll('input[type=file],[data-record-start]').forEach(input=>input.disabled=yes);
    yes ? active.add(state) : active.delete(state);
  }
  async function call(state, operation, body, headers={}) {
    state.controller=new AbortController();
    const timeout=setTimeout(()=>state.controller.abort(),60000);
    try {
      const query=new URLSearchParams({op:operation}); if(state.id) query.set('id',state.id);
      const response=await fetch('subir-archivo.php?'+query,{method:'POST',credentials:'same-origin',headers:{'X-MusicMad-CSRF':csrf(),...headers},body,signal:state.controller.signal});
      let result; try { result=await response.json(); } catch (_) { throw new Error('El hosting devolvió una respuesta incompleta. Tu archivo y borrador se conservan.'); }
      if(!response.ok || !result.ok) { const error=new Error(result.mensaje || 'No se pudo completar la subida.'); error.status=response.status; throw error; }
      return result;
    } finally { clearTimeout(timeout); }
  }
  async function resume(state) {
    if(state.running) return;
    busy(state,true); state.paused=false; ui(state,'Comprobando el avance guardado…');
    try {
      if(!crypto.subtle) throw new Error('La subida reanudable necesita HTTPS y un navegador actualizado.');
      if(!state.key) {
        const sample=new Blob([state.file.slice(0,65536),state.file.slice(-65536),state.file.size+'|'+state.file.lastModified+'|'+state.file.name]);
        state.key='musicmad.upload.'+scope()+'.'+(state.form.elements.restaurar_publicacion?.value ? 'restore:'+state.form.elements.restaurar_publicacion.value : state.form.elements.colaboracion_id?.value ? 'colab:'+state.form.elements.colaboracion_id.value : state.form.elements.banda_id?.value ? 'band:'+state.form.elements.banda_id.value : 'feed')+'.'+await hash(sample);
        state.id=storage.get(state.key);
      }
      let result;
      if(state.id) {
        try { result=await call(state,'estado'); }
        catch(error) { if([404,422].includes(error.status)) { storage.remove(state.key); state.id=null; } else throw error; }
      }
      if(!state.id) {
        const data=new FormData(); data.set('tipo',state.kind); data.set('nombre',state.file.name); data.set('bytes',String(state.file.size));
        result=await call(state,'iniciar',data); state.id=result.upload.id; storage.put(state.key,state.id);
      }
      let upload=result.upload;
      if(upload.estado!=='completo') {
        state.offset=upload.offset; const started=Date.now(),initial=state.offset;
        while(state.offset<state.file.size) {
          if(state.paused) throw new DOMException('paused','AbortError');
          const chunk=state.file.slice(state.offset,state.offset+upload.chunk_bytes);
          const checksum=await hash(chunk);
          result=await call(state,'bloque',chunk,{'Content-Type':'application/octet-stream','X-Upload-Offset':String(state.offset),'X-Upload-SHA256':checksum});
          upload=result.upload; state.offset=upload.offset;
          const rate=(state.offset-initial)/Math.max(1,(Date.now()-started)/1000),remaining=rate>0?Math.ceil((state.file.size-state.offset)/rate):0;
          ui(state,'Guardados '+mb(state.offset)+' de '+mb(state.file.size)+' MB'+(remaining>0?' · aprox. '+Math.ceil(remaining/60)+' min restantes':''),state.offset/state.file.size*100);
        }
        ui(state,'Archivo completo. Verificando y publicando…',100);
        const metadata=new FormData(state.form); for(const key of ['audio','video','partitura']) metadata.delete(key);
        result=await call(state,'publicar',metadata); upload=result.upload;
      }
      if(!upload.publicacion_id) throw new Error('La publicación ya no está disponible.');
      storage.remove(state.key); state.id=null;
      ui(state,state.form.elements.restaurar_publicacion?.value?'Video restaurado y respaldado.':'Publicado. Tu archivo quedó guardado y respaldado.',100);
      window.MusicMadNavigate?.(state.form.elements.restaurar_publicacion?.value ? 'publicacion.php?id='+upload.publicacion_id : result.redirect || 'feed.php#pub-'+upload.publicacion_id);
    } catch(error) {
      ui(state,error.name==='AbortError'?'Subida pausada. Toca Reanudar; no volveremos a enviar los bloques guardados.':error.message || 'Se cortó internet. Reanuda cuando vuelva la conexión.');
    } finally { busy(state,false); ui(state,state.form.querySelector('[data-upload-message]')?.textContent || 'Puedes reintentar.'); }
  }
  document.addEventListener('change',event=>{
    const input=event.target.closest?.('[data-video-input]'); if(!input) return;
    const form=input.form,file=input.files?.[0],hint=form?.querySelector('[data-video-hint]');
    if(!file){if(hint)hint.textContent='';return;}
    const select=form.querySelector('select[name=tipo]'); if(select)select.value='video';
    if(hint)hint.textContent=file.name+' · '+mb(file.size)+' MB';
  });
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-upload-pause],[data-upload-resume],[data-upload-cancel]'); if(!button)return;
    const state=states.get(button.closest('form')); if(!state)return;
    if(button.hasAttribute('data-upload-pause')) { state.paused=true; state.controller?.abort(); }
    if(button.hasAttribute('data-upload-resume')) resume(state);
    if(button.hasAttribute('data-upload-cancel') && state.id) {
      state.paused=true;state.controller?.abort();
      // Wait for the current request's finally block before starting cancellation.
      const cancel=async()=>{
        if(state.running){setTimeout(cancel,50);return;}
        try { busy(state,true); await call(state,'cancelar'); storage.remove(state.key);state.id=null;ui(state,'Subida cancelada. El archivo original sigue en tu dispositivo.',0); }
        catch(error){ui(state,error.message);}
        finally{busy(state,false);ui(state,state.form.querySelector('[data-upload-message]')?.textContent);}
      }; cancel();
    }
  });
  document.addEventListener('musicmad:beforepage',()=>active.forEach(state=>{state.paused=true;state.controller?.abort();}));
  document.addEventListener('musicmad:session',()=>active.forEach(state=>{state.paused=true;state.controller?.abort();}));
  document.addEventListener('submit',event=>{
    const form=event.target.closest?.('[data-upload-form]'); if(!form || event.defaultPrevented)return;
    const selected=[...form.querySelectorAll('input[type=file]')].flatMap(input=>[...(input.files||[])].map(file=>({input,file})));
    if(!selected.length)return;
    event.preventDefault(); const old=states.get(form); if(old?.running)return;
    if(!form.reportValidity())return;
    if(form.hasAttribute('data-chunk-upload')) {
      if(selected.length!==1 || !['audio','video','partitura'].includes(selected[0].input.name)){toast('Elige solo un archivo: audio, video o partitura.');return;}
      const {input,file}=selected[0],limits={audio:64*1048576,video:256*1048576,partitura:20*1048576};
      if(file.size>limits[input.name]){toast('El archivo supera el límite de '+mb(limits[input.name])+' MB.');return;}
      const state=old?.file===file?old:{form,file,kind:input.name,id:null,key:null,running:false};
      states.set(form,state);resume(state);return;
    }
    const state={form,running:false};states.set(form,state);
    const body=new FormData(form);if(event.submitter?.name)body.append(event.submitter.name,event.submitter.value);
    busy(state,true);ui(state,'Preparando el archivo…',0);
    const xhr=new XMLHttpRequest();state.controller={abort:()=>xhr.abort()};
    xhr.open('POST',form.action||location.href);xhr.withCredentials=true;xhr.timeout=180000;xhr.setRequestHeader('X-Requested-With','MusicMad-PJAX');
    xhr.upload.onprogress=progress=>{if(progress.lengthComputable)ui(state,'Subiendo '+mb(progress.loaded)+' de '+mb(progress.total)+' MB',progress.loaded/progress.total*100);};
    const fail=message=>{busy(state,false);ui(state,message);};
    xhr.onload=()=>{
      if(xhr.status<200||xhr.status>=300){fail('El servidor rechazó la subida ('+xhr.status+'). Tu borrador sigue aquí.');return;}
      let result;try{result=JSON.parse(xhr.responseText);}catch(_){}
      if(result?.ok===false){fail(result.mensaje||result.flash?.[1]||'El archivo no fue aceptado. Conservamos el formulario.');return;}
      if(!result){
        const doc=new DOMParser().parseFromString(xhr.responseText,'text/html'),error=doc.querySelector('.alert-error');
        if(error){fail(error.textContent.trim());return;}
        if(!doc.querySelector('main')){fail('La respuesta del hosting está incompleta. Reintenta.');return;}
      }
      busy(state,false);ui(state,'Guardado.',100);
      window.MusicMadNavigate?.(result?.redirect||xhr.responseURL||location.href);
    };
    xhr.onerror=()=>fail('Se interrumpió internet. Conservamos el archivo y el borrador; reintenta al reconectar.');
    xhr.ontimeout=()=>fail('La subida tardó demasiado. Tu archivo sigue aquí para reintentar.');
    xhr.onabort=()=>fail('Subida pausada. El archivo sigue en tu dispositivo.');
    xhr.send(body);
  });
})();
