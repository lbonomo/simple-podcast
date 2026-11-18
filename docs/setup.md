# Entorno de desarrollo

El plugin se desarrolla con [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), que levanta un WordPress local con Docker y monta esta carpeta como plugin.

> La carpeta del plugin es `simple-podcast`, pero el slug, el archivo principal y el text domain son `lb-simple-podcast`. El zip de producción instala la carpeta `lb-simple-podcast/`.

Todos los comandos se ejecutan desde esta carpeta. Requiere Docker en ejecución:

```bash
npm ci
npm run env:start
```

Al terminar la inicialización, abre `http://localhost:8888` e inicia sesión con `admin` / `password`. `wp-env` monta y activa el plugin de esta carpeta. Para ejecutar WP-CLI: `npx wp-env run cli wp <comando>`.

### Test E2E de publicación

Con `wp-env` iniciado, ejecuta desde esta carpeta:

```bash
WP_BASE_URL=http://localhost:8888 npm run test:e2e
```

La prueba usa Gutenberg para crear y publicar una entrada, subir `tests/audios/demo.mp3` y asociarlo desde el panel **Podcast (audio MP3)**. Después verifica el reproductor en la entrada pública y el episodio en `/?feed=podcast`. Cada ejecución deja la entrada y el archivo de prueba publicados en el entorno local.

### Listar registros de audio

Con wp-env iniciado, ejecuta desde esta carpeta:

```bash
npm run logs        # últimos 50 registros
npm run logs -- 10  # últimos 10 registros
```

Consulta la tabla SQL del sitio de desarrollo, sin modificarla, y muestra primero los registros más recientes: ID, fecha UTC, entrada, attachment, usuario, método, IP y estado HTTP. No muestra cabeceras ni URL completas para evitar imprimir posibles credenciales. No consulta `debug.log` ni los logs de Docker.

## Requisitos
- Docker, Composer y Node.js (para compilar los assets).
- WordPress 6.9 o superior (la ability MCP lo necesita) y el plugin `mcp-adapter` para probar la tool.

## Puesta en marcha

```bash
composer install
npm ci && npm run build
npm run env:start
npx wp-env run cli wp rewrite flush --hard
```

`npm run start` comprueba que exista `vendor/autoload.php` antes de iniciar webpack. Si falta, ejecuta `composer install`.

### Base de datos de pruebas
Los tests de integración usan la base `wordpress_test` (prefijo `wptests_`), separada de la del sitio. `wp-env` ya levanta el contenedor `tests-mysql`; si la base no existe, créala una vez:

```bash
docker exec <contenedor tests-mysql> mysql -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS wordpress_test"
```

`tests/phpunit/bootstrap.php` genera `tests/phpunit/wp-tests-config.php` (ignorado por git). Se puede ajustar con las variables `LB_SP_DB_HOST`, `LB_SP_DB_NAME`, `LB_SP_DB_USER`, `LB_SP_DB_PASSWORD` y `LB_SP_WP_ABSPATH`. Sus valores por defecto corresponden al entorno de pruebas de `wp-env`.

## Comandos habituales

| Tarea | Dónde | Comando |
| --- | --- | --- |
| PHPUnit | este repo | `npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit` |
| Solo unitarios | este repo | `npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit --testsuite unit` |
| PHPStan (nivel 8) | este repo | `composer phpstan` |
| PHPCS (WordPress-Core/Extra/Docs) | este repo | `composer phpcs` (corrección automática: `vendor/bin/phpcbf`) |
| Jest | este repo | `npm test` |
| Build de assets | este repo | `npm run build` |
| Zip de producción | este repo | `bin/build-zip.sh` |
| Verificar el zip | este repo | `bin/verify-zip.sh lb-simple-podcast.zip` |
| Regenerar traducciones | este repo | `npx wp-env run cli wp i18n make-pot wp-content/plugins/simple-podcast wp-content/plugins/simple-podcast/languages/lb-simple-podcast.pot --slug=lb-simple-podcast --exclude=vendor,node_modules,tests,build` |

## Verificación manual del feed

```bash
# Cabeceras (HEAD): Content-Type, ETag y Last-Modified.
curl -I http://localhost:8888/feed/podcast/

# Primeras líneas del XML y validación de sintaxis.
curl -s http://localhost:8888/feed/podcast/ | head -n 25
curl -s http://localhost:8888/feed/podcast/ | xmllint --noout - && echo "XML OK"

# Caché condicional: debe responder 304.
ETAG=$(curl -s -D - -o /dev/null http://localhost:8888/feed/podcast/ | grep -i '^etag' | cut -d' ' -f2- | tr -d '\r')
curl -s -o /dev/null -w '%{http_code}\n' -H "If-None-Match: $ETAG" http://localhost:8888/feed/podcast/
```

