/**
 * catalogo.js
 * Lê nome e preço da etapa em /api/catalogo.php, que serve a MESMA tabela usada
 * para cobrar (api/produtos.php). Assim a página nunca exibe um valor diferente
 * do que o gateway vai cobrar.
 *
 * O valor escrito no HTML continua servindo de fallback: se a consulta falhar,
 * a página mostra o que já estava lá em vez de ficar vazia.
 *
 *   window.catalogoEtapa('up1')                    -> Promise<{nome, valor, centavos}|null>
 *   window.catalogoTexto('up1', '.valor-badge', 'Valor da Taxa: R$ {valor}')
 */
(function () {
  var cache = {};

  window.catalogoEtapa = function (codigo) {
    if (cache[codigo]) return cache[codigo];

    cache[codigo] = fetch('/api/catalogo.php?etapa=' + encodeURIComponent(codigo), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.success) {
          console.warn('[Catalogo] etapa "' + codigo + '" nao encontrada.', d);
          return null;
        }
        return d;
      })
      .catch(function (e) {
        console.warn('[Catalogo] falha ao consultar "' + codigo + '":', e);
        return null;
      });

    return cache[codigo];
  };

  window.catalogoTexto = function (codigo, seletor, molde) {
    return window.catalogoEtapa(codigo).then(function (item) {
      if (!item) return null;
      var alvos = document.querySelectorAll(seletor);
      for (var i = 0; i < alvos.length; i++) {
        alvos[i].textContent = molde
          .replace('{valor}', item.valor)
          .replace('{nome}', item.nome);
      }
      return item;
    });
  };
})();
