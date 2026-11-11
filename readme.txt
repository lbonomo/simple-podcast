=== LB Simple Podcast ===
Contributors: lbonomo
Tags: podcast, rss, audio, mp3, mcp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Asocia un MP3 a cada entrada y publica un feed RSS de podcast compatible con Apple Podcasts, con SEO y una ability MCP.

== Description ==

LB Simple Podcast convierte tus entradas en episodios de podcast sin instalar un sistema aparte.

* **Audio por entrada:** panel en la barra lateral de Gutenberg (y metabox en el editor clásico) para elegir un MP3 de la biblioteca de medios. Solo se aceptan archivos `audio/mpeg`; se valida el contenido real, el tamaño y la duración.
* **Feed RSS para podcatchers:** `/feed/podcast/` (o `?feed=podcast`) con RSS 2.0 y etiquetas iTunes, `enclosure`, GUID persistente, fechas RFC 2822, `ETag` / `Last-Modified` y respuestas 304.
* **Ajustes del canal:** Ajustes > Podcast (título, descripción, idioma, imagen, categoría de Apple, autor, propietario, contenido explícito y copyright).
* **REST API:** el campo `audio_data` en `wp/v2/posts` (`attachment_id`, `url`, `mime_type`, `filesize`, `duration`).
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

= ¿Cómo uso la ability MCP? =

Activa el plugin MCP Adapter y llama a `lb-simple-podcast/publish-post-audio` con `post_id` y uno de `audio_url` o `audio_base64`. Hay un ejemplo en `docs/mcp-tool-schema.md` del repositorio.

= ¿Se borran mis audios al desinstalar? =

No. La desinstalación elimina solo las opciones del plugin; los archivos de la biblioteca y el meta de las entradas se conservan.

= ¿Funcionan las peticiones HEAD y de rango en los MP3? =

Dependen de tu servidor web; Apache y Nginx las admiten por defecto para archivos estáticos.

== Changelog ==

= 0.1.0 =
* Primera versión: audio por entrada, feed de podcast, ajustes del canal, SEO y ability MCP.
