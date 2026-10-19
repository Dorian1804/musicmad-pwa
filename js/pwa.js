// ============================================================
//  MusicMad – Lógica PWA
//  js/pwa.js
//
//   1. Registra el Service Worker y avisa cuando hay versión nueva.
//   2. Botón "Instalar app" (Android/escritorio) + ayuda para iOS.
//   3. Aviso de conexión (en línea / sin conexión).
//   4. Limpia páginas guardadas al cerrar sesión.
// ============================================================
(() => {
  'use strict';

  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  // ── Toast reutilizable ────────────────────────────────────
  function mostrarToast(texto, accionTexto, accion, duracion = 0) {
    quitarToast();
    const t = document.createElement('div');
    t.className = 'pwa-toast';
    t.id = 'pwa-toast';
    t.setAttribute('role', 'status');

    const span = document.createElement('span');
    span.textContent = texto;
    t.appendChild(span);

    if (accionTexto && accion) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = accionTexto;
      b.addEventListener('click', () => { if(accion()!==false)quitarToast(); });
      t.appendChild(b);
    }
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'pwa-toast-close';
    x.setAttribute('aria-label', 'Cerrar');
    x.textContent = '×';
    x.addEventListener('click', quitarToast);
    t.appendChild(x);

    document.body.appendChild(t);
    if (duracion) setTimeout(quitarToast, duracion);
  }
  function quitarToast() {
    const t = document.getElementById('pwa-toast');
    if (t) t.remove();
  }

  function prepararActualizacion(){
    if(document.querySelector('form[aria-busy="true"],form[data-photo-processing="1"],form[data-sync-busy="1"]')){
      mostrarToast('Termina o cancela el envío antes de actualizar MusicMad.',null,null,6000);return false;
    }
    const files=[...document.querySelectorAll('input[type=file]')].some(input=>input.files?.length);
    const drafts=[...document.querySelectorAll('form[method=POST] textarea,form[method=post] textarea')].some(input=>input.value!==input.defaultValue);
    if(files || drafts){mostrarToast('Guarda, publica o descarta tu borrador antes de actualizar. Tus cambios siguen en esta pantalla.',null,null,7000);return false;}
    return document.dispatchEvent(new CustomEvent('musicmad:before-update',{cancelable:true}));
  }
  document.addEventListener('musicmad:version',()=>{
    // Another tab may already have activated the worker; waiting can be empty.
    mostrarToast('Hay una nueva versión. Actualiza para continuar navegando.','Actualizar',()=>{
      if(!prepararActualizacion())return false;
      navigator.serviceWorker?.getRegistration().then(reg=>{
        if(reg?.waiting){sessionStorage.setItem('musicmad.reload-update','1');reg.waiting.postMessage({type:'SKIP_WAITING'});}
        else location.reload();
      }).catch(()=>location.reload());
      return true;
    });
  });

  // ── 1. Service Worker ─────────────────────────────────────
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
      try {
        const reg = await navigator.serviceWorker.register('sw.js', { scope: './' });

        const avisarActualizacion = (worker) =>
          mostrarToast('Hay una nueva versión de MusicMad.', 'Actualizar',
                       () => {
                         if(!prepararActualizacion())return false;
                         if(worker.state==='activated'){location.reload();return true;}
                         sessionStorage.setItem('musicmad.reload-update','1');worker.postMessage({type:'SKIP_WAITING'});
                       });

        if (reg.waiting && navigator.serviceWorker.controller) avisarActualizacion(reg.waiting);

        reg.addEventListener('updatefound', () => {
          const nuevo = reg.installing;
          if (!nuevo) return;
          nuevo.addEventListener('statechange', () => {
            if (nuevo.state === 'installed' && navigator.serviceWorker.controller) {
              avisarActualizacion(nuevo);
            }
          });
        });

        // Busca actualizaciones cada hora mientras la app está abierta
        setInterval(() => reg.update().catch(() => {}), 60 * 60 * 1000);
      } catch (err) {
        console.error('No se pudo registrar el Service Worker:', err);
      }
    });

    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (sessionStorage.getItem('musicmad.reload-update') === '1') {
        sessionStorage.removeItem('musicmad.reload-update'); location.reload();
      }
    });

    // Solo la actualización elegida por el usuario recarga el shell completo.
    // El player conserva su cola/posición antes de esa recarga.

    // 4. Al cerrar sesión se borran las páginas guardadas
    document.addEventListener('submit', (ev) => {
      const formulario = ev.target;
      if (formulario && formulario.action && formulario.action.includes('logout.php') && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_PAGES' });
        try { localStorage.removeItem('musicmad.offline.scope');localStorage.removeItem('musicmad.offline.owner'); } catch (_) {}
      }
    });
  }

  // ── 2. Instalación ────────────────────────────────────────
  let promptInstalacion = null;

  const yaInstalada = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  const esIOS = () =>
    /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  function botonesInstalar(visible) {
    $$('[data-pwa-install]').forEach((b) => { b.hidden = !visible && !b.hasAttribute('data-onboard-install'); });
  }

  window.addEventListener('beforeinstallprompt', (ev) => {
    ev.preventDefault();
    promptInstalacion = ev;
    if (!yaInstalada()) botonesInstalar(true);
  });

  window.addEventListener('appinstalled', () => {
    promptInstalacion = null;
    botonesInstalar(false);
    mostrarToast('¡MusicMad instalada!', null, null, 4000);
  });

  document.addEventListener('DOMContentLoaded', () => {
    // iOS no tiene beforeinstallprompt: mostramos el botón con instrucciones
    if (esIOS() && !yaInstalada()) botonesInstalar(true);

  });
  document.addEventListener('click', async event => {
    const button = event.target.closest?.('[data-pwa-install]');
    if (!button) return;
    event.preventDefault();
    if (yaInstalada()) {
      mostrarToast('MusicMad ya está abierta como aplicación instalada.', null, null, 5000);
    } else if (promptInstalacion) {
      const prompt = promptInstalacion;
      promptInstalacion = null;
      try { await prompt.prompt(); await prompt.userChoice; }
      catch (_) { mostrarToast('Abre el menú del navegador para instalar MusicMad.', null, null, 6000); }
      botonesInstalar(false);
    } else if (esIOS()) {
      mostrarToast('En Safari toca Compartir y luego "Agregar a pantalla de inicio".');
    } else {
      mostrarToast('Abre el menú del navegador y busca Instalar aplicación o Agregar a pantalla de inicio.');
    }
  });

  // ── 3. Conexión ───────────────────────────────────────────
  function barraConexion() {
    let barra = document.getElementById('pwa-offline');
    if (!navigator.onLine) {
      if (!barra) {
        barra = document.createElement('div');
        barra.id = 'pwa-offline';
        barra.className = 'pwa-offline';
        barra.textContent = 'Sin conexión. Las acciones elegidas quedan pendientes y tu música guardada sigue disponible. Iniciar sesión y subir archivos necesitan internet.';
        barra.setAttribute('role', 'status');
        barra.setAttribute('aria-live', 'polite');
        document.body.appendChild(barra);
      }
    } else if (barra) {
      barra.remove();
      mostrarToast('Conexión restablecida. Ya puedes iniciar sesión y usar MusicMad.', null, null, 4500);
    }
  }
  document.addEventListener('submit', (ev) => {
    if (navigator.onLine) return;
    ev.preventDefault();
    const esLogin = ev.target && ev.target.id === 'formLogin';
    mostrarToast(esLogin
      ? 'Para iniciar sesión necesitas internet. Vuelve a intentarlo cuando tengas conexión.'
      : 'Sin internet: esta acción no se envió. Reconéctate y vuelve a intentarlo.', null, null, 7000);
  }, true);
  window.addEventListener('online',  barraConexion);
  window.addEventListener('offline', barraConexion);
  document.addEventListener('DOMContentLoaded', barraConexion);
})();