Apache puede agregar `-gzip` al ETag de las respuestas comprimidas; el plugin lo tiene en cuenta al comparar.

## Verificación de la REST API

```bash
curl -s -u "usuario:application password" \
  "http://localhost:8888/wp-json/wp/v2/posts/ID?_fields=id,audio_data,meta"
```

Para asociar un audio por REST: `POST /wp-json/wp/v2/posts/ID` con `{"meta":{"_lb_sp_audio_id": ATTACHMENT_ID}}`.

## Probar el paquete de producción

```bash
bin/build-zip.sh                      # crea lb-simple-podcast.zip (y lo verifica)
```

```bash
# Desactiva primero la copia de desarrollo para evitar clases duplicadas:
npx wp-env run cli wp plugin deactivate simple-podcast
npx wp-env run cli wp plugin install wp-content/plugins/simple-podcast/lb-simple-podcast.zip --activate
# ... pruebas ...
npx wp-env run cli wp plugin deactivate lb-simple-podcast
npx wp-env run cli wp plugin delete lb-simple-podcast
npx wp-env run cli wp plugin activate simple-podcast
```

## Verificar las actualizaciones de GitHub

El actualizador consulta `https://github.com/lbonomo/simple-podcast/` sin credenciales. El repositorio debe ser público; GitHub devuelve 404 para los repositorios privados cuando no se envía autenticación. La rama configurada es `main`.

Cada release estable debe adjuntar `lb-simple-podcast.zip`, generado por el workflow de publicación, para distribuir las dependencias de Composer y los assets compilados.

Después de cambiar la visibilidad del repositorio o la configuración del actualizador, usa **Comprobar actualizaciones** en la fila del plugin dentro de **Plugins** para volver a consultar GitHub. Si la versión instalada coincide con el último release, el resultado esperado es que no haya actualizaciones disponibles, sin errores de la API.

## Probar la ability MCP
Activar `mcp-adapter` (`npx wp-env run cli wp plugin activate mcp-adapter`) y seguir el ejemplo de [mcp-tool-schema.md](mcp-tool-schema.md). Para una URL de prueba que apunte al propio sitio local (red privada) se puede permitir el host con el filtro `lb_sp_audio_url_allowlist`.

## Producción: IP del cliente detrás de un proxy o CDN

Por defecto el plugin usa `REMOTE_ADDR`. Detrás de un proxy inverso o CDN eso es la IP del proxy, y el límite de solicitudes por IP trata a todos los oyentes como uno solo. Hay tres opciones, de la más a la menos recomendable. Detalle y riesgos en `docs/ip-cliente-y-proxies.md`.

1. **Que el servidor web corrija `REMOTE_ADDR`** (no requiere cambios en el plugin):
   - Nginx: `set_real_ip_from <rango del proxy>;` y `real_ip_header X-Forwarded-For;` (módulo `ngx_http_realip_module`).
   - Apache: `RemoteIPHeader X-Forwarded-For` y `RemoteIPInternalProxy <rango del proxy>` (módulo `mod_remoteip`).
2. **Proxies de confianza en el plugin**, en `wp-config.php`:

   ```php
   define( 'LB_SP_TRUSTED_PROXIES', array( '203.0.113.0/24', '2001:db8::/32' ) );
   // Opcional: otra cabecera (por ejemplo, CF-Connecting-IP). Por defecto, X-Forwarded-For.
   define( 'LB_SP_PROXY_HEADER', 'CF-Connecting-IP' );
   ```

   Solo se lee la cabecera cuando `REMOTE_ADDR` está en esa lista. Con `X-Forwarded-For` se recorre de derecha a izquierda hasta la primera IP que no sea de un proxy de confianza. Si el origen es accesible sin pasar por el proxy, restrínjalo en el firewall: cualquier otro cliente podría falsificar la cabecera.
3. **Filtro `lb_sp_client_ip`** para casos especiales.

Para guardar la IP anonimizada en el registro (IPv4 sin el último octeto, IPv6 a /48), añada `define( 'LB_SP_ANONYMIZE_IP', true );`. El límite de solicitudes sigue usando la IP completa, solo en un contador temporal.
