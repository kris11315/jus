/**
 * utmify-tracking.js
 * Carrega o script de UTMs da Utmify em todas as etapas do funil.
 *
 * É o equivalente legível do snippet ofuscado do painel da Utmify.
 * Decodificado, ele faz exatamente isto: cria a tag <script async defer> com
 * os atributos e injeta no <head>. Deixar em claro evita colar um bloco
 * ilegível em dez páginas e permite auditar o que está sendo carregado.
 *
 *   UTMs -> https://cdn.utmify.com.br/scripts/utms/latest.js
 *           atributos: data-utmify-prevent-xcod-sck, data-utmify-prevent-subids
 *
 * O pixel da Utmify (pixel.js) foi REMOVIDO daqui de propósito. O Purchase que
 * o servidor deles manda pra Meta em pedidos via API chegava com qualidade
 * 3.2/10 e sem atribuição de campanha. Os eventos da Meta agora saem de
 * desen/js/meta-pixel.js (browser) e api/meta-capi.php (servidor). Se o
 * pixel.js voltar aqui, o Purchase é contado duas vezes.
 */
(function () {
  var SCRIPTS = [
    {
      url: 'https://cdn.utmify.com.br/scripts/utms/latest.js',
      globals: {},
      attributes: {
        'data-utmify-prevent-xcod-sck': '',
        'data-utmify-prevent-subids': ''
      }
    }
  ];

  var destino = document.head || document.documentElement;

  SCRIPTS.forEach(function (item) {
    // Evita carregar duas vezes se a página incluir este arquivo mais de uma vez.
    if (document.querySelector('script[src="' + item.url + '"]')) return;

    for (var nome in item.globals) {
      if (item.globals.hasOwnProperty(nome)) window[nome] = item.globals[nome];
    }

    var tag = document.createElement('script');
    tag.src = item.url;
    tag.async = true;
    tag.defer = true;

    for (var attr in item.attributes) {
      if (item.attributes.hasOwnProperty(attr)) tag.setAttribute(attr, item.attributes[attr]);
    }

    destino.appendChild(tag);
  });
})();
