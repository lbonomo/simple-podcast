# Documento de Requerimientos Técnicos y Arquitectura (PRD)

> **Estado:** implementado. Este documento recoge los requerimientos corregidos con las decisiones de [init-plan.md](init-plan.md). El diseño final está en [architecture.md](architecture.md), la tool MCP en [mcp-tool-schema.md](mcp-tool-schema.md) y el entorno en [setup.md](setup.md).
>
> **Cambios respecto a la versión original:** el plugin pasa a llamarse `lb-simple-podcast`; el meta es solo `_lb_sp_audio_id` (la URL se deriva); la integración MCP usa la Abilities API de WordPress (no existe `mcp_register_tool()`); se agregan ajustes del canal, anti-SSRF, límite de tamaño y reglas de caché del feed.

## 1. Identificación y Objetivos

* **Nombre del Plugin:** `LB Simple Podcast` (slug y text domain: `lb-simple-podcast`; namespace `LbSimplePodcast`; prefijo `lb_sp_`).
* **Objetivo principal:** Extender el post type nativo `post` para asociar un recurso de audio MP3, generar un feed RSS 2.0 compatible con especificaciones de Apple Podcasts / RSS estándar para podcatchers, inyectar metadatos Open Graph / Schema.org para SEO, y exponer una Tool MCP (Model Context Protocol) integrada con `mcp-adapter` para adjuntar y publicar audios programáticamente mediante LLMs.
* **Compatibilidad objetivo:** WordPress ≥ 6.4, PHP ≥ 8.1.

## 2. Requerimientos Funcionales Detallados

### 2.1. Gestión del Campo de Audio (Meta & Media)

* **Registro de Meta:** Registrar el meta key `_lb_sp_audio_id` (ID del attachment en Media Library) utilizando `register_post_meta()` con `show_in_rest => true`, sanitización estricta (`absint`) y `auth_callback` que exige `edit_post` (al ser un meta protegido con prefijo `_`, sin `auth_callback` no se podría editar por REST). La URL, el tamaño y la duración se derivan del attachment y no se guardan.
* **UI de Edición (Gutenberg nativo & Classic fallback):**
  * Integrar mediante un componente React en el panel lateral de Gutenberg (Document Sidebar Panel) utilizando `@wordpress/media-utils` (`MediaUpload`) con filtro de mime type restringido exclusivamente a `audio/mpeg` (`.mp3`).
  * Validar tamaño (máximo permitido por PHP/WordPress), duración (leída vía `wp_read_audio_metadata()`, cargando `wp-admin/includes/media.php` fuera del admin) y mime type real al guardar o asignar.
  * Respaldo en el editor clásico: metabox con selector de medios (oculto en Gutenberg).
* **Exposición en REST API:** El endpoint `wp/v2/posts` debe exponer el objeto `audio_data`:
  ```json
  "audio_data": {
    "attachment_id": 123,
    "url": "http://localhost:8888/wp-content/uploads/track.mp3",
    "mime_type": "audio/mpeg",
    "filesize": 15420310,
    "duration": 345
  }
  ```

### 2.2. Feed RSS para Podcatchers (Apple Podcasts / RSS 2.0 Spec)

* **Endpoint y Rewrite Rules:** Registrar el feed con `add_feed( 'podcast', ... )`, que habilita `sitio.com/feed/podcast/` y `?feed=podcast`; las rewrite rules se refrescan al activar/desactivar el plugin.
* **Criterio de Inclusión:** Consulta optimizada en `WP_Query` que filtre únicamente posts con estado `publish` donde `_lb_sp_audio_id` exista y sea numérico mayor a `0` (`meta_query` con `compare => '>'`, `value => 0`, `type => 'NUMERIC'`); se excluyen los posts con contraseña y se limita el número de episodios (filtro `lb_sp_feed_limit`, 100 por defecto).
* **Especificación XML:**
  * Declaración de namespaces obligatorios: `xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"`, `xmlns:content="http://purl.org/rss/1.0/modules/content/"`.
  * Nodo `<enclosure url="..." length="[bytes]" type="audio/mpeg" />` validado en cada `<item>`.
  * Inclusión de tags `<itunes:duration>`, `<itunes:explicit>`, `<itunes:summary>` y GUID persistente (`isPermaLink="false"`).
  * Cabecera HTTP `Content-Type: application/rss+xml; charset=UTF-8` con gestión correcta de cache `Last-Modified` y `ETag`, respuesta `304` a `If-None-Match` / `If-Modified-Since` e invalidación al publicar, editar, borrar o cambiar los ajustes.
  * Etiquetas de canal para Apple Podcasts: `title`, `description`, `language`, `itunes:image` (JPG/PNG cuadrada de 1400 a 3000 px), `itunes:category` (lista cerrada de Apple, capitalización exacta), `itunes:explicit`, `itunes:author`, `itunes:owner`, `itunes:type`. Fechas en RFC 2822 y entidades XML seguras (`&amp; &lt; &gt; &apos; &quot;`).
  * Los datos del canal se configuran en una página propia: **Podcast > Setup** (opción `lb_sp_channel`), con valores por defecto tomados del sitio.
  * Los archivos de audio deben servirse con HEAD y byte-range (responsabilidad del servidor web).

