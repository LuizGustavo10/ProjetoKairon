'use strict';
document.querySelectorAll('[data-password]').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.password);
    input.type = input.type === 'password' ? 'text' : 'password';
    const label = button.querySelector('span');
    const symbol = button.querySelector('i');
    if (label) label.textContent = input.type === 'password' ? 'Mostrar' : 'Ocultar';
    if (symbol) {
      symbol.classList.toggle('fa-eye', input.type === 'password');
      symbol.classList.toggle('fa-eye-slash', input.type !== 'password');
    }
    button.setAttribute('aria-label', input.type === 'password' ? 'Mostrar senha' : 'Ocultar senha');
  });
});
document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
  const button = form.querySelector('.submit');
  if (button && form.checkValidity()) {
    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>Só um instante…</span>';
  }
}));
