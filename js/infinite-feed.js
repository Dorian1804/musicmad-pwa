(() => {
  let cleanup = () => {};
  const init = () => {
    cleanup();
    const list=document.querySelector('[data-feed-list]'),sentinel=document.querySelector('[data-feed-sentinel]');
    if(!list || !sentinel || list.dataset.feedHasMore!=='1')return;
    let observer,controller,busy=false,stopped=false,failed=false;
    const button=sentinel.querySelector('[data-feed-more]'),status=sentinel.querySelector('[data-feed-status]'),skeleton=sentinel.querySelector('[data-feed-skeleton]');
    const load=async()=>{
      if(busy || stopped || list.dataset.feedHasMore!=='1')return;
      const cursor=list.dataset.feedCursor;if(!cursor)return;
      busy=true;failed=false;controller=new AbortController();button.disabled=true;skeleton.hidden=false;status.textContent='Cargando publicaciones…';observer?.unobserve(sentinel);
      try {
        const url=new URL(location.href);url.hash='';url.searchParams.set('fragmento','feed');url.searchParams.set('cursor',cursor);
        const response=await fetch(url,{credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'MusicMad-PJAX'}});
        if(!response.ok){const error=new Error('HTTP '+response.status);error.status=response.status;throw error;}
        const doc=new DOMParser().parseFromString(await response.text(),'text/html'),fragment=doc.querySelector('[data-feed-fragment]');
        if(!fragment)throw new Error('Incomplete fragment');
        if(stopped || !list.isConnected)return;
        const existing=new Set([...list.querySelectorAll('.post[id]')].map(post=>post.id));
        const incoming=[...fragment.querySelectorAll('.post[id]')].filter(post=>!existing.has(post.id));
        for(const post of incoming)list.append(document.importNode(post,true));
        list.dataset.feedCursor=fragment.dataset.feedCursor || '';list.dataset.feedHasMore=fragment.dataset.hasMore==='1'?'1':'0';
        if(incoming.length)document.dispatchEvent(new CustomEvent('musicmad:posts'));
        status.textContent=list.dataset.feedHasMore==='1'?'':'Ya estás al día.';
        if(list.dataset.feedHasMore!=='1')button.hidden=true;
      }catch(error){
        if(error.name==='AbortError')return;failed=true;
        if(error.status===410){status.textContent='Hay una selección nueva para ti. Actualiza la comunidad.';button.textContent='Actualizar comunidad';button.dataset.feedRefresh='1';}
        else {status.textContent=navigator.onLine?'No pudimos cargar esta parte. Tus publicaciones anteriores siguen aquí.':'Sin conexión. Reintenta cuando vuelva internet.';button.textContent='Reintentar';}
      }finally{busy=false;if(stopped)return;button.disabled=false;skeleton.hidden=true;if(!failed && list.dataset.feedHasMore==='1')observer?.observe(sentinel);}
    };
    const click=()=>button.dataset.feedRefresh?window.MusicMadNavigate?.(location.href):load();button.addEventListener('click',click);
    if('IntersectionObserver' in window){observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting))load();},{rootMargin:'300px 0px'});observer.observe(sentinel);}
    cleanup=()=>{stopped=true;controller?.abort();observer?.disconnect();button.removeEventListener('click',click);};
  };
  document.addEventListener('musicmad:beforepage',()=>cleanup());
  document.addEventListener('musicmad:page',init);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
