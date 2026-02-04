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

  // Inicializar tema escuro do dashboard
  initializeDashboardTheme();
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

/**
 * Inicializar tema escuro do dashboard
 */
function initializeDashboardTheme() {
  const storageKey = "gridnexobot-theme";
  const body = document.body;
  const toggleButton = document.getElementById("themeToggle");

  if (!toggleButton) {
    return;
  }

  function setTheme(theme) {
    const isDark = theme === "dark";
    if (isDark) {
      body.classList.add("theme-dark");
      toggleButton.innerHTML =
        '<i class="bi bi-sun"></i> <span class="d-none d-sm-inline">Tema claro</span>';
    } else {
      body.classList.remove("theme-dark");
      toggleButton.innerHTML =
        '<i class="bi bi-moon-stars"></i> <span class="d-none d-sm-inline">Tema escuro</span>';
    }
  }

  // Recuperar tema salvo ou usar preferência do sistema
  const saved = localStorage.getItem(storageKey);
  const prefersDark =
    window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
  const initialTheme = saved ? saved : prefersDark ? "dark" : "light";
  setTheme(initialTheme);

  // Evento de clique para alternar tema
  toggleButton.addEventListener("click", function () {
    const isDark = body.classList.contains("theme-dark");
    const nextTheme = isDark ? "light" : "dark";
    localStorage.setItem(storageKey, nextTheme);
    setTheme(nextTheme);
  });
}
