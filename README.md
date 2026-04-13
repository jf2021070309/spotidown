# 🎵 SpotiDown – Spotify Playlist Downloader (Premium)

SpotiDown es una aplicación web moderna diseñada para descargar playlists, álbumes y canciones individuales de Spotify en calidad **320 kbps** directamente a archivos MP3, permitiendo además la descarga de colecciones completas en un único archivo **ZIP**.

![SpotiDown Preview](https://img.shields.io/badge/Status-Functional-brightgreen)
![Quality](https://img.shields.io/badge/Quality-320kbps-success)
![Platform](https://img.shields.io/badge/Platform-XAMPP%20%7C%20Windows-blue)

## ✨ Características

- 🚀 **Interfaz Premium**: Diseño moderno con efectos Glassmorphism, animaciones y modo oscuro.
- 📦 **Download ZIP**: Descarga playlists completas empaquetadas automáticamente en un ZIP.
- 🎧 **Alta Calidad**: Configurado para forzar tracks a 320 kbps (MP3).
- 🔄 **Cola de Descarga**: Visualización del progreso en tiempo real canción por canción.
- 🛡️ **Setup Check**: Página integrada (`setup.html`) para verificar dependencias.

## 🛠️ Requisitos del Sistema

1.  **XAMPP** (con PHP 7.4 o superior).
2.  **Python 3.10+** instalado y en el PATH.
3.  **Librería spotdl**: Instalada en el entorno virtual del proyecto.
4.  **FFmpeg**: Necesario para la conversión de audio.

## 🚀 Instalación en 3 Pasos

### 1. Clonar o descargar
Coloca los archivos en tu carpeta de XAMPP: `C:\xampp\htdocs\spotidown`.

### 2. Configurar el Entorno Virtual (.venv)
Abre una terminal en la carpeta del proyecto y ejecuta:

```powershell
# Crear el entorno virtual
python -m venv .venv

# Instalar spotdl dentro del entorno
.\.venv\Scripts\python.exe -m pip install spotdl
```

### 3. Configurar FFmpeg
Para que la conversión funcione correctamente, descarga FFmpeg usando spotdl y muévelo a la carpeta de scripts:

```powershell
# Descargar FFmpeg automáticamente
.\.venv\Scripts\spotdl.exe --download-ffmpeg

# Mover el ejecutable a la carpeta de ejecución (ajusta "Mi Equipo" por tu nombre de usuario)
copy "C:\Users\Mi Equipo\.spotdl\ffmpeg.exe" ".\.venv\Scripts\ffmpeg.exe"
```

## 🖥️ Uso

1.  Inicia **Apache** desde el panel de XAMPP.
2.  Accede a la página de configuración para verificar que todo esté OK:
    `http://localhost/spotidown/setup.html`
3.  ¡Empieza a descargar!
    `http://localhost/spotidown/index.html`

## 📁 Estructura del Proyecto

- `index.html`: Interfaz principal.
- `style.css`: Estilos visuales premium.
- `app.js`: Lógica del frontend y polling de progreso.
- `api.php`: Endpoints para búsqueda, descarga individual y estado de trabajos.
- `worker.php`: Script de fondo que descarga y crea el ZIP.
- `setup.html`: Utilidad para verificar dependencias.
- `downloads/`: Carpeta donde se guardan temporalmente los archivos (se crea automáticamente).
- `jobs/`: Carpeta para el seguimiento de tareas en segundo plano.

## ⚖️ Aviso Legal
Esta herramienta ha sido creada con fines educativos y de respaldo personal. No apoyamos la piratería. Asegúrate de tener los derechos necesarios sobre la música que descargas.

---
Creado con ❤️ para disfrutar de la música sin límites.
