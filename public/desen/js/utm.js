/**
 * xTracky UTM Preserver - Adiciona parâmetros da URL atual à URL de destino
 * @param {string} url - URL de destino para navegação
 * @returns {string} URL com parâmetros UTM anexados
 */
function getUrlWithUtm(url) {
  // Verificação de ambiente browser (pula no SSR)
  if (typeof window === 'undefined') return url;

  var params = window.location.search;
  if (!params) return url;

  var separator = url.indexOf('?') !== -1 ? '&' : '?';
  return url + separator + params.substring(1);
}
