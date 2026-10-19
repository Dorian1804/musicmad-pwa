(function(root){
  const message=(error,mediaCode,diagnostic={})=>{
    if(diagnostic.login||[401,403].includes(diagnostic.status))return 'Tu sesión de reproducción cambió o venció. Recarga e inicia sesión de nuevo.';
    if(diagnostic.reason==='missing-file')return 'El servidor no encuentra el archivo de esta pista y no pudo recuperar una copia. Conserva la publicación; hay que revisar o restaurar el audio original.';
    if(diagnostic.reason==='invalid-record')return 'La referencia guardada de este audio no es válida. Conserva la publicación para revisar su archivo.';
    if(diagnostic.status===404)return 'Esta pista ya no está disponible o cambió su acceso. Revisa su publicación.';
    if(diagnostic.status>=500)return 'El servidor no pudo entregar esta pista. Puedes reintentar en unos momentos.';
    if(error?.name==='NotAllowedError'&&!mediaCode)return 'El navegador necesita un toque en Reproducir para iniciar el audio.';
    if(diagnostic.html)return 'El servidor devolvió una página en lugar del audio. Recarga e inicia sesión de nuevo.';
    if(mediaCode===3||mediaCode===4||error?.name==='NotSupportedError')return 'El navegador no pudo leer este audio. El archivo puede estar dañado o usar un formato no compatible.';
    return 'Se interrumpió la carga de esta pista. Revisa la conexión y vuelve a reproducir.';
  };
  function createReporter({notify,probe}){
    let generation=0,armed=false,reported=false,controller=null;
    const reset=(ready=false)=>{generation++;armed=ready;reported=false;controller?.abort();controller=null;};
    return {reset,async report(error,mediaCode,src){
      if(!armed||reported||error?.name==='AbortError'||mediaCode===1)return;
      reported=true;const current=generation;let diagnostic={};
      if(error?.name!=='NotAllowedError'||mediaCode){
        controller=new AbortController();
        try{diagnostic=await probe(src,controller.signal)||{};}catch(_){}
      }
      if(current===generation&&armed)notify(message(error,mediaCode,diagnostic));
    }};
  }
  const api={message,createReporter};
  if(typeof module==='object'&&module.exports)module.exports=api;else root.MusicMadPlaybackErrors=api;
})(globalThis);
