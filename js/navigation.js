/* Navigation keeps the audio shell alive; one generation owns each page. */
(() => {
  const neverFetch = /\/(?:audio|video|partitura|clip-medio|evento-ics|banda-ics|cartel-evento|pista-publica|exportar-datos|eliminar-cuenta|reactivar-cuenta|logout|google-inicio|google-regreso|perfil-estilos)\.php$/i;
  const scriptKey = value => { const url = new URL(value, location.href); return url.origin + url.pathname; };
  const loadedScripts = new Set([...document.scripts].map(node => node.src).filter(Boolean).map(scriptKey));
  const pendingForms = new WeakSet();
  let request = null, generation = 0, scrollTimer;
  const toast = (message, type = 'error') => document.dispatchEvent(new CustomEvent('musicmad:toast', { detail: { message, type } }));
  const route = (value, method = 'GET') => { try { const u = new URL(value, document.baseURI || location.href); const clean = method === 'POST' ? window.MusicMadRoutePaths?.isPost(u.href) : window.MusicMadRoutePaths?.isPage(u.href); return u.origin === location.origin && ((/\.php$/i.test(u.pathname) && !neverFetch.test(u.pathname)) || clean) ? u : null; } catch (_) { return null; } };
  const savePosition = () => history.replaceState({ ...history.state, musicmad: true, scroll: [scrollX, scrollY] }, '', location.href);
  const waitForAsset=(node,key)=>new Promise((resolve,reject)=>{
    const timer=setTimeout(()=>{node.remove();reject(new Error('asset-timeout'));},12000);
    node.onload=()=>{clearTimeout(timer);if(key)loadedScripts.add(key);resolve();};
    node.onerror=()=>{clearTimeout(timer);node.remove();reject(new Error('asset'));};
  });
  history.scrollRestoration = 'manual';
  history.replaceState({ ...history.state, musicmad: true, scroll: [scrollX, scrollY] }, '', location.href);
  addEventListener('scroll', () => { clearTimeout(scrollTimer); scrollTimer = setTimeout(savePosition, 120); }, { passive: true });

  async function assets(doc, pageURL = location.href) {
    const wanted = new Set();
    const assetBase = doc.querySelector('base[href]')?.href || pageURL;
    for (const link of doc.querySelectorAll('link[rel="stylesheet"]:not([data-core-css])')) {
      const href = new URL(link.getAttribute('href'), assetBase).href;
      if (new URL(href).origin !== location.origin) continue;
      wanted.add(href);
      if (![...document.querySelectorAll('link[data-pjax-css]')].some(node => node.href === href)) {
        const clone = document.createElement('link');
        clone.rel = 'stylesheet'; clone.href = href; clone.dataset.pjaxCss = '1';
        if (link.hasAttribute('data-profile-theme')) clone.dataset.profileTheme = '';
        const ready = waitForAsset(clone);
        document.head.insertBefore(clone, document.querySelector('link[data-mobile-css]'));
        await ready;
      }
    }
    document.querySelectorAll('link[data-pjax-css]').forEach(link => { if (!wanted.has(link.href)) link.remove(); });
    for (const script of doc.querySelectorAll('script[src]')) {
      const url = new URL(script.getAttribute('src'), assetBase);
      const reloadLanguage = /\/assets\/i18n\/(?:es|en|pt-BR|fr|de|it|ja|ko|zh-CN)\.js$/.test(url.pathname) && window.MusicMadMessagesLocale !== doc.documentElement.lang;
      if (url.origin !== location.origin || /\/(?:app|pwa|player|navigation|session-state|mobile-ui|sheets|comments|waveform|waveform-math|listening-math|listening-statistics|refresh|refresh-math|pending-store|pending-actions)\.js$/.test(url.pathname) || (loadedScripts.has(scriptKey(url.href)) && !reloadLanguage)) continue;
      const node = document.createElement('script'); node.src = url.href;
      const ready=waitForAsset(node,scriptKey(url.href));document.head.append(node);await ready;
    }
  }
  function shell(doc) {
    const oldBase=document.querySelector('base[href]'),nextBase=doc.querySelector('base[href]');
    if(nextBase){if(oldBase)oldBase.href=nextBase.href;else document.head.prepend(document.importNode(nextBase,true));}
    else oldBase?.remove();
    document.documentElement.lang=doc.documentElement.lang || 'es';
    const manifest=document.querySelector('link[rel=manifest]'),nextManifest=doc.querySelector('link[rel=manifest]');
    if(manifest && nextManifest)manifest.href=nextManifest.href;
    for (const selector of ['.navbar-inner', '.mobile-dock', '.app-sidebar']) {
      const old = document.querySelector(selector), next = doc.querySelector(selector);
      if (old && next) old.replaceWith(document.importNode(next, true));
      else if (old) old.remove();
      else if (next) document.body.append(document.importNode(next, true));
    }
    for (const name of ['has-mobile-dock', 'has-app-sidebar', 'onboarding-layout']) {
      document.body.classList.toggle(name, doc.body.classList.contains(name));
    }
    for (const name of ['musicmad-user', 'musicmad-sync', 'musicmad-account', 'musicmad-csrf']) {
      document.querySelector('meta[name="'+name+'"]')?.setAttribute('content', doc.querySelector('meta[name="'+name+'"]')?.content || '');
    }
    window.MusicMadSetAccount?.(doc.querySelector('meta[name="musicmad-account"]')?.content || '');
  }
  async function install(response, html, { push = true, position = null, id = generation, targetHash = '' } = {}) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    if (!doc.querySelector('main')) throw new Error('incomplete');
    const currentVersion=document.querySelector('meta[name="musicmad-version"]')?.content;
    const incomingVersion=doc.querySelector('meta[name="musicmad-version"]')?.content;
    if(currentVersion && incomingVersion && currentVersion!==incomingVersion){
      navigator.serviceWorker?.ready.then(registration=>registration.update()).catch(()=>{});
      document.dispatchEvent(new CustomEvent('musicmad:version'));
      throw new Error('version');
    }
    if (id !== generation) return false;
    await assets(doc, response.url || location.href);
    if (id !== generation) return false;
    document.dispatchEvent(new CustomEvent('musicmad:beforepage'));
    document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
    document.querySelector('main').replaceWith(document.importNode(doc.querySelector('main'), true));
    document.title = doc.title || 'MusicMad'; shell(doc);
    const destination = new URL(response.url || location.href);
    if(targetHash) destination.hash=targetHash;
    const url = destination.href;
    if (push) history.pushState({ musicmad: true, scroll: [0, 0] }, '', url);
    document.dispatchEvent(new CustomEvent('musicmad:page', { detail: { url, restore: Boolean(position) } }));
    requestAnimationFrame(() => {
      const target = new URL(url).hash;
      if (position) window.scrollTo(...position);
      else if (target) { try { document.getElementById(decodeURIComponent(target.slice(1)))?.scrollIntoView(); } catch (_) {} }
      else window.scrollTo(0, 0);
      if (!position) {
        const focus = document.querySelector('main h1, main');
        if (focus) { focus.setAttribute('tabindex', '-1'); focus.focus({ preventScroll: true }); }
      }
    });
    return true;
  }
  async function loadPage(value, { push = true, position = null } = {}) {
    const url = route(value);
    if (!url || !document.querySelector('main')) { location.assign(value); return; }
    if (push) savePosition();
    request?.abort(); request = new AbortController(); const controller=request,id = ++generation;
    let timedOut=false;const timeout=setTimeout(()=>{timedOut=true;controller.abort();},15000);
    const main = document.querySelector('main'); main.classList.add('is-loading'); main.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url.href, { credentials: 'same-origin', signal: request.signal, headers: { 'X-Requested-With': 'MusicMad-PJAX' } });
      if (!response.ok) throw new Error('HTTP '+response.status);
      const html=await response.text();clearTimeout(timeout);
      return await install(response, html, { push, position, id, targetHash: url.hash });
    } catch (error) {
      if (error.message==='version') { toast('Hay una actualización lista. Toca Actualizar para abrir la nueva versión; conservamos tu música y esta pantalla.'); return false; }
      if ((error.name !== 'AbortError'||timedOut) && id === generation) toast(navigator.onLine ? (timedOut?'La conexión tardó demasiado. Toca otra vez para reintentar.':'No pudimos abrir esta sección. Reintenta desde el enlace.') : 'Sin conexión. Tu música guardada sigue disponible; reintenta esta sección al volver internet.');
      return false;
    } finally {
      clearTimeout(timeout);
      if (id === generation) { document.querySelector('main')?.classList.remove('is-loading'); document.querySelector('main')?.removeAttribute('aria-busy'); }
    }
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download') || link.hasAttribute('data-native-navigation')) return;
    const url = route(link.href);
    if (!url || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
    event.preventDefault(); loadPage(url.href);
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.target || form.hasAttribute('data-native-navigation')) return;
    const url = route(form.action || location.href, form.method.toUpperCase()); if (!url) return;
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) { event.preventDefault(); return; }
    const body = new FormData(form); if (event.submitter?.name) body.append(event.submitter.name, event.submitter.value);
    if (form.method.toUpperCase() === 'GET') {
      event.preventDefault(); url.search = '';
      for (const [key, value] of body) if (typeof value === 'string' && value) url.searchParams.append(key, value);
      await loadPage(url.href); return;
    }
    if (form.method.toUpperCase() !== 'POST' || (form.matches('[data-upload-form]') && [...form.querySelectorAll('input[type=file]')].some(input => input.files?.length))) return;
    if (!/\/(?:social-accion|perfil|configuracion|login|registro|cambiar-REPLACE_WITH_LOCAL_VALUE|notificaciones|mensajes|banda-accion|ubicacion|evento-accion|epk|sala-accion|colab-accion|clip-accion|reto-accion|recomendacion-accion|bienvenida-accion|feedback-accion|playlist-equipo-accion|repost-accion|verificaciones)\.php$/.test(url.pathname) && !window.MusicMadRoutePaths?.isPost(url.href)) return;
    event.preventDefault(); if (pendingForms.has(form)) return;
    pendingForms.add(form); form.setAttribute('aria-busy', 'true');
    const buttons = [...document.querySelectorAll('button:not([type="button"]):not([type="reset"]),input[type="submit"],input[type="image"]')].filter(button => button.form === form);
    const previous = buttons.map(button => button.disabled); buttons.forEach(button => button.disabled = true);
    const id = generation,controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
    try {
      const response = await fetch(url.href, { method: 'POST', credentials: 'same-origin', body, signal:controller.signal, headers: { 'X-Requested-With': 'MusicMad-PJAX' } });
      if ((response.headers.get('Content-Type') || '').includes('application/json')) {
        const result = await response.json();
        if (!response.ok || result.ok === false) { toast(result.mensaje || result.flash?.[1] || 'No se pudo guardar. Tu borrador sigue aquí.'); return; }
        if (result.flash?.[1]) toast(result.flash[1], result.flash[0]);
        if (id === generation) await loadPage(new URL(result.redirect || 'feed.php', document.baseURI || location.href).href);
      } else {
        if (!response.ok) throw new Error('HTTP '+response.status);
        if (id === generation) { savePosition(); await install(response, await response.text(), { id }); }
      }
    } catch (_) { toast(navigator.onLine ? 'No se pudo guardar. Conservamos este formulario para que reintentes.' : 'Sin internet. Conservamos el formulario; vuelve a enviar cuando regreses.'); }
    finally { clearTimeout(timeout);pendingForms.delete(form); form.removeAttribute('aria-busy'); buttons.forEach((button, index) => button.disabled = previous[index]); }
  });
  addEventListener('popstate', event => loadPage(location.href, { push: false, position: event.state?.scroll || [0, 0] }));
  window.MusicMadNavigate = (value, options) => loadPage(value, options);
})();
