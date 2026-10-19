(() => {
  const selector = '[data-reaction-menu]';
  let press = null, suppressed = null, suppressionTimer = null;
  const closeOthers = except => document.querySelectorAll(selector + '[open]').forEach(menu => {
    if (menu !== except) menu.open = false;
  });
  const cancelPress = () => { if (press) clearTimeout(press.timer); press = null; };
  const clearSuppression = () => { clearTimeout(suppressionTimer); suppressed = null; };
  document.addEventListener('toggle', event => {
    if (event.target.matches?.(selector) && event.target.open) closeOthers(event.target);
  }, true);
  document.addEventListener('click', event => {
    if (suppressed?.contains(event.target)) {
      event.preventDefault(); event.stopImmediatePropagation(); clearSuppression(); return;
    }
    if (!event.target.closest?.(selector)) closeOthers(null);
  }, true);
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const menus = [...document.querySelectorAll(selector + '[open]')];
    if (!menus.length) return;
    const focused = menus.find(menu => menu.contains(document.activeElement)) || menus[0];
    closeOthers(null); cancelPress(); clearSuppression();
    focused.querySelector('summary')?.focus(); event.preventDefault();
  });
  // A short tap keeps the normal applause action. A stationary touch opens choices.
  document.addEventListener('pointerdown', event => {
    cancelPress(); clearSuppression();
    if (!['touch', 'pen'].includes(event.pointerType) || event.button !== 0 || event.isPrimary === false) return;
    const button = event.target.closest?.('[data-sync-action="aplaudir"] button');
    const menu = button?.closest('.post-reaction-control')?.querySelector(selector);
    if (!menu || button.disabled) return;
    press = { button, menu, id: event.pointerId, x: event.clientX, y: event.clientY, timer: null };
    press.timer = setTimeout(() => {
      if (!press || !button.isConnected || button.disabled) { cancelPress(); return; }
      closeOthers(menu); menu.open = true; suppressed = button;
      suppressionTimer = setTimeout(clearSuppression, 1500); press = null;
    }, 450);
  }, { passive: true });
  document.addEventListener('pointermove', event => {
    if (press && event.pointerId === press.id && Math.hypot(event.clientX - press.x, event.clientY - press.y) > 10) cancelPress();
  }, { passive: true });
  for (const name of ['pointerup', 'pointercancel']) document.addEventListener(name, cancelPress, { passive: true });
  document.addEventListener('contextmenu', event => {
    if (suppressed?.contains(event.target)) event.preventDefault();
  });
  const cleanup = () => { cancelPress(); clearSuppression(); closeOthers(null); };
  document.addEventListener('musicmad:beforepage', cleanup);
  document.addEventListener('musicmad:session', cleanup);
})();
