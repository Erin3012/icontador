(() => {
  const password = document.getElementById('clave');
  const showPassword = document.getElementById('showPassw');
  const recoveryLink = document.getElementById('rcvrpass');
  const message = document.getElementById('resultadoLogin');

  if (password && showPassword) {
    showPassword.addEventListener('change', () => {
      password.type = showPassword.checked ? 'text' : 'password';
    });
  }

  if (recoveryLink && message) {
    recoveryLink.addEventListener('click', (event) => {
      event.preventDefault();
      message.textContent = 'Para recuperar tu clave, solicita ayuda al administrador de la cuenta.';
      message.className = 'alert login-info';
      message.hidden = false;
    });
  }
})();
