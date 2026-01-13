/**
 * Counter Controller - Alpine.js
 * Controla o exemplo de contador interativo
 */

document.addEventListener("alpine:init", () => {
  Alpine.data("counterController", () => ({
    count: 0,
    open: false,

    increment() {
      this.count++;
    },

    decrement() {
      this.count--;
    },

    reset() {
      this.count = 0;
    },

    toggle() {
      this.open = !this.open;
    },
  }));
});
