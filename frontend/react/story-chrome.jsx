import React, {useSyncExternalStore} from 'react';
import {createRoot} from 'react-dom/client';
import {flushSync} from 'react-dom';

const paths = {
  play: 'm9 5 11 7-11 7Z', pause: 'M8 5v14M16 5v14',
  muted: 'm11 5-6 5H2v4h3l6 5ZM16 9l6 6M16 15l6-6',
  sound: 'm11 5-6 5H2v4h3l6 5ZM16 8a6 6 0 0 1 0 8M19 4a11 11 0 0 1 0 16',
  close: 'm6 6 12 12M6 18 18 6', prev: 'm15 5-7 7 7 7', next: 'm9 5 7 7-7 7'
};
const Icon = ({name}) => <svg viewBox="0 0 24 24" aria-hidden="true"><path d={paths[name] || paths.play}/></svg>;

function StoryChrome({store, actions, text}) {
  const view = useSyncExternalStore(store.subscribe, store.read);
  return <>
    <div className="story-viewer-top">
      <div className="story-segments" data-story-segments aria-label={text('Historias de este autor')}>
        {Array.from({length:view.count}, (_, index) => <progress key={index} max="1"
          value={index < view.position ? 1 : index === view.position ? view.progress : 0}
          aria-label={text('Historia {number} de {total}').replace('{number}', String(index + 1)).replace('{total}', String(view.count))}/>) }
      </div>
      <div className="story-viewer-toolbar"><span data-story-position>{view.position + 1} / {view.count}</span>
        <button type="button" data-story-close aria-label={text('Cerrar historias')} onClick={actions.close}><Icon name="close"/></button>
      </div>
    </div>
    <button type="button" className="story-arrow story-arrow-prev" data-story-prev aria-label={text('Historia anterior')} onClick={actions.previous}><Icon name="prev"/></button>
    <button type="button" className="story-arrow story-arrow-next" data-story-next aria-label={text('Historia siguiente')} onClick={actions.next}><Icon name="next"/></button>
    <button type="button" className="story-media-control" data-story-control data-icon={view.icon} aria-label={view.label} onClick={actions.control}><Icon name={view.icon}/></button>
    <p className="story-viewer-status" data-story-feedback role="status">{view.feedback}</p>
  </>;
}

export function mountStoryChrome(host, actions, catalogue = {}) {
  const fallback = host.innerHTML, listeners = new Set();
  const text = key => typeof catalogue[key] === 'string' ? catalogue[key] : key;
  let active = true, lastProgress = 0;
  let snapshot = {count:1, position:0, progress:0, icon:'play', label:text('Reproducir historia'), feedback:''};
  const store = {read:() => snapshot, subscribe:listener => {listeners.add(listener);return () => listeners.delete(listener);}};
  const update = patch => {
    if (!active || Object.keys(patch).every(key => snapshot[key] === patch[key])) return;
    snapshot = {...snapshot, ...patch}; listeners.forEach(listener => listener());
  };
  const root = createRoot(host);
  // The existing media controller needs the close button immediately for focus.
  flushSync(() => root.render(<StoryChrome store={store} actions={actions} text={text}/>));
  host.dataset.reactMounted = '1';
  return {
    show:(count, position) => update({count, position, progress:0}),
    control:(icon, label) => update({icon, label:text(label)}),
    feedback:value => update({feedback:text(value)}),
    progress:value => {
      const now = performance.now();
      if (value !== 1 && now - lastProgress < 33) return;
      lastProgress = now; update({progress:Math.max(0, Math.min(1, value))});
    },
    unmount:() => {
      if (!active) return;
      active = false; root.unmount(); listeners.clear();
      host.innerHTML = fallback; delete host.dataset.reactMounted;
    }
  };
}

window.MusicMadStoryChrome = {mount:mountStoryChrome};
