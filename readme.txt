=== LB Simple Podcast ===
Contributors: lbonomo
Tags: podcast, rss, audio, mp3, mcp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Asocia un MP3 a cada entrada y publica un feed RSS de podcast compatible con Apple Podcasts, con SEO y una ability MCP.

== Description ==

Las solicitudes a los audios se registran automáticamente, incluyendo la IP observada, la URL completa y todas las cabeceras disponibles. Pueden contener cookies, tokens, credenciales y datos personales y se conservan sin límite hasta desinstalar. Consulte la documentación de arquitectura y evalúe las obligaciones de privacidad antes de usarlo. Los archivos originales públicos y cachés externas pueden seguir siendo accesibles sin pasar por el registro.

LB Simple Podcast convierte tus entradas en episodios de podcast sin instalar un sistema aparte.

* **Audio por entrada:** panel en la barra lateral de Gutenberg (y metabox en el editor clásico) para elegir un MP3 de la biblioteca de medios. Solo se aceptan archivos `audio/mpeg`; se valida el contenido real, el tamaño y la duración.
* **Feed RSS para podcatchers:** `/feed/podcast/` (o `?feed=podcast`) con RSS 2.0 y etiquetas iTunes, `enclosure`, GUID persistente, fechas RFC 2822, `ETag` / `Last-Modified` y respuestas 304.
* **Ajustes del canal:** Ajustes > Podcast (título, descripción, idioma, imagen, categoría de Apple, autor, propietario, contenido explícito y copyright).
* **REST API:** el campo `audio_data` en `wp/v2/posts` (`attachment_id`, `url`, `mime_type`, `filesize`, `duration`).
* **Reproductor en la entrada:** se agrega automáticamente un `<audio controls>` al inicio del contenido de las entradas con audio, independiente del tema. Va dentro de `<div class="lb-sp-player">` (el audio tiene la clase `lb-sp-player__audio`) para que el tema lo personalice con CSS. Ver las preguntas frecuentes.
* **SEO:** `og:audio` y JSON-LD `AudioObject` en las entradas con audio, sin duplicar lo que ya imprima otro plugin o tema.
* **Ability MCP:** `lb-simple-podcast/publish-post-audio` permite que un agente de IA adjunte un MP3 (por URL o base64) a una entrada. Requiere WordPress 6.9 o superior; el plugin MCP Adapter la publica como tool MCP.

= Seguridad =

* La descarga por URL solo admite http/https públicos (protección contra SSRF), con límite de tamaño y de tiempo.
* El máximo de tamaño del audio es el que permiten PHP y WordPress.
* Las operaciones comprueban permisos (`edit_post`, `upload_files`).

== Installation ==

1. Sube la carpeta `lb-simple-podcast` a `/wp-content/plugins/` o instala el zip desde Plugins > Añadir nuevo.
2. Activa el plugin.
3. Completa Ajustes > Podcast.
4. Edita una entrada, elige un MP3 en el panel "Podcast (audio MP3)" y publícala.
5. Envía la URL `/feed/podcast/` a tu plataforma de podcasts.

Si los enlaces permanentes no funcionan tras activar el plugin, visita Ajustes > Enlaces permanentes y guarda.

== Frequently Asked Questions ==

= ¿Qué formatos de audio se admiten? =

Solo MP3 (`audio/mpeg`).

= ¿Cómo personalizo el reproductor desde el tema? =

* **CSS:** estiliza `.lb-sp-player` y `.lb-sp-player__audio`. El CSS del plugin es mínimo y de baja especificidad.
* **Plantilla:** copia `templates/player.php` del plugin a `{tema}/lb-simple-podcast/player.php` y cambia el marcado. Recibe `$audio` (`url`, `mime_type`, `filesize`, `duration`, `attachment_id`) y `$post`.
* **Filtros:** `lb_sp_player_enabled` (bool, `$post`) para desactivarlo; `lb_sp_player_position` (`before` o `after`, `$post`); `lb_sp_player_html` (HTML, `$audio`, `$post`); `lb_sp_player_enqueue_styles` (bool) para no cargar el CSS del plugin.

Ejemplo: `add_filter( 'lb_sp_player_position', fn() => 'after' );`

= ¿Cómo uso la ability MCP? =

Activa el plugin MCP Adapter y llama a `lb-simple-podcast/publish-post-audio` con `post_id` y uno de `audio_url` o `audio_base64`. Hay un ejemplo en `docs/mcp-tool-schema.md` del repositorio.

= ¿Se borran mis audios al desinstalar? =

No. La desinstalación elimina las opciones y la tabla de registros del plugin; los archivos de la biblioteca, el meta de las entradas y las copias protegidas se conservan.

= ¿Funcionan las peticiones HEAD y de rango en los MP3? =

La ruta de entrega del plugin admite HEAD y rangos simples de bytes. Los archivos originales y las redirecciones a almacenamiento externo dependen del servidor que los sirve.

== Changelog ==

= 0.6.0 =
* Agrega una prueba E2E para publicar episodios con audio y verifica el reproductor y el feed.
* Añade un comando local para consultar los registros de solicitudes de audio.

= 0.5.0 =
* Registro de cada solicitud de audio en una tabla SQL propia con mantenimiento mediante WordPress.
* Ruta de entrega compatible con HEAD y rangos simples, integrada en el reproductor, feed, REST, SEO y MCP.
* Copias protegidas opcionales y avisos sobre accesos directos y almacenamiento de datos sensibles.

= 0.4.0 =
* Fija la rama principal para comprobar actualizaciones desde GitHub y cambia el nombre mostrado del plugin.

= 0.3.0 =
* Actualizaciones automáticas desde los releases de GitHub.

= 0.2.0 =
* Reproductor automático en las entradas con audio, personalizable desde el tema.

= 0.1.0 =
* Primera versión: audio por entrada, feed de podcast, ajustes del canal, SEO y ability MCP.
