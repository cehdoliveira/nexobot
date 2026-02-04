/**
 * Dashboard Controller - Alpine.js
 * Gerencia funcionalidades do dashboard
 */

document.addEventListener("alpine:init", () => {
  Alpine.data("gridDashboardController", () => ({
    // Estado
    isLoading: false,
    isClosingPositions: false,
    autoRefresh: false,
    refreshInterval: null,
    refreshTime: 60, // segundos

    /**
     * Atualizar dados do dashboard e limpar cache
     */
    async refreshData() {
      this.isLoading = true;

      try {
        // Limpar cache no servidor
        const response = await fetch(window.location.pathname, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'clearCache'
          })
        });

        const data = await response.json();

        if (data.success) {
          // Cache foi limpo com sucesso
          console.log('✅ Cache limpo:', data.message);
          
          // Aguardar um pouco e recarregar a página
          setTimeout(() => {
            window.location.reload();
          }, 500);
        } else {
          console.warn('⚠️ Erro ao limpar cache:', data.message);
          // Mesmo em caso de erro, recarregar a página
          setTimeout(() => {
            window.location.reload();
          }, 500);
        }
      } catch (error) {
        console.error('❌ Erro ao fazer requisição:', error);
        // Em caso de erro, apenas recarregar
        setTimeout(() => {
          window.location.reload();
        }, 500);
      }
    },

    /**
     * Encerrar todas as posições abertas
     */
    async closeAllPositions() {
      // Primeira confirmação
      const confirm1 = await Swal.fire({
        title: '⚠️ CUIDADO!',
        html: '<p style="font-size: 16px; line-height: 1.6;">Isso irá:<br><br>1. Cancelar <strong>TODAS</strong> as ordens abertas<br>2. Vender <strong>TODOS</strong> os pares de USDC<br><br>Deseja continuar?</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sim, continuar',
        cancelButtonText: 'Cancelar'
      });

      if (!confirm1.isConfirmed) {
        return;
      }

      // Segunda confirmação
      const confirm2 = await Swal.fire({
        title: '🛑 CONFIRMAÇÃO FINAL',
        html: '<p style="font-size: 16px; line-height: 1.8; color: #dc3545;"><strong>Esta ação é IRREVERSÍVEL!</strong><br><br>Você está prestes a encerrar <strong>TODAS as suas posições</strong> e <strong>vender todos os pares</strong> neste exato momento.<br><br>Esta é a sua última chance para cancelar.<br><br>Tem <strong>ABSOLUTA CERTEZA</strong>?</p>',
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sim, ENCERRAR TUDO',
        cancelButtonText: 'Não, cancelar',
        allowOutsideClick: false,
        allowEscapeKey: false
      });

      if (!confirm2.isConfirmed) {
        await Swal.fire({
          title: 'Operação cancelada',
          text: 'Nenhuma ação foi realizada.',
          icon: 'info',
          confirmButtonColor: '#0d6efd'
        });
        return;
      }

      this.isClosingPositions = true;

      // Mostrar loading
      Swal.fire({
        title: 'Encerrando posições...',
        html: '<p>Por favor aguarde enquanto todas as ordens são canceladas e posições vendidas.</p>',
        icon: 'info',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: async () => {
          Swal.showLoading();

          try {
            const response = await fetch(window.location.pathname, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
              },
              body: JSON.stringify({
                action: 'closeAllPositions'
              })
            });

            // Tentar fazer parse do JSON
            const data = await response.json();

            Swal.hideLoading();

            if (data.success) {
              await Swal.fire({
                title: '✅ Sucesso!',
                html: `<p style="font-size: 15px; line-height: 1.6;">${data.message}</p>
                       <div style="text-align: left; margin-top: 15px;">
                         <p><strong>Ordens canceladas:</strong> ${data.cancelled_orders ? data.cancelled_orders.length : 0}</p>
                         <p><strong>Posições vendidas:</strong> ${data.sold_positions ? data.sold_positions.length : 0}</p>
                       </div>`,
                icon: 'success',
                confirmButtonColor: '#198754'
              });

              // Recarregar após sucesso
              setTimeout(() => {
                window.location.reload();
              }, 1500);
            } else {
              await Swal.fire({
                title: '❌ Erro',
                text: data.message || 'Erro desconhecido ao encerrar posições',
                icon: 'error',
                confirmButtonColor: '#d33'
              });
            }
          } catch (error) {
            console.error('Erro ao encerrar posições:', error);
            Swal.hideLoading();

            await Swal.fire({
              title: '❌ Erro ao processar requisição',
              html: `<p style="word-break: break-word; font-size: 13px;">${error.message || 'Erro desconhecido'}</p>`,
              icon: 'error',
              confirmButtonColor: '#d33'
            });
          } finally {
            this.isClosingPositions = false;
          }
        }
      });
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
