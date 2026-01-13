/**
 * Contact Controller - Alpine.js
 * Controla o formulário de contato
 */

document.addEventListener("alpine:init", () => {
  Alpine.data("contactController", () => ({
    submitted: false,

    async submitForm(event) {
      event.preventDefault();

      await Swal.fire({
        title: "Enviando...",
        html: "Processando sua mensagem",
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
          setTimeout(() => {
            Swal.fire({
              icon: "success",
              title: "Mensagem enviada!",
              text: "Entraremos em contato em breve.",
              confirmButtonText: "OK",
            });
            this.submitted = true;
            event.target.reset();
          }, 1500);
        },
      });
    },
  }));
});
