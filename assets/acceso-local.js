(() => {
  const form = document.getElementById('login_form');
  const password = document.getElementById('password');
  const showPassword = document.getElementById('showPassw');
  const result = document.getElementById('resultadoLogin');
  const recover = document.getElementById('rcvrpass');

  if (!form || !password || !showPassword || !result || !recover) return;

  const showMessage = (message, type = 'info') => {
    result.textContent = message;
    result.className = `alert login-${type}`;
    result.hidden = false;
  };

  showPassword.addEventListener('change', () => {
    password.type = showPassword.checked ? 'text' : 'password';
  });

  recover.addEventListener('click', (event) => {
    event.preventDefault();
    showMessage('La recuperación de clave requiere el servicio original. Esta copia local no envía solicitudes.');
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    password.value = '';
    showPassword.checked = false;
    password.type = 'password';
    showMessage('Esta pantalla es una referencia visual. El acceso no está conectado; puedes abrir el panel local desde el enlace inferior.');
  });
})();
