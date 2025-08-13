(() => {
  // Obtener referencia al formulario de autenticación
  const form = document.getElementById('auth-form');
  
  // Salir si el formulario no existe para evitar errores
  if (!form) return;

  // Referencia al campo oculto que indica la acción actual: 'login' o 'register'
  const action = document.getElementById('action');

  // Botón que permite alternar entre los modos de login y registro
  const toggle = document.getElementById('toggle-mode');

  // Configurar el comportamiento al hacer clic en el botón de alternar
  toggle?.addEventListener('click', () => {
    // Determinar si el formulario está actualmente en modo login
    const isLogin = action.value === 'login';

    // Alternar el valor del input 'action' según el modo actual
    // Login -> Register, Register -> Login
    action.value = isLogin ? 'register' : 'login';

    // Actualizar el texto del botón de alternar para reflejar la acción contraria
    // Proporciona indicación clara al usuario sobre la acción disponible
    toggle.textContent = isLogin ? 'Ya tengo cuenta' : 'Crear cuenta';

    // Actualizar el texto del botón de envío para reflejar la acción actual
    // Login -> 'Crear cuenta', Register -> 'Entrar'
    form.querySelector('button[type="submit"]').textContent = isLogin ? 'Crear cuenta' : 'Entrar';
  });
})();
