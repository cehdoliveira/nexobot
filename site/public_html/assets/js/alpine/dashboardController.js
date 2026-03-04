/**
 * Grid Dashboard Controller - Enhanced (Alpine.js)
 * Real-time data updates, responsive interactions, bot controls
 */
document.addEventListener('alpine:init', () => {
  Alpine.data('gridDashboardController', () => ({
    // === State ===
    isLoading: false,
    isRefreshing: false,
    isConnected: true,
    connectionRetries: 0,
    lastUpdate: null,
    lastUpdateFormatted: '--',
    autoRefresh: false,
    refreshInterval: null,
    priceInterval: null,
    refreshSeconds: 30,
    currentPrice: 0,
    previousPrice: 0,
    priceDirection: '',

    // Active tab
    activeTab: 'overview',

    // Orders state
    ordersSortField: 'grid_level',
    ordersSortDir: 'asc',
    ordersFilter: 'all',

    // Logs state
    logsExpanded: false,

    // Performance accordion (mobile)
    perfExpanded: true,

    // Control actions loading
    actionLoading: null,

    // Grid data (updated via AJAX)
    gridData: null,

    // Desktop viewport detection
    isDesktop: window.innerWidth >= 1025,
    isTablet: window.innerWidth >= 768 && window.innerWidth < 1025,
    isMobile: window.innerWidth < 768,

    // === Initialization ===
    init() {
      // Parse existing server-side data
      this.parseInitialData();

      // Start price ticker
      this.startPriceTicker();

      // Start auto-refresh
      this.startAutoRefresh();

      // Viewport detection
      this.handleResize = this.debounce(() => {
        this.isDesktop = window.innerWidth >= 1025;
        this.isTablet = window.innerWidth >= 768 && window.innerWidth < 1025;
        this.isMobile = window.innerWidth < 768;
      }, 200);
      window.addEventListener('resize', this.handleResize);

      // Cleanup on unload
      window.addEventListener('beforeunload', () => this.destroy());

      // Update relative time every minute
      this.timeInterval = setInterval(() => this.updateRelativeTime(), 60000);

      // Mark as connected
      this.updateConnectionStatus(true);
    },

    // === Data Management ===
    parseInitialData() {
      const el = document.getElementById('dashboardData');
      if (el) {
        try {
          this.gridData = JSON.parse(el.textContent);
          this.currentPrice = parseFloat(this.gridData?.currentPrice) || 0;
          this.previousPrice = this.currentPrice;
          this.lastUpdate = new Date();
          this.updateRelativeTime();
        } catch (e) {
          console.warn('Failed to parse dashboard data:', e);
        }
      }
    },

    async fetchDashboardData() {
      try {
        const response = await fetch(window.location.pathname + '?_t=' + Date.now(), {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache'
          },
          body: JSON.stringify({ action: 'getGridDashboardData' })
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        if (data.success) {
          this.gridData = data.data;
          this.previousPrice = this.currentPrice;
          this.currentPrice = parseFloat(data.data?.currentPrice) || this.currentPrice;

          if (this.currentPrice > this.previousPrice) {
            this.priceDirection = 'up';
          } else if (this.currentPrice < this.previousPrice) {
            this.priceDirection = 'down';
          } else {
            this.priceDirection = '';
          }
          setTimeout(() => { this.priceDirection = ''; }, 600);

          this.lastUpdate = new Date();
          this.updateRelativeTime();
          this.updateConnectionStatus(true);
          return true;
        }
        return false;
      } catch (error) {
        console.error('Fetch error:', error);
        this.updateConnectionStatus(false);
        return false;
      }
    },

    // === Refresh ===
    async refreshData() {
      if (this.isRefreshing) return;
      this.isRefreshing = true;

      try {
        // Clear cache first
        await fetch(window.location.pathname + '?_t=' + Date.now(), {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache'
          },
          body: JSON.stringify({ action: 'clearCache' })
        });

        // Fetch fresh data via AJAX
        const success = await this.fetchDashboardData();

        if (success) {
          this.showToast('Dados atualizados', 'success');
        } else {
          // Fallback: reload page if AJAX fetch failed
          window.location.reload();
          return;
        }
      } catch (e) {
        console.error('Refresh error:', e);
        this.showToast('Erro ao atualizar', 'error');
      } finally {
        this.isRefreshing = false;
      }
    },

    startAutoRefresh() {
      this.autoRefresh = true;
      if (this.refreshInterval) clearInterval(this.refreshInterval);
      this.refreshInterval = setInterval(() => {
        this.fetchDashboardData();
      }, this.refreshSeconds * 1000);
    },

    stopAutoRefresh() {
      this.autoRefresh = false;
      if (this.refreshInterval) {
        clearInterval(this.refreshInterval);
        this.refreshInterval = null;
      }
    },

    toggleAutoRefresh() {
      if (this.autoRefresh) {
        this.stopAutoRefresh();
        this.showToast('Auto-atualização desativada', 'info');
      } else {
        this.startAutoRefresh();
        this.showToast('Auto-atualização ativada (30s)', 'success');
      }
    },

    // === Price Ticker ===
    startPriceTicker() {
      this.priceInterval = setInterval(async () => {
        try {
          const response = await fetch(window.location.pathname + '?_t=' + Date.now(), {
            method: 'POST',
            credentials: 'include',
            headers: {
              'Content-Type': 'application/json',
              'Cache-Control': 'no-cache, no-store, must-revalidate',
              'Pragma': 'no-cache'
            },
            body: JSON.stringify({ action: 'getCurrentPrice' })
          });
          if (!response.ok) return;
          const data = await response.json();
          if (data.success && data.price) {
            this.previousPrice = this.currentPrice;
            this.currentPrice = parseFloat(data.price);
            if (this.currentPrice > this.previousPrice) {
              this.priceDirection = 'up';
            } else if (this.currentPrice < this.previousPrice) {
              this.priceDirection = 'down';
            }
            setTimeout(() => { this.priceDirection = ''; }, 600);
            this.updateConnectionStatus(true);
          }
        } catch (e) {
          // Silent
        }
      }, 15000);
    },

    // === Bot Control Actions ===
    async executeAction(action, confirmTitle, confirmText) {
      const confirm = await Swal.fire({
        title: confirmTitle,
        html: confirmText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: action === 'emergencyShutdown' ? '#dc2626' : '#3b82f6',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sim, confirmar',
        cancelButtonText: 'Cancelar'
      });

      if (!confirm.isConfirmed) return;

      if (['emergencyShutdown', 'closeAllPositions', 'resetGrid'].includes(action)) {
        const confirm2 = await Swal.fire({
          title: 'Confirmação Final',
          text: 'Esta ação é irreversível. Tem certeza absoluta?',
          icon: 'error',
          showCancelButton: true,
          confirmButtonColor: '#dc2626',
          confirmButtonText: 'SIM, EXECUTAR',
          cancelButtonText: 'Não, cancelar'
        });
        if (!confirm2.isConfirmed) return;
      }

      this.actionLoading = action;

      try {
        const response = await fetch(window.location.pathname + '?_t=' + Date.now(), {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache'
          },
          body: JSON.stringify({ action })
        });

        // Verificar se resposta HTTP foi bem-sucedida
        if (!response.ok) {
          const errorText = await response.text();
          console.error(`❌ HTTP ${response.status}:`, errorText);
          throw new Error(`Erro HTTP ${response.status}: ${response.statusText}`);
        }

        // Tentar parsear JSON
        let data;
        try {
          data = await response.json();
        } catch (parseError) {
          const responseText = await response.text();
          console.error('❌ Erro ao parsear JSON:', responseText);
          throw new Error('Resposta inválida do servidor');
        }

        console.log('✅ Resposta recebida:', data);

        if (data.success) {
          this.showToast(data.message || 'Ação executada com sucesso', 'success');
          setTimeout(() => window.location.reload(), 1500);
        } else {
          this.showToast(data.message || 'Erro ao executar ação', 'error');
        }
      } catch (error) {
        console.error('❌ Erro na ação:', error);
        this.showToast(error.message || 'Erro de comunicação com o servidor', 'error');
      } finally {
        this.actionLoading = null;
      }
    },

    stopBot() {
      this.executeAction(
        'stopBot',
        'Parar Bot',
        'O bot será parado. As ordens existentes serão mantidas na Binance.'
      );
    },

    emergencyShutdown() {
      this.executeAction(
        'emergencyShutdown',
        '⚠️ DESLIGAMENTO DE EMERGÊNCIA',
        '<p class="text-danger fw-bold">Isso irá:</p><ul class="text-start"><li>Cancelar TODAS as ordens abertas</li><li>Parar o bot imediatamente</li><li>Marcar grid como cancelado</li></ul>'
      );
    },

    cancelAllOrders() {
      this.executeAction(
        'closeAllPositions',
        'Encerrar Todas as Posições',
        'Cancelar todas as ordens abertas e vender ativos. Esta ação é irreversível.'
      );
    },

    resetGrid() {
      this.executeAction(
        'resetGrid',
        'Resetar Grid',
        'O grid atual será encerrado e todas as ordens canceladas.'
      );
    },

    restartGrid() {
      this.executeAction(
        'restartGrid',
        '🔄 Religar Bot',
        '<p>Isso irá:</p><ul class="text-start"><li>Desativar o grid parado/cancelado</li><li>Permitir que um novo grid seja criado</li><li>O novo grid será criado automaticamente na próxima execução (em até 1 minuto)</li></ul>'
      );
    },

    // === Connection Status ===
    updateConnectionStatus(connected) {
      this.isConnected = connected;
      if (connected) {
        this.connectionRetries = 0;
      } else {
        this.connectionRetries++;
      }
    },

    // === Time Helpers ===
    updateRelativeTime() {
      if (!this.lastUpdate) {
        this.lastUpdateFormatted = '--';
        return;
      }
      const diffMs = Date.now() - this.lastUpdate.getTime();
      const diffSec = Math.floor(diffMs / 1000);
      if (diffSec < 10) {
        this.lastUpdateFormatted = 'agora';
      } else if (diffSec < 60) {
        this.lastUpdateFormatted = `${diffSec}s atrás`;
      } else {
        const diffMin = Math.floor(diffSec / 60);
        this.lastUpdateFormatted = `${diffMin}min atrás`;
      }
    },

    // === Format Helpers ===
    formatUSD(value) {
      const num = parseFloat(value) || 0;
      return '$' + new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(num);
    },

    formatBTC(value) {
      const num = parseFloat(value) || 0;
      return num.toFixed(8);
    },

    formatPercent(value) {
      const num = parseFloat(value) || 0;
      return (num >= 0 ? '+' : '') + num.toFixed(2) + '%';
    },

    formatPrice(value) {
      const num = parseFloat(value) || 0;
      return '$' + new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(num);
    },

    formatDate(dateStr) {
      if (!dateStr) return 'N/A';
      const d = new Date(dateStr);
      return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    },

    formatTimeAgo(dateStr) {
      if (!dateStr) return 'N/A';
      const d = new Date(dateStr);
      const diffMs = Date.now() - d.getTime();
      const diffMin = Math.floor(diffMs / 60000);
      if (diffMin < 1) return 'agora';
      if (diffMin < 60) return `${diffMin}min`;
      const diffHours = Math.floor(diffMin / 60);
      if (diffHours < 24) return `${diffHours}h`;
      const diffDays = Math.floor(diffHours / 24);
      return `${diffDays}d`;
    },

    // === Sorting ===
    sortOrders(field) {
      if (this.ordersSortField === field) {
        this.ordersSortDir = this.ordersSortDir === 'asc' ? 'desc' : 'asc';
      } else {
        this.ordersSortField = field;
        this.ordersSortDir = 'asc';
      }
    },

    getSortIcon(field) {
      if (this.ordersSortField !== field) return 'bi-chevron-expand';
      return this.ordersSortDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down';
    },

    // === Tab Management ===
    setTab(tab) {
      this.activeTab = tab;
    },

    // === UI Helpers ===
    showToast(message, type = 'info') {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: type === 'error' ? 'error' : type === 'success' ? 'success' : 'info',
          title: message,
          toast: true,
          position: this.isMobile ? 'top' : 'top-end',
          showConfirmButton: false,
          timer: 3000,
          timerProgressBar: true
        });
      }
    },

    getStatusBadgeClass(status) {
      switch (status) {
        case 'NEW': return 'badge-new';
        case 'FILLED': return 'badge-filled';
        case 'CANCELED': case 'CANCELLED': return 'badge-canceled';
        case 'PARTIALLY_FILLED': return 'badge-partial';
        default: return 'badge-canceled';
      }
    },

    getStatusLabel(status) {
      switch (status) {
        case 'NEW': return 'Aguardando';
        case 'FILLED': return 'Executada';
        case 'CANCELED': case 'CANCELLED': return 'Cancelada';
        case 'PARTIALLY_FILLED': return 'Parcial';
        default: return status || 'N/A';
      }
    },

    getLogDotClass(logType) {
      switch (logType) {
        case 'error': return 'dot-danger';
        case 'success': return 'dot-success';
        case 'warning': return 'dot-warning';
        default: return 'dot-info';
      }
    },

    getDrawdownLevel(percent) {
      if (percent < 10) return 'safe';
      if (percent < 15) return 'caution';
      return 'critical';
    },

    // === Utility ===
    debounce(fn, delay) {
      let timer;
      return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
      };
    },

    // === Cleanup ===
    destroy() {
      if (this.refreshInterval) clearInterval(this.refreshInterval);
      if (this.priceInterval) clearInterval(this.priceInterval);
      if (this.timeInterval) clearInterval(this.timeInterval);
      if (this.handleResize) {
        window.removeEventListener('resize', this.handleResize);
      }
    }
  }));
});
