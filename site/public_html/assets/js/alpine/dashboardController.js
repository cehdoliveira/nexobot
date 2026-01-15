/**
 * Dashboard Controller - Alpine.js
 * Gerencia funcionalidades do dashboard
 */

document.addEventListener("alpine:init", () => {
  Alpine.data("dashboardController", () => ({
    // Estado
    isLoading: false,
    autoRefresh: false,
    refreshInterval: null,
    refreshTime: 60, // segundos

    /**
     * Atualizar dados do dashboard
     */
    refreshData() {
      this.isLoading = true;

      // Recarregar a página para buscar novos dados
      window.location.reload();
    },

    /**
     * Ativar/Desativar atualização automática
     */
    toggleAutoRefresh() {
      this.autoRefresh = !this.autoRefresh;

      if (this.autoRefresh) {
        // Iniciar intervalo de atualização
        this.refreshInterval = setInterval(() => {
          this.refreshData();
        }, this.refreshTime * 1000);

        // Notificar usuário
        this.showNotification("Auto-atualização ativada", "success");
      } else {
        // Parar intervalo
        if (this.refreshInterval) {
          clearInterval(this.refreshInterval);
          this.refreshInterval = null;
        }

        this.showNotification("Auto-atualização desativada", "info");
      }
    },

    /**
     * Formatar números para exibição
     */
    formatNumber(num, decimals = 2) {
      return new Intl.NumberFormat("pt-BR", {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      }).format(num);
    },

    /**
     * Formatar moeda
     */
    formatCurrency(value) {
      return new Intl.NumberFormat("pt-BR", {
        style: "currency",
        currency: "USD",
      }).format(value);
    },

    /**
     * Mostrar notificação
     */
    showNotification(message, type = "info") {
      if (typeof Swal !== "undefined") {
        const icons = {
          success: "success",
          error: "error",
          info: "info",
          warning: "warning",
        };

        Swal.fire({
          icon: icons[type] || "info",
          title: message,
          toast: true,
          position: "top-end",
          showConfirmButton: false,
          timer: 3000,
          timerProgressBar: true,
        });
      }
    },

    /**
     * Copiar texto para clipboard
     */
    copyToClipboard(text) {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
          this.showNotification(
            "Copiado para área de transferência!",
            "success"
          );
        });
      }
    },

    /**
     * Destruir ao desmontar
     */
    destroy() {
      if (this.refreshInterval) {
        clearInterval(this.refreshInterval);
      }
    },

    /**
     * Inicialização
     */
    init() {
      // Limpar intervalo ao sair da página
      window.addEventListener("beforeunload", () => {
        this.destroy();
      });

      // Verificar se há mensagens de sucesso/erro na sessão
      this.$nextTick(() => {
        // Implementar verificação de mensagens flash se necessário
      });
    },
  }));
});
