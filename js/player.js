(() => {
  const start = () => {
    const root = document.querySelector('[data-global-player]');
    if (!root || root.dataset.ready === '1') return;
    root.dataset.ready = '1';
    const audio = root.querySelector('[data-global-audio]');
    const failures=window.MusicMadPlaybackErrors.createReporter({
      notify:message=>document.dispatchEvent(new CustomEvent('musicmad:toast',{detail:{id:'player-audio-error',type:'error',message}})),
      probe:async(src,signal)=>{
        const target=new URL(src,document.baseURI || location.href);
        if(target.origin!==location.origin||!target.pathname.endsWith('/audio.php'))return {};
        const controller=new AbortController(),abort=()=>controller.abort();
        signal.addEventListener('abort',abort,{once:true});if(signal.aborted)controller.abort();
        const timeout=setTimeout(abort,8000);
        try{
          const response=await fetch(target.href,{method:'HEAD',credentials:'same-origin',cache:'no-store',signal:controller.signal});
          return {status:response.status,reason:response.headers.get('X-MusicMad-Audio-Status'),login:/\/login\.php$/.test(new URL(response.url,location.href).pathname),html:(response.headers.get('Content-Type')||'').includes('text/html')};
        }finally{clearTimeout(timeout);signal.removeEventListener('abort',abort);}
      }
    });
    const requestPlayback=async()=>{
      failures.reset(true);
      const source=audio.src;
      if(audio.error)audio.load();
      try{await audio.play();}catch(error){if(audio.src===source)await failures.report(error,audio.error?.code||0,source);}
    };
    const title = root.querySelector('[data-player-title]');
    const artist = root.querySelector('[data-player-artist]');
    const playButton = root.querySelector('[data-player-toggle]');
    const playIcon = root.querySelector('[data-player-play-icon]');
    const pauseIcon = root.querySelector('[data-player-pause-icon]');
    const seek = root.querySelector('[data-player-seek]');
    const time = root.querySelector('[data-player-time]');
    const queueNode = root.querySelector('[data-player-queue]');
    const queueCount = root.querySelector('[data-player-queue-count]');
    let accountScope = document.querySelector('meta[name="musicmad-account"]')?.content || '';
    const storageKey = () => accountScope ? 'musicmad.player.v2.' + accountScope : null;
    try { localStorage.removeItem('musicmad.player.v1'); } catch (_) {}
    new MutationObserver(() => document.body.classList.toggle('has-global-player', !root.hidden)).observe(root, { attributes: true, attributeFilter:['hidden'] });
    document.addEventListener('musicmad:session', event => {
      try { if (storageKey()) localStorage.removeItem(storageKey()); } catch (_) {}
      accountScope = event.detail.scope;
      failures.reset();
      if(expanded) expand(false);
      audio.pause(); audio.removeAttribute('src'); audio.load(); root.hidden=true;
      queue=[]; current=-1; drawQueue();
      if ('mediaSession' in navigator) navigator.mediaSession.metadata=null;
    });
    let queue = [];
    let current = -1;
    let visualContext = null;
    let visualFrame = 0;
    let seeking = false, expanded = false, shuffle = false, repeat = false, focusBeforePlayer = null;
    const inertBefore = new Map();
    const expand = yes => {
      expanded = yes; root.classList.toggle('is-expanded', yes); document.body.classList.toggle('player-screen-open', yes);
      if (yes) {
        focusBeforePlayer = document.activeElement;
        root.setAttribute('role','dialog'); root.setAttribute('aria-modal','true');
        [...document.body.children].filter(node=>node!==root && !node.contains(root) && node.tagName!=='SCRIPT').forEach(node=>{ inertBefore.set(node,node.inert); node.inert=true; });
        root.querySelector('[data-player-collapse]').focus();
      } else {
        root.removeAttribute('role'); root.removeAttribute('aria-modal');
        inertBefore.forEach((previous,node)=>node.inert=previous); inertBefore.clear();
        if(focusBeforePlayer?.isConnected) focusBeforePlayer.focus({preventScroll:true});
      }
    };
    root.addEventListener('keydown',event=>{
      if(!expanded)return;
      if(event.key==='Escape'){event.preventDefault();expand(false);}
      if(event.key==='Tab') {
        const controls=[...root.querySelectorAll('button,a[href],input,select')].filter(node=>!node.disabled && node.getClientRects().length);
        const first=controls[0],last=controls.at(-1);
        if(event.shiftKey && document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey && document.activeElement===last){event.preventDefault();first.focus();}
      }
    });
    let gesture=null;
    root.querySelector('.player-track-info').addEventListener('pointerdown',event=>gesture={y:event.clientY});
    root.querySelector('.player-track-info').addEventListener('pointerup',event=>{if(gesture && gesture.y-event.clientY>50)expand(true);gesture=null;});
    root.querySelector('.player-screen-heading').addEventListener('pointerdown',event=>gesture={y:event.clientY});
    root.querySelector('.player-screen-heading').addEventListener('pointerup',event=>{if(gesture && event.clientY-gesture.y>50)expand(false);gesture=null;});
    document.addEventListener('musicmad:beforepage',()=>{if(expanded)expand(false);});
    const nextIndex=()=>shuffle && queue.length>1 ? (current+1+Math.floor(Math.random()*(queue.length-1)))%queue.length : current+1;
    root.querySelector('[data-player-volume]').addEventListener('input',event=>audio.volume=Number(event.target.value));
    root.querySelector('[data-player-rate]').addEventListener('change',event=>audio.playbackRate=Number(event.target.value));
    const stopVisualizer=()=>{if(visualFrame)cancelAnimationFrame(visualFrame);visualFrame=0;};
    document.addEventListener('visibilitychange',()=>{if(document.hidden)stopVisualizer();else if(!audio.paused)setupVisualizer();});

    const validTrack = (track) => track && /^\d+$/.test(String(track.id)) &&
      typeof track.src === 'string' && /^audio\.php\?id=\d+$/.test(track.src);
    const scopedSource = src => { const value = new URL(src, document.baseURI || location.href); if (accountScope) value.searchParams.set('_scope', accountScope); return value.href; };
    const timeText = (seconds) => {
      if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
      const n = Math.floor(seconds);
      return `${Math.floor(n / 60)}:${String(n % 60).padStart(2, '0')}`;
    };
    const persist = () => {
      try {
        if (storageKey()) localStorage.setItem(storageKey(), JSON.stringify({ queue, current, time: Number(audio.currentTime) || 0 }));
      } catch (_) { /* El reproductor sigue funcionando si el almacenamiento está bloqueado. */ }
    };
    document.addEventListener('musicmad:before-update', () => persist());
    const safeLoad = () => {
      try {
        const data = JSON.parse(localStorage.getItem(storageKey() || 'musicmad.player.disabled') || 'null');
        if (!data || !Array.isArray(data.queue)) return;
        queue = data.queue.filter(validTrack).slice(0, 100);
        current = Number.isInteger(data.current) ? data.current : -1;
        if (current >= 0 && current < queue.length) {
          showTrack(queue[current], true);
          audio.src = scopedSource(queue[current].src);
          audio.dataset.restoreTime = String(Math.max(0, Number(data.time) || 0));
        }
        drawQueue();
      } catch (_) { /* Ignora un estado local dañado. */ }
    };
    const showTrack = (track, show = true) => {
      if (!track) return;
      root.dataset.trackId = String(track.id);
      title.textContent = track.title || 'Pista de MusicMad';
      artist.textContent = track.artist || 'Artista de MusicMad';
      root.hidden = !show;
      if ('mediaSession' in navigator && 'MediaMetadata' in window) {
        navigator.mediaSession.metadata = new MediaMetadata({
          title: track.title || 'Pista de MusicMad',
          artist: track.artist || 'MusicMad',
          album: 'MusicMad'
        });
      }
    };
    const drawQueue = () => {
      queueNode.replaceChildren();
      queue.forEach((track, index) => {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.queueIndex = String(index);
        button.className = index === current ? 'is-current' : '';
        const name = document.createElement('strong');
        name.textContent = track.title || 'Pista';
        const by = document.createElement('small');
        by.textContent = track.artist || 'MusicMad';
        button.append(name, by);
        const remove=document.createElement('button'); remove.type='button'; remove.dataset.queueRemove=String(index); remove.textContent='×'; remove.setAttribute('aria-label','Quitar '+(track.title || 'pista')+' de la cola');
        const move=document.createElement('button'); move.type='button'; move.dataset.queueMove=String(index); move.textContent='↑'; move.setAttribute('aria-label','Adelantar '+(track.title || 'pista')+' en la cola');move.disabled=index===0;
        li.append(button,move,remove);
        queueNode.append(li);
      });
      queueCount.textContent = String(queue.length);
    };
    const setPlayingState = () => {
      const playing = !audio.paused;
      playIcon.hidden = playing;
      pauseIcon.hidden = !playing;
      playButton.setAttribute('aria-label', playing ? 'Pausar' : 'Reproducir');
      playButton.title = playing ? 'Pausar' : 'Reproducir';
      root.classList.toggle('is-playing', playing);
      document.documentElement.classList.toggle('music-is-playing', playing);
      if ('mediaSession' in navigator) navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
    };
    const playAt = async (index, shouldPlay = true) => {
      if (!queue.length) return;
      const next = (index + queue.length) % queue.length;
      const same = next === current && audio.getAttribute('src');
      current = next;
      const track = queue[current];
      if (!validTrack(track)) return;
      if (!same) { failures.reset();audio.src = scopedSource(track.src); audio.currentTime = 0; }
      showTrack(track);
      drawQueue();
      persist();
      if (shouldPlay) {
        await requestPlayback();
      }
      setPlayingState();
    };
    const playlistFromPage = () => [...document.querySelectorAll('[data-track-play]')]
      .map((button) => ({
        id: button.dataset.trackId,
        src: button.dataset.trackSrc,
        title: button.dataset.trackTitle,
        artist: button.dataset.trackArtist
      })).filter(validTrack);
    const setupVisualizer = async () => {
      const canvas = root.querySelector('[data-player-visualizer]');
      if (!canvas || document.hidden || matchMedia('(prefers-reduced-motion: reduce)').matches || !window.AudioContext && !window.webkitAudioContext) return;
      try {
        if (!visualContext) {
          const Context = window.AudioContext || window.webkitAudioContext;
          visualContext = new Context();
          const source = visualContext.createMediaElementSource(audio);
          const analyser = visualContext.createAnalyser();
          analyser.fftSize = 64;
          source.connect(analyser);
          analyser.connect(visualContext.destination);
          canvas.dataset.analyser = 'ready';
          canvas._analyser = analyser;
        }
        if (visualContext.state === 'suspended') await visualContext.resume();
      } catch (_) { return; }
      if (visualFrame) cancelAnimationFrame(visualFrame);
      const ctx = canvas.getContext('2d');
      const analyser = canvas._analyser;
      if (!ctx || !analyser) return;
      const values = new Uint8Array(analyser.frequencyBinCount);
      const draw = () => {
        visualFrame = requestAnimationFrame(draw);
        analyser.getByteFrequencyData(values);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const gap = 4;
        const width = Math.max(2, (canvas.width - gap * values.length) / values.length);
        values.forEach((value, index) => {
          const height = Math.max(3, (value / 255) * canvas.height);
          ctx.fillStyle = getComputedStyle(root).getPropertyValue('--player-accent').trim() || '#d7f05a';
          ctx.fillRect(index * (width + gap), (canvas.height - height) / 2, width, height);
        });
      };
      draw();
    };

    safeLoad();
    document.addEventListener('musicmad:seek',event=>{
      const id=String(event.detail?.id||''),second=event.detail?.second;
      if(!/^[1-9][0-9]{0,9}$/.test(id)||!Number.isFinite(second)||second<0||second>86400)return;
      for(const track of playlistFromPage())if(!queue.some(existing=>existing.id===track.id)&&queue.length<100)queue.push(track);
      const index=queue.findIndex(track=>track.id===id);if(index<0)return;
      const seek=()=>{if(root.dataset.trackId===id&&Number.isFinite(audio.duration))audio.currentTime=Math.min(second,audio.duration);};
      if(root.dataset.trackId===id&&audio.readyState>=1){seek();if(audio.paused)requestPlayback();}else{audio.addEventListener('loadedmetadata',seek,{once:true});playAt(index,true);}
    });
    document.addEventListener('click', (event) => {
      const trackButton = event.target.closest('[data-track-play]');
      if (trackButton) {
        const tracks = playlistFromPage();
        const incoming=tracks.length?tracks:[{id:trackButton.dataset.trackId,src:trackButton.dataset.trackSrc,title:trackButton.dataset.trackTitle,artist:trackButton.dataset.trackArtist}];
        for(const track of incoming)if(!queue.some(existing=>existing.id===track.id) && queue.length<100)queue.push(track);
        playAt(Math.max(0,queue.findIndex(track=>track.id===trackButton.dataset.trackId)),true);
        event.preventDefault();
        return;
      }
      const captureTime = event.target.closest('[data-capture-audio-time]');
      if (captureTime) {
        const pubId = captureTime.dataset.pubId;
        const value = captureTime.closest('form')?.querySelector('[data-audio-time-value]');
        if (root.dataset.trackId !== pubId || !value) {
          document.dispatchEvent(new CustomEvent('musicmad:toast', { detail: { type: 'error', message: 'Primero reproduce esta pista y colócala en el momento que quieres comentar.' } }));
          return;
        }
        value.value = Math.max(0,audio.currentTime||0).toFixed(3);
        const moment = timeText(Number(value.value));
        captureTime.textContent = `✓ Momento ${moment}`;
        return;
      }
      const seekToComment = event.target.closest('[data-seek-audio]');
      if (seekToComment) {
        const pubId = seekToComment.dataset.pubId;
        const second = Math.max(0, Number(seekToComment.dataset.second) || 0);
        const index = queue.findIndex((track) => track.id === pubId);
        if (root.dataset.trackId === pubId && Number.isFinite(audio.duration)) audio.currentTime = Math.min(second, audio.duration || second);
        else if (index >= 0) {
          audio.addEventListener('loadedmetadata', () => { audio.currentTime = Math.min(second, audio.duration || second); }, { once: true });
          playAt(index, false);
        } else document.dispatchEvent(new CustomEvent('musicmad:toast', { detail: { type: 'error', message: 'Reproduce la pista para saltar a este comentario.' } }));
        return;
      }
      if (event.target.closest('[data-player-toggle]')) {
        if (!audio.src && queue.length) playAt(Math.max(0, current), true);
        else if (audio.paused) { requestPlayback(); }
        else audio.pause();
      }
      if (event.target.closest('[data-player-expand]')) expand(true);
      if (event.target.closest('[data-player-collapse]')) expand(false);
      if (event.target.closest('[data-player-prev]')) playAt(current - 1, true);
      if (event.target.closest('[data-player-next]')) playAt(nextIndex(), true);
      const shuffleButton=event.target.closest('[data-player-shuffle]'); if(shuffleButton){shuffle=!shuffle;shuffleButton.setAttribute('aria-pressed',String(shuffle));}
      const repeatButton=event.target.closest('[data-player-repeat]'); if(repeatButton){repeat=!repeat;repeatButton.setAttribute('aria-pressed',String(repeat));}
      if(event.target.closest('[data-player-clear]')){if(current>=0){queue=[queue[current]];current=0;}else queue=[];drawQueue();persist();}
      if (event.target.closest('[data-player-offline]')) {
        const trackId = root.dataset.trackId || '';
        const worker = navigator.serviceWorker?.controller;
        if (!/^\d+$/.test(trackId)) {
          document.dispatchEvent(new CustomEvent('musicmad:toast', { detail: { type: 'error', message: 'Reproduce una pista antes de guardarla sin conexión.' } }));
        } else if (!worker || !navigator.onLine) {
          document.dispatchEvent(new CustomEvent('musicmad:toast', { detail: { type: 'error', message: 'Conéctate a internet para guardar esta pista en el dispositivo.' } }));
        } else {
          const channel = new MessageChannel();
          channel.port1.onmessage = (message) => document.dispatchEvent(new CustomEvent('musicmad:toast', { detail: { type: message.data?.ok ? 'success' : 'error', message: message.data?.message || 'No se pudo guardar la pista.' } }));
          window.MusicMadSetAccount?.(accountScope);
          worker.postMessage({ type: 'CACHE_TRACK', id: trackId, title: title.textContent, artist: artist.textContent }, [channel.port2]);
        }
      }
      if (event.target.closest('[data-player-close]')) { failures.reset();if(expanded)expand(false); audio.pause(); root.hidden = true; persist(); }
      const queueToggle = event.target.closest('[data-player-queue-toggle]');
      if (queueToggle) {
        queueNode.hidden = !queueNode.hidden;
        queueToggle.setAttribute('aria-expanded', String(!queueNode.hidden));
      }
      const queueItem = event.target.closest('[data-queue-index]');
      if (queueItem) playAt(Number(queueItem.dataset.queueIndex), true);
      const remove=event.target.closest('[data-queue-remove]');
      if(remove){const index=Number(remove.dataset.queueRemove);if(index===current){failures.reset();audio.pause();audio.removeAttribute('src');audio.load();current=-1;}else if(index<current)current--;queue.splice(index,1);drawQueue();persist();}
      const move=event.target.closest('[data-queue-move]');
      if(move){const index=Number(move.dataset.queueMove);if(index>0){[queue[index-1],queue[index]]=[queue[index],queue[index-1]];if(current===index)current--;else if(current===index-1)current++;drawQueue();persist();}}
    });
    seek.addEventListener('input', () => {
      seeking = true;
      if (Number.isFinite(audio.duration) && audio.duration > 0) {
        const preview = audio.duration * Number(seek.value) / 1000;
        time.textContent = `${timeText(preview)} / ${timeText(audio.duration)}`;
      }
    });
    seek.addEventListener('change', () => {
      if (Number.isFinite(audio.duration) && audio.duration > 0) audio.currentTime = audio.duration * Number(seek.value) / 1000;
      seeking = false;
      persist();
    });
    audio.addEventListener('play', () => { setPlayingState(); setupVisualizer(); });
    audio.addEventListener('playing',()=>document.dispatchEvent(new CustomEvent('musicmad:playback-ok')));
    audio.addEventListener('pause', () => {setPlayingState();stopVisualizer();persist();});
    audio.addEventListener('error',()=>{if(audio.getAttribute('src'))failures.report(null,audio.error?.code||0,audio.currentSrc||audio.src);});
    audio.addEventListener('timeupdate', () => {
      if (!seeking && Number.isFinite(audio.duration) && audio.duration > 0) seek.value = String(Math.round(audio.currentTime / audio.duration * 1000));
      time.textContent = `${timeText(audio.currentTime)} / ${timeText(audio.duration)}`;
      if (Math.floor(audio.currentTime) % 5 === 0) persist();
    });
    audio.addEventListener('loadedmetadata', () => {
      const restore = Number(audio.dataset.restoreTime || 0);
      if (restore > 0 && restore < audio.duration) audio.currentTime = restore;
      delete audio.dataset.restoreTime;
      time.textContent = `${timeText(audio.currentTime)} / ${timeText(audio.duration)}`;
    });
    audio.addEventListener('ended', () => { if(audio.dataset.roomSync){setPlayingState();return;}if(repeat){audio.currentTime=0;requestPlayback();}else if(queue.length>1)playAt(nextIndex(),true);else setPlayingState(); });
    document.addEventListener('musicmad:page', drawQueue);
    if ('mediaSession' in navigator) {
      for (const [action, handler] of Object.entries({
        play: () => requestPlayback(), pause: () => audio.pause(),
        nexttrack: () => playAt(nextIndex(), true), previoustrack: () => playAt(current - 1, true),
        seekto: (details) => { if (Number.isFinite(details.seekTime)) audio.currentTime = details.seekTime; }
      })) { try { navigator.mediaSession.setActionHandler(action, handler); } catch (_) {} }
    }
    drawQueue();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
