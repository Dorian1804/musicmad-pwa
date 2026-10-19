(() => {
  let scope = document.querySelector('meta[name="musicmad-account"]')?.content || '';
  const rememberScope = () => { try { if(scope){localStorage.setItem('musicmad.offline.scope',scope);localStorage.setItem('musicmad.offline.owner',document.querySelector('meta[name=musicmad-user]')?.content || '0');}else{localStorage.removeItem('musicmad.offline.scope');localStorage.removeItem('musicmad.offline.owner');} } catch(_) {} };
  const tellWorker = () => { rememberScope(); navigator.serviceWorker?.controller?.postMessage({ type:'SET_ACCOUNT', scope }); };
  window.MusicMadSetAccount = (next) => {
    next = /^[a-f0-9]{64}$/.test(next || '') ? next : '';
    if (next !== scope) {
      const previous = scope; scope = next;
      document.dispatchEvent(new CustomEvent('musicmad:session', { detail:{ scope, previous } }));
    }
    tellWorker();
  };
  navigator.serviceWorker?.addEventListener('controllerchange', tellWorker);
  navigator.serviceWorker?.ready.then(tellWorker).catch(() => {});
  const updatePage = () => {
    const ids = [...new Set([...document.querySelectorAll('[data-profile-style]')].map(n => n.dataset.profileStyle))].filter(v=>/^\d+$/.test(v)).slice(0,100);
    let link = document.querySelector('link[data-profile-theme]');
    if (ids.length) {
      if (!link) { link=document.createElement('link'); link.rel='stylesheet'; link.dataset.pjaxCss=''; link.dataset.profileTheme=''; document.head.insertBefore(link, document.querySelector('link[data-mobile-css]')); }
      const target = new URL(link.href || 'perfil-estilos.php', location.href); target.searchParams.set('ids', ids.join(','));
      const href = target.href;
      if (link.href !== href) link.href=href;
    }
    if (location.hash === '#compartir') { const details=document.querySelector('#compartir'); if(window.MusicMadOpenSheet)window.MusicMadOpenSheet(details);else details?.setAttribute('open',''); }
  };
  document.addEventListener('musicmad:page', updatePage);
  document.addEventListener('musicmad:posts', updatePage);
  document.addEventListener('click', e => {
    if (e.target.closest('[data-open-composer]') && location.pathname.endsWith('/feed.php')) { const details=document.querySelector('#compartir'); if(window.MusicMadOpenSheet)window.MusicMadOpenSheet(details);else details?.setAttribute('open',''); }
  });
})();
