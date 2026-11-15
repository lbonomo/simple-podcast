# Requirements Document

## Introduction

Esta feature agrega una herramienta de línea de comandos para controlar el feed de podcast de una URL que se recibe como parámetro (por ejemplo `https://lucasbonomo.com/feed/podcast/`). Automatiza la revisión que hoy se hace a mano: descargar el feed, validar su estructura RSS 2.0 con los namespaces iTunes, content y atom, extraer los datos del canal y de cada episodio, y comprobar que cada `<enclosure>` responde correctamente a `HEAD`, `GET` y `GET` con `Range`.

La verificación de enclosures cubre el bug previo documentado en `.kiro/specs/enclosure-url-returns-html/`, donde `GET` devolvía la home en HTML mientras `HEAD` devolvía `audio/mpeg`.

La herramienta solo lee. No modifica el sitio ni el plugin.

## Glossary

- **Herramienta**: el script de línea de comandos descrito en esta spec.
- **Feed**: documento XML RSS 2.0 publicado en la URL recibida por parámetro.
- **Canal**: elemento `<channel>` del feed.
- **Episodio**: elemento `<item>` del canal.
- **Enclosure**: elemento `<enclosure>` de un episodio, con atributos `url`, `length` y `type`.
- **URL del enclosure**: valor del atributo `url` ya decodificado (`&amp;` pasa a `&`).
- **Host privado**: host que resuelve a una dirección loopback, privada, link-local o reservada.
- **Fallo**: incumplimiento de una comprobación que hace que la ejecución termine con código distinto de cero.
- **Advertencia**: incumplimiento de una comprobación opcional que se informa pero no cambia el código de salida.

## Requirements

### Requisito 1: Parámetro de URL y validación de entrada

**User Story:** Como mantenedor del plugin, quiero pasar la URL del feed como parámetro, para controlar cualquier feed sin editar el script.

#### Criterios de Aceptación

1. WHEN la Herramienta se ejecuta con una URL como argumento, THEN the system SHALL usar esa URL como feed a controlar.
2. WHEN la Herramienta se ejecuta sin URL, THEN the system SHALL mostrar un mensaje de uso en español y terminar con código distinto de cero sin realizar ninguna petición.
3. WHEN el parámetro no es una URL válida con esquema `http` o `https`, THEN the system SHALL informar el error de entrada y terminar con código distinto de cero sin realizar ninguna petición.
4. WHEN el parámetro incluye credenciales en la URL (`usuario:clave@host`), THEN the system SHALL rechazarlo con un mensaje de error.
5. WHEN se ejecuta con la opción de ayuda, THEN the system SHALL mostrar el uso, las opciones y los códigos de salida y terminar con código cero.

### Requisito 2: Descarga del feed

**User Story:** Como mantenedor, quiero que la herramienta descargue el feed con manejo de errores, para distinguir un problema de red de un problema del contenido.

#### Criterios de Aceptación

1. WHEN la Herramienta descarga el feed, THEN the system SHALL aplicar un timeout de conexión y un timeout total de la petición.
2. WHEN la descarga falla por red (DNS, conexión rechazada, TLS o timeout), THEN the system SHALL informar la causa y terminar con código distinto de cero.
3. WHEN el servidor responde con un estado HTTP distinto de 200 para el feed, THEN the system SHALL informar el estado recibido como Fallo y no continuar con las comprobaciones de episodios.
4. WHEN el feed responde con redirecciones, THEN the system SHALL seguirlas hasta un máximo configurable y validar cada destino con las reglas del Requisito 9.
5. WHEN el cuerpo del feed supera un tamaño máximo configurable, THEN the system SHALL abortar la descarga e informarlo como Fallo.

### Requisito 3: Validación estructural del feed

**User Story:** Como mantenedor, quiero confirmar que el feed es RSS 2.0 válido con los namespaces esperados, para detectar feeds que los directorios de podcasts rechazarían.

#### Criterios de Aceptación

