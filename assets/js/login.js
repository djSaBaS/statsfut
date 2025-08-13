/**
 * Script de animación y manejo del formulario de login
 * ----------------------------------------------------
 * Este script se encarga de:
 * 1. Interceptar el envío del formulario para evitar el comportamiento por defecto.
 * 2. Aplicar una animación visual al botón de "Enviar" simulando que se convierte en
 *    una pelota de fútbol que rueda hacia la derecha.
 * 3. Redirigir al usuario a la pantalla principal tras finalizar la animación.
 * 
 * Requiere que en el CSS exista:
 *  - Una clase `.ball` para cambiar el estilo del botón a forma de balón.
 *  - Una animación `@keyframes roll` para simular el rodar.
 */

// Asociamos un listener al evento "submit" del formulario con ID "loginForm"
document.getElementById("loginForm").addEventListener("submit", function (e) {
    // Evita que el formulario se envíe de forma tradicional y recargue la página
    e.preventDefault();

    // Obtenemos la referencia al botón de login
    const btn = document.getElementById("loginBtn");

    // Añadimos la clase "ball" para aplicar el estilo visual de pelota
    btn.classList.add("ball");

    // Iniciamos la animación CSS "roll" con duración de 1 segundo y avance hacia delante
    btn.style.animation = "roll 1s forwards";

    // Tras 1 segundo (duración de la animación), redirigimos al usuario
    // En este punto, se podría sustituir la redirección por una validación real en PHP
    setTimeout(() => {
        window.location.href = "home.php";
    }, 1000);
});
