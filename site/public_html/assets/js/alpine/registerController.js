/**
 * Register Controller - Alpine.js
 * Valida campos do formulário de cadastro e sanitiza CPF antes do submit
 */

document.addEventListener("alpine:init", () => {
  Alpine.data("registerController", () => ({
    formData: {
      name: "",
      mail: "",
      login: "",
      cpf: "",
      phone: "",
      password: "",
    },

    errors: {},
    isSubmitting: false,

    // Validação simples de e-mail
    isValidEmail(email) {
      const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      return re.test(String(email).toLowerCase());
    },

    validate() {
      this.errors = {};
      let valid = true;

      if (!this.formData.name || this.formData.name.trim().length < 2) {
        this.errors.name = "Nome obrigatório (mínimo 2 caracteres)";
        valid = false;
      }

      if (!this.formData.mail || !this.isValidEmail(this.formData.mail)) {
        this.errors.mail = "E-mail inválido";
        valid = false;
      }

      if (!this.formData.login || this.formData.login.trim().length < 3) {
        this.errors.login = "Login deve ter pelo menos 3 caracteres";
        valid = false;
      }

      if (this.formData.cpf && this.formData.cpf.trim().length > 0) {
        // CPF deve ter ao menos 11 dígitos (após sanitizar)
        const digits = this.formData.cpf.replace(/\D/g, "");
        if (digits.length !== 11) {
          this.errors.cpf = "CPF inválido (deve conter 11 dígitos)";
          valid = false;
        }
      }

      if (!this.formData.password || this.formData.password.length < 6) {
        this.errors.password = "Senha deve ter pelo menos 6 caracteres";
        valid = false;
      }

      return valid;
    },

    // Sanitiza campos client-side antes do envio (remove pontos, traços, espaços)
    sanitizeField(value) {
      if (!value) return value;
      return value.replace(/[^\p{L}0-9]+/gu, "");
    },

    // Máscara para CPF (ex: 000.000.000-00)
    onCpfInput(event) {
      let v = (event.target.value || "").replace(/\D/g, "").slice(0, 11);
      const p1 = v.slice(0, 3);
      const p2 = v.slice(3, 6);
      const p3 = v.slice(6, 9);
      const p4 = v.slice(9, 11);
      let masked = p1;
      if (p2) masked += "." + p2;
      if (p3) masked += "." + p3;
      if (p4) masked += "-" + p4;
      this.formData.cpf = masked;
      event.target.value = masked;
    },

    // Máscara para Telefone brasileiro (ex: (00) 00000-0000 ou (00) 0000-0000)
    onPhoneInput(event) {
      let v = (event.target.value || "").replace(/\D/g, "").slice(0, 11);
      if (v.length <= 10) {
        // formato (00) 0000-0000
        const ddd = v.slice(0, 2);
        const part1 = v.slice(2, 6);
        const part2 = v.slice(6, 10);
        let masked = ddd ? "(" + ddd + ")" : "";
        if (part1) masked += " " + part1;
        if (part2) masked += "-" + part2;
        this.formData.phone = masked;
        event.target.value = masked;
      } else {
        // formato (00) 00000-0000
        const ddd = v.slice(0, 2);
        const part1 = v.slice(2, 7);
        const part2 = v.slice(7, 11);
        let masked = ddd ? "(" + ddd + ")" : "";
        if (part1) masked += " " + part1;
        if (part2) masked += "-" + part2;
        this.formData.phone = masked;
        event.target.value = masked;
      }
    },

    handleSubmit(event) {
      if (!this.validate()) {
        // Construir lista de erros para apresentar ao usuário
        const messages = Object.values(this.errors || {});
        const list = messages.map((m) => `<li>${m}</li>`).join("");
        Swal.fire({
          icon: "warning",
          title: "Atenção",
          html: `<div style="text-align:left"><ul style="margin:0;padding-left:1.2rem">${list}</ul></div>`,
          confirmButtonText: "Ok",
        }).then(() => {
          // focar primeiro campo inválido
          const firstKey = Object.keys(this.errors || {})[0];
          if (firstKey) {
            const el =
              document.getElementById(firstKey) ||
              document.querySelector(`[name="${firstKey}"]`);
            if (el && typeof el.focus === "function") el.focus();
          }
        });
        return;
      }

      if (this.isSubmitting) return;
      this.isSubmitting = true;

      // Sanitizar CPF e atualizar input antes do submit
      const cpfInput = event.target.querySelector('input[name="cpf"]');
      if (cpfInput) {
        const cleaned = (this.formData.cpf || "").replace(/\D/g, "");
        cpfInput.value = cleaned;
      }

      // Sanitizar telefone antes do submit (apenas dígitos)
      const phoneInput = event.target.querySelector('input[name="phone"]');
      if (phoneInput) {
        const cleanedPhone = (this.formData.phone || "").replace(/\D/g, "");
        phoneInput.value = cleanedPhone;
      }

      // Submit nativo
      event.target.submit();
    },

    resetForm() {
      this.formData = {
        name: "",
        mail: "",
        login: "",
        cpf: "",
        phone: "",
        password: "",
      };
      this.errors = {};
      this.isSubmitting = false;
    },

    init() {
      this.$nextTick(() => {
        const el = document.getElementById("name");
        if (el) el.focus();
      });
    },
  }));
});