/**
 * Nexo - Site
 * JavaScript Principal
 */

// Inicialização ao carregar o DOM
document.addEventListener("DOMContentLoaded", function () {
  // Smooth scroll para âncoras
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href !== "#" && document.querySelector(href)) {
        e.preventDefault();
        document.querySelector(href).scrollIntoView({
          behavior: "smooth",
        });
      }
    });
  });
});

// Helpers globais
window.nexoHelpers = {
  // Formatar moeda
  formatCurrency: function (value) {
    return new Intl.NumberFormat("pt-BR", {
      style: "currency",
      currency: "BRL",
    }).format(value);
  },

  // Formatar data
  formatDate: function (date) {
    return new Intl.DateTimeFormat("pt-BR").format(new Date(date));
  },
};
