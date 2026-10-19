/* The catalogue contains fixed UI strings; render results as text, never HTML. */
(() => {
  window.MusicMadText = (key, parameters = {}) => {
    const catalogue = globalThis.MusicMadMessages || {};
    const template = Object.prototype.hasOwnProperty.call(catalogue, key) ? catalogue[key] : String(key);
    return template.replace(/\{([a-z_]+)\}/g, (marker, name) =>
      Object.prototype.hasOwnProperty.call(parameters, name) && ['string','number'].includes(typeof parameters[name])
        ? String(parameters[name]) : marker);
  };
})();