### 2.3. SEO y Metadatos HTML (`wp_head`)

* Si el post actual (`is_singular('post')`) tiene un audio asociado:
  * Inyectar tags Open Graph:
    * `<meta property="og:audio" content="[URL_AUDIO]" />`
    * `<meta property="og:audio:type" content="audio/mpeg" />`
  * Inyectar esquema JSON-LD (`AudioObject` o extensión de `BlogPosting` / `Article`):
    ```json
    {
      "@context": "https://schema.org",
      "@type": "AudioObject",
      "contentUrl": "[URL_AUDIO]",
      "encodingFormat": "audio/mpeg",
      "duration": "PT5M45S"
    }
    ```
  * Compatibilidad con plugins SEO (Yoast / Rank Math): ninguno emite `og:audio` de forma nativa. Para no duplicar etiquetas se inspecciona la salida de `wp_head` y solo se agrega lo que falte (`og:audio` o `AudioObject`). Filtros: `lb_sp_seo_output_og`, `lb_sp_seo_output_jsonld`, `lb_sp_seo_jsonld`. La integración con sus APIs (`wpseo_frontend_presenters`, `wpseo_schema_graph_pieces`, `rank_math/opengraph/facebook`) no se implementó ni se probó.

### 2.4. Integración con `mcp-adapter` (Model Context Protocol)

* **Mecanismo:** `mcp-adapter` convierte en tools MCP las *abilities* de la Abilities API de WordPress (≥ 6.9) marcadas con `meta.mcp.public = true`. No existe `mcp_register_tool()`. El registro se hace en `wp_abilities_api_init` solo si existe `wp_register_ability()`; la ability queda disponible aunque el adapter no esté activo. El plugin base sigue funcionando en WP ≥ 6.4.
* **Ability registrada:** `lb-simple-podcast/publish-post-audio` (categoría `podcast`). Detalle completo en [mcp-tool-schema.md](mcp-tool-schema.md).
  * **Esquema de entrada:** `post_id` (integer, requerido); exactamente uno de `audio_url` (http/https) o `audio_base64` (string, admite prefijo `data:`); `filename` y `title` (string, opcionales; `title` es el título del attachment).
  * **Tamaño máximo:** el que permitan PHP y WordPress (`min( wp_max_upload_size(), memoria disponible / 3 )`), también para base64 (se estima antes de decodificar).
  * **Seguridad de `audio_url`:** solo http/https sin credenciales, bloqueo de loopback y redes privadas (SSRF) con `wp_http_validate_url` y comprobación de IP propia, descarga con `wp_safe_remote_get` con tope de tamaño y timeout. Filtro `lb_sp_audio_url_allowlist` para hosts locales de prueba.
  * **Lógica de ejecución:**
    1. Validar permisos del usuario autenticado por MCP (`upload_files` y `edit_post` sobre el post).
    2. Descargar o decodificar a un archivo temporal y verificar el mime real (`wp_check_filetype_and_ext` + `finfo`).
    3. Crear el attachment con `media_handle_sideload()` (`post_parent` = post), que genera la metadata nativa (`wp_generate_attachment_metadata()`).
    4. Actualizar `_lb_sp_audio_id`; si había un audio creado por el plugin para ese post, se reemplaza y se borra el anterior (idempotente, sin huérfanos).
    5. Responder con `post_id`, `attachment_id`, `url`, `mime_type`, `filesize`, `duration`, `replaced_attachment_id`, `permalink` y `feed_url`.

## 3. Arquitectura del Código y Estructura de Archivos

Se adopta una arquitectura modular orientada a objetos con autocarga vía Composer (PSR-4), separando infraestructura de desarrollo, pruebas y documentación del código que se distribuye a producción.

