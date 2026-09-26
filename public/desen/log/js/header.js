/**
 * Menu (placeholder) e modo escuro / alto contraste no header.
 *
 * Antes: front/js/header.js (uma única cópia).
 * Agora: assets/js/header.js — carregado por todas as páginas que tenham o header com
 * #hdrMenu e/ou #hdrContrast.
 */
(function () {
  function init() {
    var contrast = document.getElementById("hdrContrast");
    if (contrast) {
      contrast.addEventListener("click", function () {
        document.documentElement.classList.toggle("theme-dark");
      });
    }
    var menu = document.getElementById("hdrMenu");
    if (menu) {
      menu.addEventListener("click", function () {
        /* Placeholder: menu gov.br — pode ligar a offcanvas depois */
        menu.setAttribute("aria-expanded", menu.getAttribute("aria-expanded") === "true" ? "false" : "true");
      });
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();