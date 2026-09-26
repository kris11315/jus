(function(){
  try {
    function uuid(){return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g,function(c){var r=Math.random()*16|0,v=c==='x'?r:(r&0x3|0x8);return v.toString(16);});}
    function getVisitor(){ try { var v = localStorage.getItem('_lv_vid'); if (!v){ v=uuid(); localStorage.setItem('_lv_vid',v);} return v; } catch(e){ return uuid(); } }
    function getSession(){ try { var s = sessionStorage.getItem('_lv_sid'); if (!s){ s=uuid(); sessionStorage.setItem('_lv_sid',s);} return s; } catch(e){ return uuid(); } }

    var visitorId = getVisitor();
    var sessionId = getSession();
    var startTime = Date.now();

    function send(){}

    window.lvTrack = function(stage, data){};
    window.lvIdentify = function(data){};

    function autoIdentify(){
      var cpfEl = document.querySelector('input[name*="cpf" i], input[id*="cpf" i], input[placeholder*="CPF" i]');
      var nameEl = document.querySelector('input[name*="nome" i], input[name*="name" i], input[id*="nome" i], input[placeholder*="nome" i]');
      var cpf = cpfEl && cpfEl.value ? cpfEl.value : null;
      var nm = nameEl && nameEl.value ? nameEl.value : null;
      if (cpf) { try { localStorage.setItem('site.cpf', cpf); } catch(e){} }
      if (nm)  { try { localStorage.setItem('site.nome', nm);  } catch(e){} }
    }
    document.addEventListener('change', function(e){
      var t = e.target;
      if (!t || !t.matches) return;
      if (t.matches('input[name*="cpf" i], input[id*="cpf" i], input[placeholder*="CPF" i], input[name*="nome" i], input[name*="name" i], input[id*="nome" i], input[placeholder*="nome" i]')) {
        autoIdentify();
      }
    }, true);

    document.addEventListener('submit', function(e){ autoIdentify(); }, true);
  } catch(e){ /* silent */ }
})();
