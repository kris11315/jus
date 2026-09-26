/**
 * Preserva UTMs e ids de clique no localStorage (para chat, pagamentos e relatórios).
 *
 * Antes existiam 2 cópias idênticas em front/js/tracking.js e cpf/js/tracking.js.
 * Agora há UMA cópia aqui, carregada por TODAS as páginas via
 * <script src="../../assets/js/tracking.js"></script>.
 *
 * API pública (window):
 *   gvdesenrolaMergeTracking(): object  — merge do localStorage + URL atual
 *   gvdesenrolaAppendQuery(url): string — reescreve href preservando UTMs/click-ids
 *
 * IMPORTANTE: manter a chave localStorage "utm_params_all" por compat com versões antigas
 * (links já em circulação na internet).
 */
(function () {
  var STORE_KEY = "utm_params_all";
  var CLICK_IDS = ["fbclid", "gclid", "wbraid", "gbraid", "ttclid", "msclkid"];
  var EXTRA_KEYS = ["src", "sck", "xcod"];

  function isTrackKey(key) {
    return key.indexOf("utm_") === 0 || CLICK_IDS.indexOf(key) >= 0 || EXTRA_KEYS.indexOf(key) >= 0;
  }

  var params = new URLSearchParams(window.location.search);
  var fromStorage = {};
  try {
    fromStorage = JSON.parse(localStorage.getItem(STORE_KEY) || "{}");
  } catch (e) {}

  var fromUrl = {};
  params.forEach(function (value, key) {
    if (isTrackKey(key) && value) fromUrl[key] = value;
  });

  var merged = Object.assign({}, fromStorage, fromUrl);
  if (Object.keys(merged).length > 0) {
    localStorage.setItem(STORE_KEY, JSON.stringify(merged));
  }

  window.gvdesenrolaMergeTracking = function () {
    var out = {};
    try {
      var s = JSON.parse(localStorage.getItem(STORE_KEY) || "{}");
      Object.keys(s).forEach(function (k) {
        if (s[k]) out[k] = s[k];
      });
    } catch (e) {}
    params.forEach(function (v, k) {
      if (v) out[k] = v;
    });
    return out;
  };

  window.gvdesenrolaAppendQuery = function (baseUrl) {
    var u = new URL(baseUrl, window.location.origin);
    var m = window.gvdesenrolaMergeTracking();
    Object.keys(m).forEach(function (k) {
      if (!u.searchParams.has(k)) u.searchParams.set(k, m[k]);
    });
    return u.pathname + u.search + u.hash;
  };
})();