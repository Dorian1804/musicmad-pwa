(() => {
  const list=document.querySelector('[data-library-list]'),status=document.querySelector('[data-library-status]'),audio=document.querySelector('[data-library-audio]');
  let scope='',tracks=[],current=-1;
  try{scope=localStorage.getItem('musicmad.offline.scope') || '';}catch(_){}
  const ask=async(type,id)=>{
    const worker=navigator.serviceWorker?.controller || (await navigator.serviceWorker.ready).active;
    if(!worker)throw new Error('Abre MusicMad con internet una vez para activar la biblioteca.');
    const channel=new MessageChannel();
    return await new Promise((resolve,reject)=>{
      const timer=setTimeout(()=>{channel.port1.close();reject(new Error('No pudimos abrir la biblioteca. Reintenta.'));},8000);
      channel.port1.onmessage=event=>{clearTimeout(timer);channel.port1.close();resolve(event.data);};
      worker.postMessage({type,scope,id},[channel.port2]);
    });
  };
  const play=index=>{
    if(!tracks.length)return; current=(index+tracks.length)%tracks.length;
    const track=tracks[current]; document.querySelector('[data-library-player]').hidden=false;
    document.querySelector('[data-library-now]').textContent=track.title+' · '+track.artist;
    audio.src='audio.php?id='+encodeURIComponent(track.id)+'&_scope='+encodeURIComponent(scope);
    audio.play().catch(()=>{status.textContent='Toca el botón de reproducción para escuchar.';});
    if('mediaSession' in navigator && 'MediaMetadata' in window) navigator.mediaSession.metadata=new MediaMetadata({title:track.title,artist:track.artist,album:'MusicMad · sin conexión'});
  };
  const refresh=async()=>{
    if(!/^[a-f0-9]{64}$/.test(scope)){status.textContent='Aún no hay pistas guardadas. Conéctate, inicia sesión y toca ↓ en el reproductor.';return;}
    if(!('serviceWorker' in navigator)){status.textContent='Este navegador no permite escuchar sin conexión. Usa un navegador actualizado.';return;}
    try{
      const result=await ask('LIST_TRACKS');tracks=result.tracks || [];list.replaceChildren();
      status.textContent=tracks.length?tracks.length+' pistas listas para escuchar.':'No hay pistas guardadas en esta cuenta. Usa ↓ en el reproductor con internet.';
      tracks.forEach((track,index)=>{
        const item=document.createElement('li'),button=document.createElement('button'),remove=document.createElement('button');
        button.type='button';button.className='offline-track-play';
        const name=document.createElement('strong'),by=document.createElement('small');name.textContent=track.title;by.textContent=track.artist+' · '+(track.bytes/1048576).toFixed(1)+' MB';button.append(name,by);button.addEventListener('click',()=>play(index));
        remove.type='button';remove.textContent='×';remove.className='offline-track-remove';remove.setAttribute('aria-label','Quitar '+track.title+' del dispositivo');
        remove.addEventListener('click',async()=>{remove.disabled=true;try{if(tracks[current]?.id===track.id){audio.pause();audio.removeAttribute('src');audio.load();document.querySelector('[data-library-player]').hidden=true;current=-1;}await ask('REMOVE_TRACK',track.id);await refresh();}catch(error){status.textContent=error.message;remove.disabled=false;}});
        item.append(button,remove);list.append(item);
      });
    }catch(error){status.textContent=error.message;}
  };
  audio.addEventListener('ended',()=>{if(tracks.length>1)play(current+1);});
  audio.addEventListener('error',()=>{status.textContent='Esta pista no pudo abrirse. Vuelve a guardarla con internet.';});
  document.querySelector('[data-library-next]').addEventListener('click',()=>play(current+1));
  refresh();
})();
