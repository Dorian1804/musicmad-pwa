(() => {
  const initialize = () => {
    const button = document.querySelector('[data-storage-probe]');
    if (!button || button.dataset.bound) return;
    button.dataset.bound = '1';
    button.addEventListener('click', async () => {
      const status = document.querySelector('[data-storage-probe-result]');
      const bytes = new Uint8Array(556), view = new DataView(bytes.buffer);
      const ascii = (offset, text) => [...text].forEach((letter, i) => bytes[offset + i] = letter.charCodeAt(0));
      ascii(0, 'RIFF'); view.setUint32(4, 548, true); ascii(8, 'WAVEfmt ');
      view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
      view.setUint32(24, 8000, true); view.setUint32(28, 16000, true);
      view.setUint16(32, 2, true); view.setUint16(34, 16, true); ascii(36, 'data'); view.setUint32(40, 512, true);
      const body = new FormData(); body.append('csrf', document.querySelector('meta[name=musicmad-csrf]').content);
      body.append('prueba', new Blob([bytes], {type:'audio/wav'}), 'prueba.wav');
      const endpoint = new URL('comprobar-almacen.php', document.baseURI);
      button.disabled = true; status.textContent = 'Comprobando recepción, guardado y respaldo…';
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(endpoint, {method:'POST', body, credentials:'same-origin', signal:controller.signal});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.stage || 'receive');
        endpoint.searchParams.set('comprobar', result.token);
        const read = await fetch(endpoint, {cache:'no-store', credentials:'same-origin', signal:controller.signal});
        const check = await read.json();
        if (!read.ok || !check.ok) throw new Error(check.stage || 'read');
        status.textContent = 'Correcto: el servidor recibió el archivo, guardó el respaldo y lo volvió a leer en otra petición.';
      } catch (error) {
        const reasons = {receive:'El archivo no llegó completo al servidor.', primary:'El servidor no puede guardar en el almacén permanente.', backup:'El archivo llegó, pero no se pudo confirmar su respaldo.', read:'El archivo o su respaldo no se pudo leer después de guardar.', validator:'El servidor guarda los archivos, pero falla la biblioteca que valida el audio.', schema:'El servidor guarda los archivos, pero la base de datos no tiene completa la estructura para registrarlos.', photos:'Faltan las extensiones GD o Fileinfo que necesita el servidor para guardar fotos.', rate:'Espera un minuto antes de repetir la prueba.', origin:'La dirección del sitio no coincide con su configuración.'};
        status.textContent = reasons[error.message] || 'No se pudo completar la comprobación. Revisa la conexión y vuelve a intentar.';
      } finally { clearTimeout(timer); button.disabled = false; }
    });
  };
  document.addEventListener('DOMContentLoaded', initialize);
  document.addEventListener('musicmad:page', initialize);
})();