1. WHEN el cuerpo descargado no es XML bien formado, THEN the system SHALL informar el error de parseo como Fallo y terminar con código distinto de cero.
2. WHEN el elemento raíz no es `<rss>` con `version="2.0"`, THEN the system SHALL informarlo como Fallo.
3. WHEN el documento no declara los namespaces iTunes, content y atom, THEN the system SHALL informar como Fallo cada namespace ausente.
4. WHEN el feed no contiene `<channel>`, o el canal carece de `<title>`, THEN the system SHALL informarlo como Fallo.
5. WHEN el XML contiene una declaración DOCTYPE o entidades externas, THEN the system SHALL rechazar el documento sin resolver las entidades.
6. WHEN el canal no contiene ningún `<item>`, THEN the system SHALL informarlo como Advertencia.

### Requisito 4: Datos del canal

**User Story:** Como mantenedor, quiero ver los datos principales del canal, para identificar qué sitio y qué versión del plugin generó el feed.

#### Criterios de Aceptación

1. WHEN el feed es válido, THEN the system SHALL extraer y mostrar el título, el `generator` (por ejemplo "LB Simple Podcast 0.8.0"), el `lastBuildDate` y la imagen del canal.
2. WHEN falta `generator`, `lastBuildDate` o la imagen del canal, THEN the system SHALL mostrar el campo como ausente e informarlo como Advertencia.
3. WHEN `lastBuildDate` está presente y no es una fecha RFC 822 válida, THEN the system SHALL informarlo como Advertencia.
4. WHEN el feed declara una imagen del canal, THEN the system SHALL comprobar con una petición `HEAD` que responde con estado 2xx y un `Content-Type` de imagen, e informar el resultado.

### Requisito 5: Datos de cada episodio

**User Story:** Como mantenedor, quiero ver los datos de cada episodio, para detectar episodios con metadatos incompletos.

#### Criterios de Aceptación

1. WHEN el feed contiene episodios, THEN the system SHALL extraer de cada `<item>` el `title`, el `guid`, el `pubDate`, el `itunes:duration` y los atributos `url`, `length` y `type` del `<enclosure>`.
2. WHEN a un episodio le falta `title`, `guid` o `<enclosure>`, THEN the system SHALL informarlo como Fallo de ese episodio.
3. WHEN a un episodio le falta `pubDate` o `itunes:duration`, THEN the system SHALL informarlo como Advertencia de ese episodio.
4. WHEN dos episodios comparten el mismo `guid`, THEN the system SHALL informarlo como Fallo.
5. WHEN el atributo `length` del enclosure no es un entero positivo, o el `type` no es un tipo MIME `audio/*`, THEN the system SHALL informarlo como Fallo de ese episodio.

### Requisito 6: Comprobación HTTP de cada enclosure

**User Story:** Como mantenedor, quiero verificar cada enclosure con `HEAD`, `GET` y `GET` con `Range`, para asegurar que los reproductores y directorios reciben audio.

#### Criterios de Aceptación

1. WHEN la Herramienta comprueba un enclosure, THEN the system SHALL usar la URL del enclosure con `&amp;` decodificado a `&`.
2. WHEN la Herramienta comprueba un enclosure, THEN the system SHALL enviar una petición `HEAD`, una petición `GET` completa y una petición `GET` con el encabezado `Range: bytes=0-99`.
3. WHEN la respuesta a `HEAD` o `GET` completa no tiene estado 200, THEN the system SHALL informarlo como Fallo indicando el estado recibido.
4. WHEN la respuesta al `GET` con `Range` no tiene estado 206, THEN the system SHALL informarlo como Fallo indicando el estado recibido.
5. WHEN el `Content-Type` de una respuesta (sin parámetros como `charset`) difiere del `type` declarado en el feed, THEN the system SHALL informarlo como Fallo indicando ambos valores.
6. WHEN la cantidad de bytes del cuerpo del `GET` completo difiere del `length` declarado en el feed, THEN the system SHALL informarlo como Fallo indicando ambos valores.
7. WHEN el `Content-Length` de la respuesta a `HEAD` difiere del `length` declarado, THEN the system SHALL informarlo como Fallo.
8. WHEN la respuesta a `HEAD` no incluye `Accept-Ranges: bytes`, THEN the system SHALL informarlo como Fallo.
9. WHEN la respuesta a `GET` con `Range` no tiene 100 bytes de cuerpo, o su `Content-Range` no corresponde a `bytes 0-99/<length>`, THEN the system SHALL informarlo como Fallo.
10. WHEN un enclosure supera el tamaño máximo de descarga configurable, THEN the system SHALL cortar la lectura del `GET` completo, informar que no se pudo verificar `length` por bytes y no marcarlo como Fallo por esa causa.

