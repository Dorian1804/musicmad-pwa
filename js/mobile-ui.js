/* Keyboard-aware layouts use external CSS buckets, never inline styles. */
(() => {
  const viewport = window.visualViewport;
  const editable = element => element?.matches?.('textarea,input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]),[contenteditable=true]');
  const update = () => {
    const typing = editable(document.activeElement);
    const keyboard = Boolean(typing && viewport && innerHeight - viewport.height > 120 && viewport.scale < 1.1);
    document.body.classList.toggle('keyboard-open', keyboard);
    // 20px rounding leaves a small safe margin above the software keyboard.
    if (keyboard) document.body.dataset.keyboardHeight = String(Math.max(240, Math.min(1000, Math.floor(viewport.height / 20) * 20)));
    else delete document.body.dataset.keyboardHeight;
  };
  viewport?.addEventListener('resize', update);
  viewport?.addEventListener('scroll', update);
  addEventListener('resize', update);
  document.addEventListener('focusin', () => {
    update();
    setTimeout(() => {
      if (document.body.classList.contains('keyboard-open') && !document.activeElement.closest('.chat-form')) document.activeElement.scrollIntoView({ block: 'center', behavior: 'instant' });
    }, 180);
  });
  document.addEventListener('focusout', () => setTimeout(update, 30));
  document.addEventListener('musicmad:page', update);
  document.addEventListener('musicmad:beforepage', () => document.body.classList.remove('keyboard-open'));
  update();
})();
