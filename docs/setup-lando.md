# Entorno de desarrollo con Lando

El plugin vive en `plugins/simple-podcast/` y el sitio Lando (`name: plugins`, `https://plugins.lndo.site`) está en `../../server/` (no en este repo). El `.lando.yml` de ese proyecto monta esta carpeta como `wp-content/plugins/simple-podcast` dentro del contenedor.

> La carpeta del plugin es `simple-podcast`, pero el slug, el archivo principal y el text domain son `lb-simple-podcast`. El zip de producción instala la carpeta `lb-simple-podcast/`.

Todos los comandos `lando` se ejecutan desde `../../server/`.

## Requisitos
- Lando, Docker, Composer y Node.js (para compilar los assets).
- PHP del contenedor: 8.2. WordPress 7.1 (la ability MCP necesita ≥ 6.9) y el plugin `mcp-adapter` para probar la tool.

## Puesta en marcha

```bash
# En esta carpeta (plugins/simple-podcast): dependencias y assets.
composer install
npm ci && npm run build

# En ../../server: iniciar Lando y activar el plugin.
lando start
lando wp plugin activate simple-podcast
lando wp rewrite flush --hard
```

### Base de datos de pruebas
Los tests de integración usan la base `wordpress_test` (prefijo `wptests_`), separada de la del sitio. Se crea una vez:

```bash
lando mysql -h database -uroot -e "CREATE DATABASE IF NOT EXISTS wordpress_test; GRANT ALL ON wordpress_test.* TO 'wordpress'@'%'; FLUSH PRIVILEGES;"
```

`tests/phpunit/bootstrap.php` genera `tests/phpunit/wp-tests-config.php` (ignorado por git). Se puede ajustar con las variables `LB_SP_DB_HOST`, `LB_SP_DB_NAME`, `LB_SP_DB_USER`, `LB_SP_DB_PASSWORD` y `LB_SP_WP_ABSPATH`.

## Comandos habituales

| Tarea | Dónde | Comando |
| --- | --- | --- |
| PHPUnit | `../../server` | `lando ssh -c "cd /app/wordpress/wp-content/plugins/simple-podcast && vendor/bin/phpunit"` |
| Solo unitarios | idem | `... vendor/bin/phpunit --testsuite unit` |
| PHPStan (nivel 8) | este repo | `composer phpstan` |
| PHPCS (WordPress-Core/Extra/Docs) | este repo | `composer phpcs` (corrección automática: `vendor/bin/phpcbf`) |
| Jest | este repo | `npm test` |
| Build de assets | este repo | `npm run build` |
| Zip de producción | este repo | `bin/build-zip.sh` |
| Verificar el zip | este repo | `bin/verify-zip.sh lb-simple-podcast.zip` |
| Regenerar traducciones | `../../server` | `lando wp i18n make-pot /app/wordpress/wp-content/plugins/simple-podcast /app/wordpress/wp-content/plugins/simple-podcast/languages/lb-simple-podcast.pot --slug=lb-simple-podcast --exclude=vendor,node_modules,tests,build` |

## Verificación manual del feed

```bash
# Cabeceras (HEAD): Content-Type, ETag y Last-Modified.
curl -k -I https://plugins.lndo.site/feed/podcast/

# Primeras líneas del XML y validación de sintaxis.
curl -k -s https://plugins.lndo.site/feed/podcast/ | head -n 25
curl -k -s https://plugins.lndo.site/feed/podcast/ | xmllint --noout - && echo "XML OK"

# Caché condicional: debe responder 304.
ETAG=$(curl -k -s -D - -o /dev/null https://plugins.lndo.site/feed/podcast/ | grep -i '^etag' | cut -d' ' -f2- | tr -d '\r')
curl -k -s -o /dev/null -w '%{http_code}\n' -H "If-None-Match: $ETAG" https://plugins.lndo.site/feed/podcast/
```

Apache puede agregar `-gzip` al ETag de las respuestas comprimidas; el plugin lo tiene en cuenta al comparar.

## Verificación de la REST API

```bash
curl -k -s -u "usuario:application password" \
  "https://plugins.lndo.site/wp-json/wp/v2/posts/ID?_fields=id,audio_data,meta"
```

Para asociar un audio por REST: `POST /wp-json/wp/v2/posts/ID` con `{"meta":{"_lb_sp_audio_id": ATTACHMENT_ID}}`.

## Probar el paquete de producción

```bash
bin/build-zip.sh                      # crea lb-simple-podcast.zip (y lo verifica)
```

```bash
# En ../../server (desactiva primero la copia de desarrollo para evitar clases duplicadas):
lando wp plugin deactivate simple-podcast
lando wp plugin install /app/wordpress/wp-content/plugins/simple-podcast/lb-simple-podcast.zip --activate
# ... pruebas ...
lando wp plugin deactivate lb-simple-podcast && lando wp plugin delete lb-simple-podcast
lando wp plugin activate simple-podcast
```

## Probar la ability MCP
Activar `mcp-adapter` (`lando wp plugin activate mcp-adapter`) y seguir el ejemplo de [mcp-tool-schema.md](mcp-tool-schema.md). Para una URL de prueba que apunte al propio Lando (red privada) se puede permitir el host con el filtro `lb_sp_audio_url_allowlist`.