### Requisito 7: Detección de respuestas HTML y discrepancias GET vs HEAD

**User Story:** Como mantenedor, quiero detectar que el `GET` devuelva HTML mientras `HEAD` devuelve audio, para no repetir el bug de `enclosure-url-returns-html`.

#### Criterios de Aceptación

1. WHEN el `Content-Type` de cualquier respuesta del enclosure es `text/html` o `application/xhtml+xml`, THEN the system SHALL informarlo como Fallo.
2. WHEN el cuerpo de una respuesta `GET` del enclosure comienza con `<!DOCTYPE html`, `<html` o un marcador equivalente de HTML, THEN the system SHALL informarlo como Fallo aunque el `Content-Type` declare audio.
3. WHEN el estado, el `Content-Type` o el `Content-Length` de `GET` difieren de los de `HEAD`, THEN the system SHALL informar la discrepancia como Fallo indicando los valores de ambos métodos.
4. WHEN el usuario activa la comprobación opcional de `Cache-Control` y la respuesta de un enclosure incluye `no-store`, THEN the system SHALL informarlo como Advertencia.
5. WHEN el usuario no activa la comprobación opcional de `Cache-Control`, THEN the system SHALL no evaluar ese encabezado.

### Requisito 8: Salida y código de salida

**User Story:** Como mantenedor, quiero una salida legible y un código de salida fiable, para usar la herramienta a mano y en automatizaciones.

#### Criterios de Aceptación

1. WHEN la ejecución termina, THEN the system SHALL imprimir primero los datos del canal y luego un resumen por episodio con el resultado de cada comprobación (OK, Advertencia o Fallo) y el motivo de cada incumplimiento.
2. WHEN la ejecución termina, THEN the system SHALL imprimir un total de episodios revisados, Fallos y Advertencias.
3. WHEN no hay ningún Fallo, THEN the system SHALL terminar con código de salida 0, aunque existan Advertencias.
4. WHEN hay al menos un Fallo, THEN the system SHALL terminar con un código de salida distinto de cero.
5. WHEN el error es de uso o de entrada, THEN the system SHALL usar un código de salida distinto del usado para Fallos de la comprobación.
6. WHEN el usuario activa la opción de salida JSON, THEN the system SHALL imprimir en la salida estándar un único documento JSON válido con los datos del canal, los episodios, cada comprobación con su resultado, y los totales, sin texto adicional en la salida estándar.
7. WHEN se imprimen mensajes de diagnóstico o errores, THEN the system SHALL enviarlos a la salida de error estándar.
8. IF la salida se dirige a una terminal sin soporte de color o el usuario lo desactiva, THEN the system SHALL imprimir texto plano sin secuencias de escape.

### Requisito 9: Seguridad (SSRF) y límites

**User Story:** Como mantenedor, quiero que la herramienta no pueda usarse para alcanzar servicios internos, para que ejecutarla sobre un feed no confiable sea seguro.

#### Criterios de Aceptación

1. WHEN el host de la URL del feed, de una redirección, de la imagen del canal o de un enclosure es un Host privado, THEN the system SHALL no realizar la petición e informarlo como Fallo.
2. WHEN el host se resuelve por DNS, THEN the system SHALL validar la dirección resuelta y conectarse a esa misma dirección, para evitar cambios de resolución entre la validación y la conexión.
3. WHEN el usuario activa la opción explícita para permitir hosts privados, THEN the system SHALL permitir peticiones a Hosts privados.
4. WHEN una URL de enclosure, de imagen o de redirección usa un esquema distinto de `http` o `https`, THEN the system SHALL no realizar la petición e informarlo como Fallo.
5. WHEN el feed contiene muchos episodios, THEN the system SHALL permitir limitar la cantidad de episodios revisados mediante una opción, y SHALL aplicar un tope por defecto documentado.
6. WHEN la Herramienta envía peticiones, THEN the system SHALL identificarse con un `User-Agent` propio que incluya el nombre de la herramienta.
7. WHEN la Herramienta realiza peticiones, THEN the system SHALL no enviar cookies ni credenciales.

