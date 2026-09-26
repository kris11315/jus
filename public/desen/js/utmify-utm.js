/**
 * utmify-utm.js (v3 - Ultra Compreensivo)
 * Salva TODOS os parâmetros de rastreamento no localStorage e integra com Utmify.
 */
(function () {
  var UTM_KEY = 'utmify_params';
  
  // Lista base de parâmetros conhecidos para garantir prioridade, 
  // mas o script agora captura QUALQUER parâmetro utm_* ou IDs de clique.
  var baseParams = [
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
    'src', 'sck', 'gclid', 'fbclid', 'ttclid', 'msclkid', 
    'keyword', 'device', 'network', 'placement', 'campaign_id', 'adset_id', 'ad_id'
  ];
  
  function captureFromUrl() {
    var search = window.location.search;
    if ((search.match(/\?/g) || []).length > 1) {
      var parts = search.split('?');
      search = '?' + parts.slice(1).join('&');
    }
    
    var qs = new URLSearchParams(search);
    var stored = {};
    try { stored = JSON.parse(localStorage.getItem(UTM_KEY) || '{}'); } catch(e) {}

    var updated = false;
    
    // 1. Captura parâmetros da lista base
    baseParams.forEach(function(k) {
      if (qs.has(k) && qs.get(k)) {
        stored[k] = qs.get(k);
        updated = true;
      }
    });

    // 2. Captura dinamicamente qualquer outro parâmetro que comece com utm_
    for (var pair of qs.entries()) {
      var key = pair[0];
      var val = pair[1];
      if (key.indexOf('utm_') === 0 && val) {
        if (stored[key] !== val) {
          stored[key] = val;
          updated = true;
        }
      }
    }

    if (updated) {
      try { localStorage.setItem(UTM_KEY, JSON.stringify(stored)); } catch(e) {}
    }
    return stored;
  }

  function syncWithUtmify() {
    try {
      var stored = JSON.parse(localStorage.getItem(UTM_KEY) || '{}');
      var utmifyLeadRaw = localStorage.getItem('lead-google') || localStorage.getItem('lead') || localStorage.getItem('lead-tiktok');
      
      if (utmifyLeadRaw) {
        var utmifyLead = JSON.parse(utmifyLeadRaw);
        if (utmifyLead && utmifyLead._id) {
          stored['leadId'] = utmifyLead._id;
          
          if (stored['utm_source'] && stored['utm_source'].indexOf('jLj') === -1) {
            stored['utm_source'] = stored['utm_source'] + 'jLj' + utmifyLead._id;
          }
          
          localStorage.setItem(UTM_KEY, JSON.stringify(stored));
        }
      }
    } catch(e) {}
  }

  captureFromUrl();
  syncWithUtmify();
  setTimeout(syncWithUtmify, 1500);
  setTimeout(syncWithUtmify, 3000);
  setTimeout(syncWithUtmify, 6000);

  window.getUtmifyParams = function () {
    try { return JSON.parse(localStorage.getItem(UTM_KEY) || '{}'); } catch(e) { return {}; }
  };

  window.appendUtmsToFormData = function (formData) {
    var p = window.getUtmifyParams();
    for (var k in p) {
      if (p.hasOwnProperty(k) && p[k]) {
        if (formData.has(k)) formData.delete(k);
        formData.append(k, p[k]);
      }
    }
  };
})();
