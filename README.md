# ⚽ StatsFut

![StatsFut Logo](./logo.png)

📊 **StatsFut** es una plataforma web diseñada para **gestionar, registrar y analizar estadísticas de partidos de fútbol** de manera sencilla y visual. Ideal para equipos, entrenadores y aficionados que quieren llevar un control detallado de su rendimiento.

---

## 🚀 Características principales

-   📅 **Gestión de partidos**: Registra fecha, equipos, resultados y eventos clave.
-   📈 **Estadísticas en tiempo real**: Pases, tiros, posesión, goles y más.
-   📂 **Organización por temporadas** y competiciones.
-   🖥 **Interfaz intuitiva** optimizada para móvil y escritorio.
-   ☁ **Datos en la nube** para acceder desde cualquier lugar.
-   🔒 **Control de acceso seguro** mediante usuarios y contraseñas.

---

## 📂 Estructura del repositorio

```
Por supuesto, aquí tienes la estructura de archivos en formato de árbol de texto para tu README.md, basada en la lista que me has proporcionado.

Simplemente copia y pega este bloque completo en tu archivo.

StatsFut/
│
├── assets/
│   ├── css/                  # Hojas de estilo CSS
│   ├── js/                   # Scripts de JavaScript
│   └── img/                  # Imágenes, logos y escudos
│
├── includes/
│   ├── db.php                # Script de conexión a la base de datos
│   ├── header.php            # Cabecera HTML común para las páginas
│   └── footer.php            # Pie de página HTML común
│
├── index.php                 # Página de login y autenticación
├── home.php                  # Pantalla principal o dashboard
├── partido_nuevo.php         # Formulario para crear un nuevo partido
├── partido.php               # Vista para registrar estadísticas en tiempo real
├── partido_editar.php        # Formulario para editar los totales de un partido
├── partido_finalizar.php     # Script que procesa la finalización de un partido
├── partidos_lista.php        # Muestra el listado de todos los partidos
├── partido_estadisticas.php  # Visualización de estadísticas y gráficas
├── configuracion.php         # Panel para configurar datos del equipo
└── logout.php                # Script para cerrar la sesión del usuario
```

---

## 🛠️ Tecnologías utilizadas

-   **Frontend:** HTML5, CSS3, JavaScript (jQuery)
-   **Backend:** PHP 8+
-   **Base de datos:** MySQL
-   **Control de versiones:** Git / GitHub
-   **Diseño responsivo:** Mobile First

---

## 📌 Versiones

-   **v0.1.0** – Creación inicial del repositorio, estructura base y recursos gráficos.
-   **v0.2.0** – Integración de formularios para registro de partidos.
-   **v0.3.0** – Módulo de estadísticas y primeros reportes gráficos.

> 🔄 *Las versiones futuras incluirán mejoras de rendimiento, nuevos tipos de estadísticas y un panel de administración avanzado.*

---

## 📥 Instalación y uso

1.  **Clonar el repositorio:**

2.  **Configurar la base de datos:**
    -   Importa el archivo `sql/statsfut.sql` en tu gestor de MySQL.
    -   Edita el archivo `php/config.php` con tus credenciales de conexión.

3.  **Ejecutar en un servidor local:**
    -   Utiliza un entorno de servidor como XAMPP, WAMP o MAMP.
    -   Coloca la carpeta del proyecto en el directorio raíz de tu servidor (ej. `htdocs`).

4.  **Abrir en el navegador:**
    -   Accede a `http://localhost/StatsFut` para iniciar la aplicación.

---

## 🤝 Contribuciones

¡Toda ayuda es bienvenida! Si quieres colaborar:

1.  Haz un **fork** del repositorio.
2.  Crea una nueva rama (`git checkout -b feature-nueva`).
3.  Realiza tus cambios y haz **commit**.
4.  Envía un **pull request**.

---

## 📜 Licencia

Este proyecto está bajo la licencia MIT. Consulta el archivo `LICENSE` para más detalles.

> 💡 StatsFut está pensado para evolucionar con el feedback de la comunidad y convertirse en la herramienta de referencia para el seguimiento de estadísticas futbolísticas.
