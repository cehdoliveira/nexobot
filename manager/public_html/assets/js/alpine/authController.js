/**
 * Controllers Bundle - Alpine.js
 * Unifica os controllers do manager em um único arquivo para simplificar carregamento.
 */

document.addEventListener("alpine:init", () => {
  // Auth Controller
  Alpine.data("authController", () => ({
    async logout() {
      const result = await Swal.fire({
        title: "Sair do sistema?",
        text: "Você será desconectado",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "Sim, sair",
        cancelButtonText: "Cancelar",
      });

      if (result.isConfirmed) {
        window.location.href = "?logout=yes";
      }
    },
  }));

  // Login Controller
  Alpine.data("loginController", () => ({
    formData: {
      login: "",
      password: "",
      remember: false,
    },

    isSubmitting: false,
    showPassword: false,
    errors: {},

    validate() {
      this.errors = {};
      let isValid = true;

      if (!this.formData.login || this.formData.login.trim().length < 3) {
        this.errors.login = "Login deve ter pelo menos 3 caracteres";
        isValid = false;
      }

      if (!this.formData.password || this.formData.password.length < 4) {
        this.errors.password = "Senha deve ter pelo menos 4 caracteres";
        isValid = false;
      }

      return isValid;
    },

    async handleSubmit(event) {
      if (!this.validate()) {
        Swal.fire({
          icon: "warning",
          title: "Atenção",
          text: "Por favor, corrija os erros no formulário",
          confirmButtonText: "Ok",
        });
        return;
      }

      if (this.isSubmitting) return;
      this.isSubmitting = true;
      this.errors = {};

      event.target.submit();
    },

    resetForm() {
      this.formData = { login: "", password: "", remember: false };
      this.errors = {};
      this.isSubmitting = false;
      this.showPassword = false;
    },

    init() {
      this.$nextTick(() => {
        const loginInput = document.getElementById("login");
        if (loginInput) loginInput.focus();
      });
    },
  }));
});
