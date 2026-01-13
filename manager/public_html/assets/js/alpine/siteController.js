/**
 * Controllers Bundle - Alpine.js
 * Unifica os controllers do manager em um único arquivo para simplificar carregamento.
 */

document.addEventListener("alpine:init", () => {
  // Stats Controller
  Alpine.data("statsController", () => ({
    stats: {
      users: 1234,
      content: 567,
      visits: 45678,
      revenue: 12345.67,
    },

    init() {
      this.loadStats();
    },

    async loadStats() {
      // fetch real stats if needed
    },

    formatCurrency(value) {
      return (
        "R$ " +
        value.toLocaleString("pt-BR", {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        })
      );
    },

    formatNumber(value) {
      return value.toLocaleString("pt-BR");
    },
  }));

  // Actions Controller
  Alpine.data("actionsController", () => ({
    selectedAction: "",

    selectAction(action) {
      this.selectedAction = action;
      setTimeout(() => {
        this.selectedAction = "";
      }, 3000);
    },

    async createUser() {
      const { value: formValues } = await Swal.fire({
        title: "Novo Usuário",
        html:
          '<input id="swal-input1" class="swal2-input" placeholder="Nome">' +
          '<input id="swal-input2" class="swal2-input" placeholder="Email">',
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: "Criar",
        cancelButtonText: "Cancelar",
        preConfirm: () => {
          return [
            document.getElementById("swal-input1").value,
            document.getElementById("swal-input2").value,
          ];
        },
      });

      if (formValues) {
        Toast.fire({
          icon: "success",
          title: "Usuário criado com sucesso!",
        });
      }
    },
  }));

  // Users Controller
  Alpine.data("usersController", () => ({
    users: [],
    selectedUser: null,
    search: "",
    loading: false,
    currentPage: 1,
    itemsPerPage: 5,
    itemsPerPageOptions: [5, 20, 50, 100],

    async init() {
      await this.loadUsers();
      console.log(
        "Users Controller inicializado com",
        this.users.length,
        "usuários"
      );
    },

    async loadUsers() {
      this.loading = true;
      try {
        const response = await fetch("/users/list");

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const contentType = response.headers.get("content-type");
        if (!contentType || !contentType.includes("application/json")) {
          throw new Error(`Tipo de conteúdo inválido: ${contentType}`);
        }

        const listUsers = await response.json();

        // Validar se é um array
        if (!Array.isArray(listUsers)) {
          throw new Error("Resposta não é um array válido");
        }

        // Mapear campos do backend para o formato esperado pelo frontend
        this.users = listUsers.map((u) => ({
          id: u.idx,
          name: u.name,
          email: u.mail,
          cpf: u.cpf,
          phone: u.phone,
          login: u.login,
          status: u.enabled === "yes" ? "Ativo" : "Inativo",
          enabled: u.enabled,
          genre: u.genre,
          last_login: u.last_login,
        }));
        this.currentPage = 1;
      } catch (error) {
        console.error("Erro ao carregar usuários:", error);
        Toast.fire({
          icon: "error",
          title: "Erro ao carregar usuários",
          text: error.message,
        });
      } finally {
        this.loading = false;
      }
    },

    get filteredUsers() {
      if (!this.search) return this.users;
      return this.users.filter(
        (user) =>
          user.name.toLowerCase().includes(this.search.toLowerCase()) ||
          user.email.toLowerCase().includes(this.search.toLowerCase()) ||
          user.cpf.includes(this.search)
      );
    },

    get paginatedUsers() {
      const filtered = this.filteredUsers;
      if (this.itemsPerPage === "all") {
        return filtered;
      }
      const itemsPerPage = parseInt(this.itemsPerPage);
      const start = (this.currentPage - 1) * itemsPerPage;
      return filtered.slice(start, start + itemsPerPage);
    },

    get totalPages() {
      if (this.itemsPerPage === "all") {
        return 1;
      }
      const itemsPerPage = parseInt(this.itemsPerPage);
      return Math.ceil(this.filteredUsers.length / itemsPerPage);
    },

    nextPage() {
      if (this.currentPage < this.totalPages) {
        this.currentPage++;
      }
    },

    prevPage() {
      if (this.currentPage > 1) {
        this.currentPage--;
      }
    },

    setItemsPerPage(num) {
      this.itemsPerPage = num;
      this.currentPage = 1;
    },

    selectUser(userId) {
      this.selectedUser = userId;
    },

    async viewUser(user) {
      this.selectedUser = user.id;
      await Swal.fire({
        title: user.name,
        html: `
          <div class="text-start">
            <p><strong>Email:</strong> ${user.email}</p>
            <p><strong>CPF:</strong> ${user.cpf}</p>
            <p><strong>Telefone:</strong> ${user.phone}</p>
            <p><strong>Status:</strong> ${user.status}</p>
            <p><strong>Último Acesso:</strong> ${this.formatDate(
              user.last_login
            )}</p>
          </div>
        `,
        icon: "info",
        confirmButtonText: "Fechar",
      });
    },

    async editUser(user) {
      await Swal.fire({
        icon: "info",
        title: "Editar Usuário",
        text: "Função de edição em desenvolvimento",
        confirmButtonText: "OK",
      });
    },

    async deleteUser(user) {
      const result = await Swal.fire({
        title: "Excluir usuário?",
        html: `Tem certeza que deseja excluir <strong>${user.name}</strong>?`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "Sim, excluir",
        cancelButtonText: "Cancelar",
      });

      if (result.isConfirmed) {
        this.users = this.users.filter((u) => u.id !== user.id);
        await Swal.fire(
          "Excluído!",
          "Usuário removido com sucesso.",
          "success"
        );
      }
    },

    formatDate(dateString) {
      if (!dateString) return "-";
      const date = new Date(dateString);
      return date.toLocaleDateString("pt-BR", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      });
    },

    getRoleBadgeClass(role) {
      const classes = {
        Admin: "bg-danger",
        Editor: "bg-primary",
        Usuário: "bg-secondary",
      };
      return classes[role] || "bg-secondary";
    },

    getStatusBadgeClass(status) {
      return status === "Ativo" ? "bg-success" : "bg-warning";
    },
  }));
});
