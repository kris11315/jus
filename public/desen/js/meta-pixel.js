/**
 * meta-pixel.js
 * Pixel da Meta instalado direto na página, sem passar pelo pixel da Utmify.
 *
 * Por quê: o pixel.js da Utmify só dispara PageView/ViewContent/IC/Lead no
 * browser. O Purchase quem manda é o servidor deles via API de Conversões, e
 * em pedidos que entram por credencial de API ele chega sem fbc/fbp/IP
 * (qualidade 3.2/10 no Gerenciador de Eventos), então a Meta não atribui a
 * compra a campanha nenhuma. Aqui a gente controla os eventos.
 *
 * Deduplicação: cada evento leva eventID = id da transação Genesys (Purchase)
 * ou id + ':ic' (InitiateCheckout). O servidor (api/meta-capi.php) manda o
 * mesmo Purchase pela API de Conversões com o mesmo event_id, e a Meta funde
 * os dois. Quem paga no app do banco e não volta pra página é coberto pelo
 * servidor; quem volta gera os dois e conta uma vez.
 *
 * Expõe:
 *   window.metaPurchase({ orderId, valorReais | priceInCents, productName, customer })
 *   window.metaInitiateCheckout({ orderId, valorReais | priceInCents, productName })
 */
(function () {
  var META_PIXEL_ID = '1022079780841693';

  // ---- bootstrap padrão do fbevents.js ------------------------------------
  /* eslint-disable */
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
  document,'script','https://connect.facebook.net/en_US/fbevents.js');
  /* eslint-enable */

  // Sem fbevents ainda carregado o _fbc não existe; se o clique veio com
  // fbclid, grava o cookie no formato oficial pra API de Conversões ler.
  (function garantirFbc() {
    try {
      if (/(^|; )_fbc=/.test(document.cookie)) return;
      var fbclid = new URLSearchParams(window.location.search).get('fbclid');
      if (!fbclid) return;
      var valor = 'fb.1.' + Date.now() + '.' + fbclid;
      var dominio = window.location.hostname.split('.').slice(-2).join('.');
      document.cookie = '_fbc=' + valor + '; path=/; max-age=' + (90 * 86400) +
        '; domain=.' + dominio + '; SameSite=Lax';
    } catch (e) {}
  })();

  fbq('init', META_PIXEL_ID, dadosAvancados());
  fbq('track', 'PageView');

  // ---- helpers -------------------------------------------------------------
  function soDigitos(v) { return String(v || '').replace(/\D/g, ''); }

  // Advanced matching: só o que a página realmente sabe do lead. CPF vira
  // external_id (o fbevents faz o hash). E-mail e telefone gerados pelo
  // servidor não entram, porque não são do comprador de verdade.
  function dadosAvancados(cliente) {
    var out = {};
    try {
      var p = new URLSearchParams(window.location.search);
      var cpf = soDigitos((cliente && cliente.document) || p.get('cpf') || localStorage.getItem('site.cpf') || localStorage.getItem('userCPF'));
      var nome = String((cliente && cliente.name) || p.get('nome') || localStorage.getItem('site.nome') || localStorage.getItem('userName') || '').trim();
      if (cpf.length === 11) out.external_id = cpf;
      if (nome) {
        var partes = nome.toLowerCase().split(/\s+/);
        out.fn = partes[0];
        if (partes.length > 1) out.ln = partes[partes.length - 1];
      }
      // E-mail fica de fora de propósito: o funil não pede e-mail, e o que o
      // servidor devolve (nome.sobrenome@hotmail.com) é inventado pro gateway.
      var tel = soDigitos(cliente && cliente.phone);
      if (tel.length >= 10 && tel !== '11988887777' && tel !== '11999999999') out.ph = '55' + tel;
    } catch (e) {}
    return out;
  }

  function valorReais(dados) {
    if (dados.priceInCents !== undefined && dados.priceInCents !== null) {
      return Math.round(Number(dados.priceInCents)) / 100;
    }
    var v = dados.valorReais;
    if (typeof v === 'string') v = v.replace(/[^\d,.-]/g, '').replace(',', '.');
    return Math.round(Number(v) * 100) / 100 || 0;
  }

  function slug(texto) {
    return String(texto || 'produto')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'produto';
  }

  function jaEnviado(chave) {
    try { return localStorage.getItem(chave) === '1'; } catch (e) { return false; }
  }
  function marcarEnviado(chave) {
    try { localStorage.setItem(chave, '1'); } catch (e) {}
  }

  function disparar(evento, dados, sufixoId) {
    if (!dados || !dados.orderId) {
      console.warn('[Meta] ' + evento + ' ignorado: orderId ausente.', dados);
      return;
    }
    var eventID = String(dados.orderId) + (sufixoId || '');
    var chave = 'meta_sent:' + evento + ':' + eventID;
    if (jaEnviado(chave)) {
      console.log('[Meta] ' + evento + ' já enviado para ' + eventID + ', ignorando.');
      return;
    }
    marcarEnviado(chave);

    var valor = valorReais(dados);
    var nome = dados.productName || 'Produto';
    var params = {
      value: valor,
      currency: 'BRL',
      content_name: nome,
      content_ids: [slug(nome)],
      content_type: 'product',
      num_items: 1
    };
    if (evento === 'Purchase') params.order_id = String(dados.orderId);

    fbq('track', evento, params, { eventID: eventID });
    console.log('[Meta] ' + evento + ' enviado', { eventID: eventID, value: valor, content_name: nome });
  }

  window.metaPurchase = function (dados) { disparar('Purchase', dados, ''); };
  window.metaInitiateCheckout = function (dados) { disparar('InitiateCheckout', dados, ':ic'); };
})();