```text
lb-simple-podcast/
├── .github/
│   └── workflows/
│       ├── test.yml                 # phpcs, phpstan, jest, phpunit (PHP 8.1-8.3), paquete
│       └── build-release.yml        # Genera el artifact .zip limpio en tags v*
├── assets/
│   ├── src/                         # React/JS para el sidebar de Gutenberg (+ tests Jest)
│   ├── build/                       # Assets compilados (@wordpress/scripts)
│   └── js/                          # JS del editor clásico y de los ajustes
├── bin/
│   ├── build-zip.sh
│   └── verify-zip.sh
├── docs/                            # Documentación técnica (no se distribuye)
│   ├── architecture.md
│   ├── mcp-tool-schema.md
│   ├── setup.md
│   ├── init-plan.md
│   └── requerimiento.md
├── inc/
│   ├── Admin/
│   │   ├── DashboardPage.php        # Estadísticas generales
│   │   ├── EditorAssets.php         # Script del sidebar de Gutenberg
│   │   ├── LogsPage.php             # Tabla de solicitudes de audio
│   │   ├── Menu.php                 # Menú Podcast (Dashboard, Setup, Logs)
│   │   ├── MetaBox.php              # Respaldo para el editor clásico
│   │   └── SettingsPage.php         # Ajustes del canal
│   ├── Feed/
│   │   ├── FeedRegistrar.php        # add_feed, cabeceras, invalidación
│   │   ├── FeedVersion.php
│   │   ├── PodcastFeedRenderer.php  # Query, datos, ETag/304
│   │   └── Xml.php
│   ├── Integrations/
│   │   ├── McpAbility.php           # Ability publish-post-audio
│   │   └── SeoHeaders.php           # Inyección de OG y JSON-LD
│   ├── Media/
│   │   ├── AudioIngestor.php        # Sideload desde URL o base64
│   │   └── AudioValidator.php       # Mime type, tamaño, duración
│   ├── Meta/
│   │   └── AudioMeta.php            # Meta _lb_sp_audio_id y campo REST audio_data
│   └── Plugin.php                   # Bootstrap / registro de servicios
├── languages/
├── templates/
│   └── feed-podcast.php             # Template del feed desacoplado
├── tests/
│   ├── phpstan/bootstrap.php
│   └── phpunit/
│       ├── bootstrap.php
│       ├── fixtures/sample.mp3
│       ├── Unit/AudioValidatorTest.php
│       └── Integration/             # Feed, meta, ingesta, SEO, MCP, ajustes, metabox, uninstall
├── .distignore                      # Archivos excluidos del .zip final
├── .gitignore
├── composer.json
├── package.json
├── phpcs.xml.dist
├── phpunit.xml.dist
├── phpstan.neon.dist
├── readme.txt
├── uninstall.php
└── lb-simple-podcast.php            # Entrypoint del plugin
```

## 4. Estrategia de Testing y Calidad

1. **PHPUnit (WP Test Suite):**
   * Pruebas de integración sobre una base de datos de test de WordPress (`wordpress_test`) levantada con wp-env: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit`.
   * Tests específicos:
     * El feed solo muestra posts con audio válido y responde con HTTP 200 y `Content-Type: application/rss+xml`.
     * Archivos que no sean `audio/mpeg` son rechazados al intentar guardarse en el meta.
     * La tool MCP devuelve error cuando el `post_id` no existe o no tiene permisos.
     * Rechazo de URLs hacia redes privadas (SSRF), de base64 inválido o demasiado grande y de archivos que no son MP3.
     * SEO: salida con y sin audio, sin duplicados; ajustes del canal; metabox clásico; desinstalación.
   * Jest para el componente del sidebar (`npm test`).
2. **Análisis Estático (PHPStan / Psalm):**
   * Nivel 8 en PHPStan con `szepeviktor/phpstan-wordpress`.
3. **Estándares de Código:**
   * PHP_CodeSniffer con reglas `WordPress-Core`, `WordPress-Extra`, y `WordPress-Docs`.

## 5. Estrategia de Empaquetado (.zip de producción limpio)

Para aislar completamente herramientas de desarrollo, documentación interna (`docs/`), tests y dotfiles, el proceso de empaquetado excluye estos elementos en el release final:

### 5.1. Archivo `.distignore`

Ver el archivo en la raíz. Excluye `.git`, `.github`, `docs/`, `/bin/`, `/build/`, `tests/`, `node_modules/`, `assets/src/`, los manifiestos (`composer.json`, `composer.lock`, `package*.json`) y la configuración de herramientas. **No** excluye `vendor/` (autoload de producción) ni `assets/build/`. Los patrones `/bin/` y `/build/` están anclados para no excluir `assets/build/`.

### 5.2. Script de Build / CI Pipeline

`bin/build-zip.sh` compila los assets, copia el proyecto a un directorio temporal, instala allí `composer install --no-dev --optimize-autoloader` (el `vendor/` de desarrollo del árbol de trabajo no se toca), aplica `.distignore` con `rsync` y genera `lb-simple-podcast.zip`. Luego ejecuta `bin/verify-zip.sh`, que falla si falta algún archivo necesario o si el paquete contiene `tests/`, `docs/`, `node_modules/` u otros archivos de desarrollo.

```bash
bin/build-zip.sh
bin/verify-zip.sh lb-simple-podcast.zip
```

## 6. Procedimiento de Pruebas en Servidor Local (wp-env)

Comandos para inicializar, verificar y probar en el entorno:

1. **Flush de Rewrite Rules:**
   ```bash
   npx wp-env run cli wp rewrite flush --hard
   ```
2. **Verificación de Feed vía CLI:**
   ```bash
   curl -I http://localhost:8888/feed/podcast
   curl -s http://localhost:8888/feed/podcast | head -n 25
   ```
3. **Prueba de Autenticación con Application Password:**
   ```bash
   curl -X GET "http://localhost:8888/wp-json/wp/v2/posts?_fields=id,audio_data" \
     -u "usuario:application password"
   ```
4. **Ejecución de Tests en el Contenedor:**
   ```bash
   npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit
   ```