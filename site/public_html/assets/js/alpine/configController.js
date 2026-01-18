document.addEventListener('alpine:init', () => {
  Alpine.data('configController', () => ({
    mode: 'dev',
    init() {
      const selected = document.querySelector('input[name="mode"]:checked');
      this.mode = selected ? selected.value : 'dev';
    },
    setMode(newMode) {
      this.mode = newMode;
      const devCreds = document.getElementById('devCredentials');
      const prodCreds = document.getElementById('prodCredentials');
      if (!devCreds || !prodCreds) return;
      if (newMode === 'prod') {
        devCreds.style.display = 'none';
        prodCreds.style.display = 'block';
      } else {
        devCreds.style.display = 'block';
        prodCreds.style.display = 'none';
      }
    }
  }));
});
