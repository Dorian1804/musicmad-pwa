/* Explicit, short microphone capture. Always release microphone on leaving. */
(() => {
 let current=null,generation=0;
 const status=(s,text)=>{s.form.querySelector('[data-record-status]').textContent=text;};
 function controls(s,busy){s.form.querySelector('[data-record-start]').disabled=busy;s.form.querySelector('[data-record-stop]').hidden=!busy;s.form.querySelector('[data-record-submit]').disabled=busy;s.input.disabled=busy;}
 function release(s){clearInterval(s.timer);clearTimeout(s.permissionTimeout);s.stream?.getTracks().forEach(track=>track.stop());if(s.base){s.base.removeEventListener('ended',s.baseEnded);s.base.pause();s.base=null;}}
 function dispose(){generation++;const s=current;current=null;if(!s)return;s.cancelled=true;s.form.querySelector('[data-record-preview]')?.pause();release(s);if(s.recorder?.state==='recording')s.recorder.stop();if(s.url)URL.revokeObjectURL(s.url);}
 function init(){dispose();const form=document.querySelector('[data-studio-recorder]');if(!form)return;current={form,input:form.querySelector('input[name=audio]'),busy:false,parts:[]};if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder||!window.OfflineAudioContext){form.querySelector('[data-record-start]').disabled=true;status(current,'Este navegador no permite grabar aquí. Puedes elegir un archivo de audio.');}}
 async function finish(s){
  release(s);if(s.cancelled||current!==s)return;
  s.form.querySelector('[data-record-stop]').hidden=true;status(s,'Preparando tu toma en WAV…');
  try{
   const blob=new Blob(s.parts,{type:s.recorder.mimeType});s.parts=[];
   if(!blob.size||blob.size>16*1048576)throw new Error('La toma está vacía o es demasiado grande. Prueba otra vez.');
   const context=new OfflineAudioContext(1,48000,48000),buffer=await context.decodeAudioData(await blob.arrayBuffer());
   if(s.cancelled||current!==s)return;
   const max=Math.min(buffer.length,buffer.sampleRate*90),channels=Array.from({length:Math.min(2,buffer.numberOfChannels)},(_,i)=>buffer.getChannelData(i).subarray(0,max));
   const file=new File([window.MusicMadRecorderMath.wav(channels,buffer.sampleRate)],'toma-'+Date.now()+'.wav',{type:'audio/wav'});
   if(s.url)URL.revokeObjectURL(s.url);s.url=URL.createObjectURL(file);s.recordedFile=file;
   const preview=s.form.querySelector('[data-record-preview]');preview.src=s.url;preview.hidden=false;
   const download=s.form.querySelector('[data-record-download]');download.href=s.url;download.download=file.name;download.hidden=false;
   try{const transfer=new DataTransfer();transfer.items.add(file);s.input.files=transfer.files;s.input.dispatchEvent(new Event('change',{bubbles:true}));status(s,'Toma lista. Escúchala, añade su nombre y toca Subir y agregar aporte.');}
   catch(_){status(s,'Toma lista. Descárgala y selecciónala en el campo de audio para subirla.');}
  }catch(error){status(s,error.message||'No pudimos preparar esta toma. Puedes subir un archivo.');}
  finally{if(current===s&&!s.cancelled){s.busy=false;controls(s,false);}}
 }
 async function record(s){
  if(s.busy||s.form.getAttribute('aria-busy')==='true')return;
  s.busy=true;s.cancelled=false;s.recorder=null;s.form.querySelector('[data-record-clock]').textContent='0:00 / 1:30';controls(s,true);status(s,'Autoriza el micrófono para empezar.');const ticket=++generation;
  s.permissionTimeout=setTimeout(()=>{if(current===s&&ticket===generation&&!s.recorder){generation++;s.busy=false;controls(s,false);status(s,'No recibimos acceso al micrófono. Toca Grabar para intentar otra vez.');}},20000);
  try{
   const stream=await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:false,noiseSuppression:false,autoGainControl:false}});
   if(current!==s||ticket!==generation||s.cancelled){stream.getTracks().forEach(t=>t.stop());return;}
   clearTimeout(s.permissionTimeout);s.stream=stream;s.parts=[];
   document.querySelector('[data-global-audio]')?.pause();document.querySelectorAll('[data-studio-mix] audio,[data-record-preview]').forEach(a=>a.pause());
   const type=['audio/webm;codecs=opus','audio/mp4','audio/ogg;codecs=opus'].find(m=>MediaRecorder.isTypeSupported(m));
   s.recorder=new MediaRecorder(stream,type?{mimeType:type,audioBitsPerSecond:192000}:undefined);let bytes=0;
   s.recorder.addEventListener('dataavailable',e=>{if(e.data.size){bytes+=e.data.size;s.parts.push(e.data);if(bytes>16*1048576&&s.recorder.state==='recording')s.recorder.stop();}});
   s.recorder.addEventListener('stop',()=>finish(s),{once:true});
   s.recorder.addEventListener('error',()=>{status(s,'La grabación se interrumpió. Puedes subir un archivo.');release(s);if(s.recorder.state==='recording')s.recorder.stop();});
   s.started=performance.now();s.recorder.start(1000);status(s,'Grabando tu toma. El micrófono se apaga al terminar.');
   if(s.form.querySelector('[data-record-base]')?.checked){
    const base=document.querySelector('[data-mix-base]');if(base){s.base=base;s.baseEnded=()=>{if(s.recorder?.state==='recording')s.recorder.stop();};base.addEventListener('ended',s.baseEnded);base.currentTime=0;
     base.play().then(()=>{if(current!==s||s.cancelled||s.recorder?.state!=='recording')base.pause();}).catch(()=>{if(current===s&&s.recorder?.state==='recording')status(s,'Grabando. El navegador no permitió iniciar la base; puedes tocar su botón de reproducción.');});
    }
   }
   s.timer=setInterval(()=>{const seconds=Math.min(90,Math.floor((performance.now()-s.started)/1000));s.form.querySelector('[data-record-clock]').textContent=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0')+' / 1:30';if(seconds>=90&&s.recorder.state==='recording')s.recorder.stop();},250);
  }catch(error){if(current===s&&ticket===generation){release(s);s.busy=false;controls(s,false);status(s,error.name==='NotAllowedError'?'No se autorizó el micrófono. Puedes elegir tu archivo.':'No pudimos iniciar el micrófono. Revisa su disponibilidad o elige un archivo.');}}
 }
 document.addEventListener('click',e=>{const button=e.target.closest('[data-record-start],[data-record-stop]');if(!button||!current||!current.form.contains(button))return;const s=current;if(button.hasAttribute('data-record-start'))record(s);else if(s.recorder?.state==='recording')s.recorder.stop();else{generation++;release(s);s.busy=false;controls(s,false);status(s,'Grabación cancelada.');}});
 document.addEventListener('change',e=>{const s=current;if(!s||e.target!==s.input||(s.recordedFile&&s.input.files?.[0]?.name===s.recordedFile.name&&s.input.files?.[0]?.size===s.recordedFile.size&&s.input.files?.[0]?.lastModified===s.recordedFile.lastModified))return;s.form.querySelector('[data-record-preview]').pause();s.form.querySelector('[data-record-preview]').hidden=true;s.form.querySelector('[data-record-download]').hidden=true;if(s.url){URL.revokeObjectURL(s.url);s.url=null;}const file=s.input.files?.[0];status(s,file?'Archivo elegido: '+file.name+' · '+(file.size/1048576).toFixed(1)+' MB. Añade su nombre para subirlo.':'Puedes grabar una toma o elegir un archivo.');});
 document.addEventListener('submit',e=>{if(current?.busy&&e.target===current.form){e.preventDefault();status(current,'Termina la grabación antes de subirla.');}},true);
 document.addEventListener('play',e=>{if(e.target.matches?.('[data-record-preview]')){document.querySelector('[data-global-audio]')?.pause();document.querySelectorAll('[data-studio-mix] audio').forEach(a=>a.pause());}},true);
 document.addEventListener('visibilitychange',()=>{if(document.hidden&&current?.recorder?.state==='recording')current.recorder.stop();});
 document.addEventListener('musicmad:beforepage',dispose);document.addEventListener('musicmad:session',dispose);document.addEventListener('musicmad:page',init);window.addEventListener('pagehide',dispose);init();
})();
