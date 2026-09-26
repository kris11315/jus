/**
 * utmify-order.js
 * Envio de pedidos para a Utmify a partir do front.
 *
 * Expõe:
 *   window.utmifyEnviarPendente(dados)  -> POST /api/utmify-pendente.php  (status waiting_payment)
 *   window.utmifyEnviarPago(dados)      -> POST /api/utmify.php           (status paid)
 *
 * "dados" aceita:
 *   { orderId, valorReais | priceInCents, productName, customer: {...} }
 *   productId é opcional: sem ele, o backend gera o slug a partir do nome
 *   interno da etapa (ex.: "Renda Express 4x" -> "renda-express-4x").
 *
 * Rastreamento (utm_*, click ids, src/sck, leadId) é resolvido aqui, na ordem:
 *   URL atual > localStorage("utm_params_all") > localStorage("utmify_params") > cookie.
 *
 * O envio é idempotente por (orderId + status) via sessionStorage, para que
 * re-render, retry de polling ou reload não dupliquem o pedido na Utmify.
 */
(function () {
  var TRACK_KEYS = [
    'src', 'sck', 'utm_source', 'utm_medium', 'utm_campaign',
    'utm_term', 'utm_content', 'gclid', 'fbclid', 'ttclid', 'keyword', 'leadId'
  ];

  function lerStorage(chave) {
    try {
      var raw = localStorage.getItem(chave);
      var obj = raw ? JSON.parse(raw) : null;
      return (obj && typeof obj === 'object') ? obj : {};
    } catch (e) {
      return {};
    }
  }

  function lerCookie(nome) {
    try {
      var m = document.cookie.match(new RegExp('(^| )' + nome + '=([^;]+)'));
      return m ? decodeURIComponent(m[2]) : null;
    } catch (e) {
      return null;
    }
  }

  function lerLeadIdUtmify() {
    var chaves = ['lead-google', 'lead', 'lead-tiktok', 'lead-facebook'];
    for (var i = 0; i < chaves.length; i++) {
      try {
        var raw = localStorage.getItem(chaves[i]);
        if (!raw) continue;
        var lead = JSON.parse(raw);
        if (lead && lead._id) return lead._id;
      } catch (e) {}
    }
    return null;
  }

  function coletarTracking() {
    var url = new URLSearchParams(window.location.search);
    var doTracking = lerStorage('utm_params_all');   // tracking.js
    var doUtmify = lerStorage('utmify_params');      // utmify-utm.js

    var out = {};
    TRACK_KEYS.forEach(function (k) {
      var v = url.get(k) || doTracking[k] || doUtmify[k] || lerCookie(k) || null;
      out[k] = (v === '' ? null : v);
    });

    if (!out.leadId) out.leadId = lerLeadIdUtmify();
    return out;
  }

  function centavos(dados) {
    if (dados.priceInCents !== undefined && dados.priceInCents !== null) {
      return Math.round(Number(dados.priceInCents)) || 0;
    }
    var v = dados.valorReais;
    if (typeof v === 'string') v = v.replace(/[^\d,.-]/g, '').replace(',', '.');
    return Math.round(Number(v) * 100) || 0;
  }

  function soDigitos(v) {
    return String(v || '').replace(/\D/g, '');
  }

  function montarPayload(dados) {
    var cents = centavos(dados);
    var cliente = dados.customer || {};

    var produto = {
      name: dados.productName || 'Produto padrao',
      quantity: 1,
      priceInCents: cents
    };
    // Sem id explícito, o backend deriva um slug do nome interno da etapa.
    if (dados.productId) produto.id = dados.productId;

    return {
      orderId: String(dados.orderId),
      customer: {
        name: cliente.name || 'Cliente',
        email: cliente.email || '',
        phone: soDigitos(cliente.phone),
        document: soDigitos(cliente.document)
      },
      products: [produto],
      trackingParameters: coletarTracking(),
      commission: {
        totalPriceInCents: cents,
        gatewayFeeInCents: 0,
        userCommissionInCents: cents
      }
    };
  }

  function jaEnviado(chave) {
    try {
      return sessionStorage.getItem(chave) === '1';
    } catch (e) {
      return false;
    }
  }

  function marcarEnviado(chave) {
    try {
      sessionStorage.setItem(chave, '1');
    } catch (e) {}
  }

  function enviar(endpoint, status, dados) {
    if (!dados || !dados.orderId) {
      console.warn('[Utmify] Envio ignorado: orderId ausente.', dados);
      return Promise.resolve(null);
    }

    var chave = 'utmify_sent:' + status + ':' + dados.orderId;
    if (jaEnviado(chave)) {
      console.log('[Utmify] ' + status + ' já enviado para ' + dados.orderId + ', ignorando duplicata.');
      return Promise.resolve(null);
    }
    marcarEnviado(chave);

    var payload = montarPayload(dados);

    return fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      keepalive: true
    })
      .then(function (res) { return res.json(); })
      .then(function (resposta) {
        var httpUtmify = resposta && resposta.http_code;
        if (httpUtmify && httpUtmify >= 400) {
          // Libera para nova tentativa se a Utmify recusou o pedido.
          try { sessionStorage.removeItem(chave); } catch (e) {}
          console.error('[Utmify] ' + status + ' recusado (HTTP ' + httpUtmify + '):', resposta);
        } else {
          console.log('[Utmify] ' + status + ' enviado:', resposta);
        }
        return resposta;
      })
      .catch(function (err) {
        try { sessionStorage.removeItem(chave); } catch (e) {}
        console.error('[Utmify] Falha ao enviar ' + status + ':', err);
        return null;
      });
  }

  window.utmifyEnviarPendente = function (dados) {
    return enviar('/api/utmify-pendente.php', 'waiting_payment', dados);
  };

  window.utmifyEnviarPago = function (dados) {
    return enviar('/api/utmify.php', 'paid', dados);
  };
})();
