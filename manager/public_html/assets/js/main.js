/**
 * Nexo Manager - Painel Administrativo
 * JavaScript Principal
 */

// Inicialização ao carregar o DOM
document.addEventListener("DOMContentLoaded", function () {
  console.log("Nexo Manager - Carregado");

  // Auto-fechar sidebar em mobile após clicar em link
  const sidebarLinks = document.querySelectorAll("#sidebarMenu .nav-link");
  const sidebarCollapse = document.querySelector("#sidebarMenu");

  sidebarLinks.forEach((link) => {
    link.addEventListener("click", function () {
      if (window.innerWidth < 768) {
        const bsCollapse = bootstrap.Collapse.getInstance(sidebarCollapse);
        if (bsCollapse) {
          bsCollapse.hide();
        }
      }
    });
  });

  // Marcar link ativo baseado na URL
  const currentPath = window.location.pathname;
  sidebarLinks.forEach((link) => {
    if (link.getAttribute("href") === currentPath) {
      link.classList.add("active");
    }
  });
});

// Helpers globais
window.managerHelpers = {
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

  // Formatar números
  formatNumber: function (value) {
    return new Intl.NumberFormat("pt-BR").format(value);
  },

  // Copiar para clipboard
  copyToClipboard: function (text) {
    navigator.clipboard.writeText(text).then(() => {
      Swal.fire({
        icon: "success",
        title: "Copiado!",
        text: "Texto copiado para a área de transferência",
        timer: 1500,
        showConfirmButton: false,
      });
    });
  },
};

// SweetAlert2 configuração padrão
const Toast = Swal.mixin({
  toast: true,
  position: "top-end",
  showConfirmButton: false,
  timer: 3000,
  timerProgressBar: true,
  didOpen: (toast) => {
    toast.addEventListener("mouseenter", Swal.stopTimer);
    toast.addEventListener("mouseleave", Swal.resumeTimer);
  },
});

window.Toast = Toast;
