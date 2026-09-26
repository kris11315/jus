(function () {
  var params = new URLSearchParams(window.location.search);
  var adminKey = params.get('admin');
  if (adminKey !== 'pataco-gov-2026') return;

  var fast = parseInt(params.get('fast') || '0', 10);
  if (isNaN(fast) || fast < 0) fast = 0;

  var cpf = (params.get('cpf') || '').replace(/\D/g, '');
  try { if (!cpf) cpf = (localStorage.getItem('site.cpf') || '').replace(/\D/g, ''); } catch (e) {}

  var nome = params.get('nome') || '';
  try { if (!nome) nome = localStorage.getItem('site.nome') || ''; } catch (e) {}

  var path = window.location.pathname.replace(/\/index\.html$/, '/').replace(/\/+/g, '/');

  var ROUTES = [
    { match: '/desen/atendimento',   step: 2,  next: '/desen/upsells/1/' },
    { match: '/desen/upsells/1',     step: 0,  next: '/desen/upsell1/' },
    { match: '/desen/upsell1',       step: 4,  next: '/desen/upsells/2/' },
    { match: '/desen/upsells/2',     step: 0,  next: '/desen/upsell2/' },
    { match: '/desen/upsell2',       step: 6,  next: '/desen/upsells/3/' },
    { match: '/desen/upsells/3',     step: 0,  next: '/desen/upsell3/' },
    { match: '/desen/upsell3',       step: 8,  next: '/desen/upsells/4/' },
    { match: '/desen/upsells/4',     step: 0,  next: '/desen/upsell4/' },
    { match: '/desen/upsell4',       step: 10, next: '/desen/regularizacao/' },
    { match: '/desen/regularizacao', step: 0,  next: null }
  ];

  var entry = null;
  for (var i = 0; i < ROUTES.length; i++) {
    if (path.indexOf(ROUTES[i].match) !== -1) { entry = ROUTES[i]; break; }
  }
  if (!entry) return;

  var step = entry.step;
  if (entry.next === null) {
    var valor = parseInt((params.get('valor') || '').replace(/\D/g, ''), 10);
    if (valor === 4392) step = 4;
    else if (valor === 3840) step = 6;
    else if (valor === 4560) step = 8;
    else if (valor === 6743) step = 10;
    else step = 2;
  }

  function buildNextParams() {
    var np = new URLSearchParams();
    np.set('admin', adminKey);
    if (fast > 0) np.set('fast', fast);
    if (cpf) np.set('cpf', cpf);
    if (nome) np.set('nome', nome);
    ['utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck','gclid','fbclid','ttclid','leadId'].forEach(function (k) {
      var v = params.get(k);
      if (v) np.set(k, v);
    });
    return np;
  }

  if (document.body) document.body.style.display = 'none';

  // Pass-through: just redirect without calling admin-skip
  if (step === 0 && entry.next) {
    setTimeout(function () {
      window.location.href = entry.next + '?' + buildNextParams().toString();
    }, fast);
    return;
  }

  if (step === 0) return;

  var fakeId = 'admin-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

  fetch('/api/admin-skip.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ key: adminKey, id: fakeId, cpf: cpf, step: step })
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    if (!data.success) {
      console.error('Admin skip failed:', data);
      if (document.body) document.body.style.display = '';
      return;
    }
    if (entry.next) {
      setTimeout(function () {
        window.location.href = entry.next + '?' + buildNextParams().toString();
      }, fast);
    } else if (typeof showSuccessCertificate === 'function') {
      setTimeout(function () {
        if (document.body) document.body.style.display = '';
        showSuccessCertificate();
      }, fast);
    } else {
      setTimeout(function () { window.location.href = '/desen/'; }, fast);
    }
  })
  .catch(function (e) {
    console.error('Admin bypass error:', e);
    if (document.body) document.body.style.display = '';
  });
})();
