(() => {
 let active=null;
 const symbols={musico:'♫',banda:'♬',evento:'◷'},labels={musico:'Músico',banda:'Banda',evento:'Evento'};
 const rounded=value=>Math.round(value*10)/10;
 function stop(){if(active){clearTimeout(active.timer);active.controller?.abort();active.resize?.disconnect();active.map?.remove();active=null;}}
 function safeLink(point){const pattern=point.tipo==='musico'?/^musico\.php\?id=\d+$/:point.tipo==='banda'?/^banda\.php\?id=\d+$/:/^evento\.php\?id=\d+$/;return pattern.test(point.url)?point.url:null;}
 function pointNode(point){const box=document.createElement('div'),name=document.createElement('strong'),detail=document.createElement('p'),link=document.createElement('a');name.textContent=point.titulo;detail.textContent=[labels[point.tipo],point.detalle,point.ciudad].filter(Boolean).join(' · ');link.textContent='Ver '+labels[point.tipo].toLocaleLowerCase()+' ↗';link.href=safeLink(point);box.append(name,detail,link);return box;}
 function error(state,message){if(active!==state)return;if(state.error){state.error.querySelector('p').textContent=message;state.error.hidden=false;}else state.status.textContent=message;}
 async function load(state){
  if(active!==state||state.editor||document.hidden)return;state.controller?.abort();const controller=new AbortController();state.controller=controller;const timeout=setTimeout(()=>controller.abort(),10000);
  const bounds=state.map.getBounds(),query=new URLSearchParams({oeste:Math.max(-180,bounds.getWest()).toFixed(6),este:Math.min(180,bounds.getEast()).toFixed(6),sur:Math.max(-85,bounds.getSouth()).toFixed(6),norte:Math.min(85,bounds.getNorth()).toFixed(6),tipo:state.root.querySelector('[name=tipo]')?.value||'todos',ciudad:state.root.querySelector('[name=ciudad]')?.value.trim()||''});state.status.textContent='Buscando conexiones en esta zona…';
  try{const response=await fetch('mapa-datos.php?'+query,{credentials:'same-origin',cache:'no-store',signal:controller.signal});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.mensaje||'No pudimos cargar esta zona.');if(!Array.isArray(result.puntos))throw new Error('No pudimos leer las conexiones de esta zona.');if(active!==state||state.controller!==controller)return;state.markers.clearLayers();const list=state.root.querySelector('[data-map-results]');list.replaceChildren();
   for(const point of result.puntos){if(!labels[point.tipo]||!safeLink(point)||!Number.isFinite(Number(point.latitud))||!Number.isFinite(Number(point.longitud)))continue;const icon=L.divIcon({className:'scene-marker scene-marker-'+point.tipo,html:symbols[point.tipo],iconSize:[34,34],iconAnchor:[17,17]});L.marker([point.latitud,point.longitud],{icon,title:point.titulo}).bindPopup(pointNode(point)).addTo(state.markers);const row=document.createElement('li');row.dataset.sceneKind=point.tipo;row.append(pointNode(point));list.append(row);}
   state.status.textContent=result.puntos.length?result.puntos.length+(result.puntos.length===1?' conexión visible.':' conexiones visibles.')+' Cada punto muestra una zona aproximada.':'Aún no hay conexiones visibles aquí. Prueba otra zona o agrega la tuya.';
  }catch(failure){if(active===state&&state.controller===controller)state.status.textContent=failure.name==='AbortError'?'La búsqueda tardó demasiado. Puedes volver a aplicar los filtros.':failure.message;}finally{clearTimeout(timeout);}
 }
 function open(state){
  if(active!==state||state.map)return;
  if(!window.L){error(state,'No pudimos iniciar el mapa. Actualiza esta pantalla y comprueba tu conexión.');return;}
  try{
   state.map=L.map(state.root.querySelector('[data-map-canvas]'),{maxBounds:[[-85,-180],[85,180]],maxBoundsViscosity:1,zoomControl:true,minZoom:3,maxZoom:12});state.map.setView([20.7,-103.3],7);state.root.querySelector('[data-map-consent]').hidden=true;
   if(state.root.dataset.tiles==='1'){
    state.failedTiles=new Set();state.layer=L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:12,noWrap:true,attribution:'© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>'});
    state.layer.on('tileerror',event=>{state.failedTiles.add(event.tile);error(state,'No pudo cargar el fondo del mapa. Puedes reintentar; la lista de conexiones sigue disponible.');});
    const clearTile=event=>{state.failedTiles.delete(event.tile);if(active===state&&!state.failedTiles.size&&state.error)state.error.hidden=true;};state.layer.on('tileload',clearTile).on('tileunload',clearTile).addTo(state.map);
   }else{L.control.attribution({prefix:false}).addTo(state.map).addAttribution('Vista de prueba · sin mapas externos');}
   state.markers=L.layerGroup().addTo(state.map);
   if(state.editor){const lat=state.root.querySelector('[data-map-lat]'),lon=state.root.querySelector('[data-map-lon]');let circle=null;const choose=(a,b)=>{lat.value=rounded(a).toFixed(1);lon.value=rounded(b).toFixed(1);circle?.remove();circle=L.circle([Number(lat.value),Number(lon.value)],{radius:10000,color:'#7046bb',fillOpacity:.13}).addTo(state.map);state.status.textContent='Zona aproximada seleccionada. Confirma abajo si quieres mostrarla.';};if(lat.value&&lon.value&&Number.isFinite(Number(lat.value))&&Number.isFinite(Number(lon.value))){state.map.setView([Number(lat.value),Number(lon.value)],9);choose(Number(lat.value),Number(lon.value));}state.map.on('click',event=>choose(event.latlng.lat,event.latlng.lng));}
   else{state.map.on('moveend',()=>{clearTimeout(state.timer);state.timer=setTimeout(()=>load(state),400);});load(state);}
   requestAnimationFrame(()=>{if(active===state)state.map.invalidateSize({pan:false});});
   if(window.ResizeObserver){state.resize=new ResizeObserver(()=>{if(active===state)state.map.invalidateSize({pan:false});});state.resize.observe(state.root.querySelector('[data-map-canvas]'));}
  }catch(_){state.map?.remove();state.map=null;state.root.querySelector('[data-map-consent]').hidden=false;error(state,'No pudimos abrir el mapa. Recarga esta pantalla y vuelve a intentar.');}
 }
 function init(){stop();const root=document.querySelector('[data-scene-map]');if(!root)return;const state={root,status:root.querySelector('[data-map-status]'),error:root.querySelector('[data-map-error]'),editor:root.hasAttribute('data-map-editor')};active=state;root.querySelector('[data-map-open]')?.addEventListener('click',()=>open(state));root.querySelector('[data-map-retry]')?.addEventListener('click',()=>{if(state.error)state.error.hidden=true;if(!state.map){open(state);return;}state.failedTiles?.clear();state.layer?.redraw();state.map.invalidateSize({pan:false});if(!state.editor)load(state);});root.querySelector('[data-scene-filters]')?.addEventListener('submit',event=>{event.preventDefault();if(state.map)load(state);else state.status.textContent='Abre el mapa para aplicar tus filtros.';});}
 document.addEventListener('musicmad:beforepage',stop);document.addEventListener('musicmad:page',init);document.addEventListener('visibilitychange',()=>{if(document.hidden)active?.controller?.abort();else if(active?.map&&!active.editor)load(active);});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

