/**
 * Driftex - UI Core
 */

document.addEventListener("DOMContentLoaded", function () {
  injectFloatingThemeToggle();
  initializeTheme();
  initializeSmoothScroll();
});

window.nexoHelpers = {
  formatCurrency(value) {
    return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(value);
  },
  formatDate(date) {
    return new Intl.DateTimeFormat("pt-BR").format(new Date(date));
  },
};

function initializeSmoothScroll() {
  document.querySelectorAll("a[href^=\"#\"]").forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href !== "#" && document.querySelector(href)) {
        e.preventDefault();
        document.querySelector(href).scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });
}

function initializeTheme() {
  const storageKey = "driftex-theme";
  const body = document.body;
  const root = document.documentElement;

  const applyTheme = (theme) => {
    const isDark = theme === "dark";
    body.classList.toggle("theme-dark", isDark);
    root.setAttribute("data-theme", isDark ? "dark" : "light");

    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
      button.innerHTML = isDark
        ? "<i class=\"bi bi-sun\"></i><span class=\"d-none d-md-inline\">Claro</span>"
        : "<i class=\"bi bi-moon-stars\"></i><span class=\"d-none d-md-inline\">Escuro</span>";
      button.setAttribute("aria-label", isDark ? "Ativar tema claro" : "Ativar tema escuro");
    });
  };

  const saved = localStorage.getItem(storageKey) || "dark";
  localStorage.setItem(storageKey, saved);
  applyTheme(saved);

  document.addEventListener("click", function (event) {
    const button = event.target.closest("[data-theme-toggle]");
    if (!button) {
      return;
    }

    const nextTheme = body.classList.contains("theme-dark") ? "light" : "dark";
    localStorage.setItem(storageKey, nextTheme);
    applyTheme(nextTheme);
  });
}

function injectFloatingThemeToggle() {
  if (document.querySelector("[data-theme-toggle]")) {
    return;
  }
  const floatingButton = document.createElement("button");
  floatingButton.type = "button";
  floatingButton.className = "btn btn-sm nexo-theme-toggle nexo-theme-toggle-floating";
  floatingButton.setAttribute("data-theme-toggle", "true");
  floatingButton.setAttribute("title", "Alternar tema");
  floatingButton.setAttribute("aria-label", "Ativar tema claro");
  floatingButton.innerHTML = '<i class="bi bi-sun"></i><span class="d-none d-md-inline">Claro</span>';
  document.body.appendChild(floatingButton);
}