### Requisito 10: Alcance y pruebas

**User Story:** Como mantenedor, quiero que la herramienta sea de solo lectura y esté probada, para confiar en sus resultados sin riesgo para el sitio.

#### Criterios de Aceptación

1. WHEN la Herramienta se ejecuta, THEN the system SHALL realizar únicamente peticiones `GET` y `HEAD` y no modificar el sitio, el plugin ni el sistema de archivos del proyecto.
2. WHEN se agrega la Herramienta, THEN the system SHALL no modificar el código del plugin ni empaquetar la Herramienta en el zip de producción.
3. WHEN se prueba la Herramienta, THEN the system SHALL contar con pruebas automatizadas que usen un servidor o respuestas simuladas, sin depender de un sitio externo, y que cubran al menos: feed válido, XML inválido, enclosure que devuelve HTML en `GET` con `HEAD` correcto, `length` incorrecto, estado distinto de 200/206 y host privado bloqueado.
4. WHEN se documenta la Herramienta, THEN the system SHALL describir su invocación y opciones en el propio mensaje de ayuda y en un script registrado en el manifiesto del proyecto elegido (ver Supuestos abiertos).

## Supuestos abiertos (a revisar por el usuario)

Estos puntos no se decidieron y quedan para confirmar antes del diseño. Los valores propuestos son una sugerencia.

1. **Lenguaje de implementación.** Propuesta: Node.js (`>=26` según `.nvmrc` y `engines`), sin dependencias nuevas, usando `fetch` y un parser XML ya disponible o mínimo. Alternativas: PHP 8.1 (como `scripts/list-audio-logs.php` y `composer.json`, pero ese script depende de WP-CLI y esta herramienta no necesita WordPress) o Bash con `curl` (como `bin/*.sh`, pero con validación XML y JSON más débil).
2. **Ubicación del script.** Propuesta: `scripts/check-feed.js`, con prueba junto a las existentes de Jest. Alternativa: `bin/check-feed.sh`. En ambos casos se debe excluir del zip de producción (revisar `.distignore` y `bin/verify-zip.sh`).
3. **Invocación.** Propuesta: script en `package.json` `"check:feed": "node scripts/check-feed.js"`, usado como `npm run check:feed -- <url> [opciones]`, a la manera de `npm run logs -- [cantidad]`.
4. **Nombres de flags.** Propuesta: `--json`, `--allow-private`, `--limit <n>`, `--timeout <segundos>`, `--max-redirects <n>`, `--max-bytes <n>`, `--check-cache-control`, `--no-color`, `-h/--help`.
5. **Códigos de salida.** Propuesta: 0 sin Fallos, 1 con Fallos de la comprobación, 2 error de uso o de entrada.
6. **Valores por defecto.** Propuesta: timeout de conexión 10 s y total 30 s por petición, 5 redirecciones, tope de 50 episodios, tamaño máximo de descarga por enclosure 100 MiB y del feed 10 MiB.
7. **Cache-Control.** El pedido lo marca como opcional. Se propone que solo se evalúe con `--check-cache-control` y que la presencia de `no-store` se informe como Advertencia; confirmar si `no-store` es lo deseado o lo no deseado.
8. **Descarga completa del GET.** Verificar `length` por bytes exige descargar todo el audio de cada episodio. Confirmar si es aceptable por defecto o si conviene una opción `--no-full-get` para saltarla.
9. **Severidad.** La clasificación Fallo/Advertencia de cada comprobación es una propuesta (por ejemplo, `Accept-Ranges` ausente como Fallo).
