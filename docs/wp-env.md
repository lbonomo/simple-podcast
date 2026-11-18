# Guía de referencia de `wp-env`

Referencia completa en español basada en la guía oficial de WordPress: [Get started with wp-env](https://developer.wordpress.org/block-editor/getting-started/devenv/get-started-with-wp-env/).

El paquete [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) (`wp-env`) permite levantar un entorno local de WordPress (sitio) para desarrollar y probar plugins y temas sin configuración adicional.

> En este repositorio `@wordpress/env` ya figura en `devDependencies` (`package.json`), por lo que puedes ejecutar `npm run env:start` o `npx wp-env <comando>` desde la raíz del proyecto sin instalarlo de forma global. Consulta [setup.md](setup.md) para los comandos propios del plugin.

Antes de seguir esta guía, asegúrate de tener instaladas las [herramientas de desarrollo de Node.js](https://developer.wordpress.org/block-editor/getting-started/devenv/#node-js-development-tools).

## Inicio rápido

1. Instala e inicia un entorno de ejecución de Docker, como [Colima](https://github.com/abiosoft/colima) en macOS o [Docker Desktop](https://www.docker.com/products/docker-desktop). Consulta [Configurar Docker](#configurar-docker) más abajo.
2. Ejecuta `npm -g install @wordpress/env` en la terminal para instalar `wp-env` de forma global (o usa `npx wp-env` / `npm run env:start` dentro de este repositorio).
3. En la terminal, navega a la carpeta de un plugin existente, un tema o un nuevo directorio de trabajo.
4. Ejecuta `wp-env start` en la terminal para iniciar el entorno local de WordPress.
5. Cuando el script termine, accede a `http://localhost:8888/wp-admin` e inicia sesión en el escritorio de WordPress con usuario `admin` y contraseña `password`.

## Configurar Docker

La herramienta `wp-env` utiliza [Docker](https://www.docker.com/) para crear una máquina virtual que ejecuta el sitio local de WordPress. Cualquier entorno de ejecución de Docker que provea el CLI `docker` y Docker Compose v2 funciona.

### Colima (macOS)

[Colima](https://github.com/abiosoft/colima) es gratuito y de código abierto, no requiere cuenta y funciona sin interfaz gráfica. Instálalo e inícialo con [Homebrew](https://brew.sh/):

```bash
brew install colima docker docker-buildx docker-compose
colima start --cpu 4 --memory 4
```

Homebrew instala Buildx y Compose como plugins del CLI de Docker fuera de la ruta de búsqueda por defecto del CLI. Para indicarle dónde encontrarlos, añade esta propiedad a `~/.docker/config.json`, manteniendo el resto del contenido que ya tenga el archivo. La ruta siguiente corresponde a Apple Silicon; en Macs Intel usa `/usr/local/lib/docker/cli-plugins`:

```json
"cliPluginsExtraDirs": [ "/opt/homebrew/lib/docker/cli-plugins" ]
```

Colima recuerda los valores de CPU y memoria, por lo que en inicios posteriores basta con `colima start`. Para iniciarlo automáticamente al iniciar sesión, ejecuta `brew services start colima`.

### Docker Desktop

La aplicación Docker Desktop es gratuita para pequeñas empresas, uso personal, educación y proyectos de código abierto no comerciales (consulta su [FAQ](https://docs.docker.com/desktop/faqs/general/#do-i-need-to-pay-to-use-docker-desktop) para más detalles).

Enlaces de descarga e instalación según el sistema operativo:

- [Docker Desktop para Mac](https://docs.docker.com/desktop/install/mac-install/)
- [Docker Desktop para Windows](https://docs.docker.com/desktop/install/windows-install/) (se recomienda el backend WSL2)
- [Docker Desktop para Linux](https://docs.docker.com/desktop/install/linux-install/)

Si utilizas una versión de Ubuntu anterior a la `20.04.1`, revisa las [notas de solución de problemas en Ubuntu](#configuración-de-docker-en-ubuntu).

Tras completar la instalación, abre Docker Desktop y sigue el asistente de configuración. Por lo general conviene usar la configuración recomendada (por defecto); crear una cuenta de Docker es opcional.

### Otros entornos de ejecución

- **Windows:** [Rancher Desktop](https://rancherdesktop.io/) con su motor `dockerd (moby)` es una alternativa gratuita a Docker Desktop.
- **Linux:** Puedes usar [Docker Engine](https://docs.docker.com/engine/install/) directamente.

## Instalar y ejecutar `wp-env`

Después de configurar Docker, abre la terminal e instala `wp-env` de forma global con:

```bash
npm -g install @wordpress/env
```

Esto permite ejecutar `wp-env` desde cualquier directorio. Para verificar que quedó disponible, ejecuta `wp-env --version`, lo cual debe mostrar el número de versión.

A continuación, sitúate en el directorio de un plugin, un tema o un directorio de trabajo y ejecuta:

```bash
wp-env start
```

Al finalizar el script, podrás acceder al entorno local en `http://localhost:8888`. Inicia sesión en el panel de WordPress con usuario `admin` y contraseña `password`.

> Algunos proyectos, como Gutenberg (o este repositorio mediante `npm run env:start`), incluyen sus propios scripts o configuraciones de `wp-env` y su documentación indica ejecutar `npm run wp-env start` o el script definido en `package.json`.

Para más detalles sobre cómo controlar el entorno Docker, consulta la [documentación del paquete `@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guide/packages/packages-env/).

### Dónde ejecutar `wp-env`

`wp-env` puede ejecutarse prácticamente desde cualquier carpeta:

- Si ejecutas `wp-env start` desde la carpeta de un plugin, monta y activa ese plugin automáticamente.
- Lo mismo ocurre con temas al ejecutarlo desde la carpeta del tema en desarrollo.
- Si lo ejecutas desde un directorio que no es un plugin ni un tema, creará un entorno genérico de WordPress y mostrará la siguiente advertencia (puedes ignorarla si es intencional):

```text
!! Warning: could not find a .wp-env.json configuration file and could not determine if 'DIR' is a WordPress installation, a plugin, or a theme.
```

También puedes usar el archivo de configuración `.wp-env.json` para crear un entorno con múltiples plugins y/o temas. Consulta la sección [`.wp-env.json` en la documentación de `@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guide/packages/packages-env/#wp-envjson) para más opciones.

### Desinstalar o restablecer `wp-env`

- **Limpiar y restablecer solo la base de datos de WordPress:** `wp-env clean all`
- **Eliminar por completo el entorno local de un proyecto específico:** `wp-env destroy`
- **Desinstalar `wp-env` de forma global:** `npm -g uninstall @wordpress/env`

## Solución de problemas

### Errores comunes

**Error:** `Error while running docker-compose command`

- Comprueba que Docker esté en ejecución. Con Colima, `colima status` muestra su estado.
- Con Docker Desktop, revisa los registros en el panel, reinicia o elimina máquinas virtuales existentes. Con Colima, ejecuta `colima restart`.
- Después vuelve a ejecutar `wp-env start`.

**Error:** `Host is already in use by another container`

- El contenedor que intentas iniciar ya está en ejecución, u otro contenedor está ocupando el puerto/host. Detén el entorno existente ejecutando `wp-env stop` desde el directorio donde lo iniciaste.
- Si no recuerdas desde qué directorio iniciaste `wp-env`, puedes detener todos los contenedores en ejecución con `docker stop $(docker ps -q)` (detendrá **todos** los contenedores Docker activos, úsalo con precaución).
- Luego vuelve a intentar `wp-env start`.

### Configuración de Docker en Ubuntu

Si utilizas una versión de Ubuntu anterior a `20.04.1`, pueden aparecer errores al iniciar un entorno local de WordPress con `wp-env`.

1. Sigue la [guía de instalación de Docker para Ubuntu](https://docs.docker.com/install/linux/docker-ce/ubuntu/). También es necesario `docker-compose`, que quizá debas instalar por separado según la [documentación de Docker Compose](https://docs.docker.com/compose/install/).
2. Con Docker y `wp-env` instalados, si al ejecutar `wp-env start` aparece el error:

```text
ERROR: Couldn't connect to Docker daemon at http+docker://localhost - is it running?

If it's at a non-standard location, specify the URL with the DOCKER_HOST environment variable.
```

3. Comprueba primero que Docker esté corriendo:

```bash
ps -ef | grep docker
```

Debería devolver una salida similar a:

```text
/usr/bin/dockerd -H fd:// --containerd=/run/containerd/containerd.sock
```

Si Docker no está corriendo, intenta iniciar el servicio con:

```bash
sudo systemctl start docker.service
```

4. Si Docker ya está en ejecución, es posible que no esté escuchando en el protocolo que espera el entorno de WordPress. Añade el siguiente archivo de sobrescritura de systemd para habilitar la escucha en `tcp` (consulta la [documentación de Docker sobre acceso remoto al daemon](https://docs.docker.com/config/daemon/remote-access/)):

```ini
# /etc/systemd/system/docker.service.d/override.conf
[Service]
ExecStart=
ExecStart=/usr/bin/dockerd -H fd:// -H tcp://0.0.0.0:2376
```

5. Recarga la configuración y reinicia el servicio:

```bash
sudo systemctl daemon-reload
sudo systemctl restart docker.service
```

6. Define la variable de entorno `DOCKER_HOST` y vuelve a iniciar `wp-env`:

```bash
export DOCKER_HOST=tcp://127.0.0.1:2376
wp-env start
```

Tu entorno debería quedar disponible en `http://localhost:8888`.

## Recursos adicionales

- [`@wordpress/env` (documentación oficial en npm)](https://www.npmjs.com/package/@wordpress/env)
- [Referencia del paquete `@wordpress/env` (Block Editor Handbook)](https://developer.wordpress.org/block-editor/reference-guide/packages/packages-env/)
- [Colima (documentación oficial)](https://github.com/abiosoft/colima)
- [Docker Desktop (documentación oficial)](https://docs.docker.com/desktop)
- [Quick and easy local WordPress development with wp-env (WordPress Developer Blog)](https://developer.wordpress.org/news/2023/03/quick-and-easy-local-wordpress-development-with-wp-env/)
- [wp-env: Simple Local Environments for WordPress (Make WordPress Core Blog)](https://make.wordpress.org/core/2020/03/03/wp-env-simple-local-environments-for-wordpress/)
- [Diagrama básico de `wp-env` (Excalidraw)](https://excalidraw.com/#json=8Tp55B-R6Z6-pNGtmenU6,_DeBR1IBxuHNIKPTVEaseA) ([imagen PNG](https://i0.wp.com/developer.wordpress.org/files/2023/10/wp-env-diagram.png?ssl=1))
