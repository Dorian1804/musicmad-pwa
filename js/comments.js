(() => {
  const requests=new Set();
  const make=(tag,text,className)=>{const node=document.createElement(tag);if(text)node.textContent=text;if(className)node.className=className;return node;};
  document.addEventListener('musicmad:beforepage',()=>{requests.forEach(controller=>controller.abort());requests.clear();});
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-comments-more]');if(!button || button.disabled)return;
    const root=button.closest('[data-comment-thread]'),list=root?.querySelector('[data-comment-list]');if(!root || !list)return;
    const controller=new AbortController();requests.add(controller);button.disabled=true;const previous=button.textContent;button.textContent='Cargando…';
    try{
      const query=new URLSearchParams({pub:root.dataset.postId,antes:button.dataset.before || '0'});if(root.dataset.parentId)query.set('comentario',root.dataset.parentId);
      const response=await fetch('comentarios.php?'+query,{credentials:'same-origin',signal:controller.signal,cache:'no-store'}),result=await response.json();
      if(!response.ok || !result.ok)throw new Error(result.mensaje || 'No pudimos cargar los comentarios.');
      const fragment=document.createDocumentFragment();
      for(const item of result.items){
        const row=make(root.dataset.parentId?'div':'li','','comment-reply-page'),image=make('img','','com-avatar');
        const avatar=new URL(item.avatar,document.baseURI || location.href);if(avatar.origin===location.origin && /\/(?:img|uploads\/avatars)\//.test(avatar.pathname))image.src=avatar.href;
        image.alt='';image.width=28;image.height=28;image.loading='lazy';
        const copy=make('div'),link=make('a'),name=make('strong',item.nombre),text=make('span',' '+item.texto),date=make('small',' '+item.fecha);
        link.href='musico.php?id='+Number(item.uid);link.append(name);copy.append(link,text,date);
        if(!root.dataset.parentId){
          const form=make('form','','reply-form');form.method='POST';form.action='social-accion.php';form.dataset.syncAction='responder';
          for(const [key,value] of Object.entries({csrf:document.querySelector('meta[name=musicmad-csrf]')?.content || '',accion:'responder_comentario',id:root.dataset.postId,comentario:item.id,volver:'publicacion.php?id='+root.dataset.postId})){const hidden=make('input');hidden.type='hidden';hidden.name=key;hidden.value=String(value);form.append(hidden);}
          const input=make('input');input.type='text';input.name='texto';input.maxLength=400;input.required=true;input.placeholder='Responder…';input.setAttribute('aria-label','Responder a '+item.nombre);
          const submit=make('button','Responder');submit.type='submit';form.append(input,submit);copy.append(form);
        }
        row.append(image,copy);fragment.append(row);
      }
      list.prepend(fragment);button.dataset.before=String(result.before);button.hidden=!result.hay_mas;
    }catch(error){if(error.name!=='AbortError')document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{type:'error',message:error.message}}));}
    finally{requests.delete(controller);button.disabled=false;button.textContent=previous;}
  });
})();
