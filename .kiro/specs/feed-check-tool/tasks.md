# Implementation Plan

- [x] 1. Preparar la estructura, la configuración de Jest y el script de npm
  - Crear la carpeta `scripts/check-feed/` y el punto de entrada delgado `scripts/check-feed.js` (`'use strict'`, CommonJS, `argv` → `cli` → `runner` → `report` → `process.exit`)
  - Agregar `"check:feed": "node scripts/check-feed.js"` a `package.json`, sin dependencias nuevas
  - Ampliar `testMatch` de `jest.config.js` con `'<rootDir>/scripts/check-feed/**/*.test.js'`; cada prueba de la herramienta declara `/** @jest-environment node */`
  - Agregar `scripts/` al patrón `forbidden` de `bin/verify-zip.sh` sin alterar el resto del script, y confirmar que `scripts/` ya figura en `.distignore`
  - _Requirements: 10.2, 10.4_

- [x] 2. Implementar el parseo de argumentos (`cli.js`)
  - [x] 2.1 Implementar `parseArgs(argv, env, isTTY)` con `node:util.parseArgs` (`strict: true`)
    - Opciones: `--json`, `--allow-private`, `--limit` (defecto 50), `--timeout` (30), `--max-redirects` (5), `--max-bytes` (104857600), `--check-cache-control`, `--no-color`, `-h/--help`
    - Devolver `{ ok: true, options }`, `{ ok: true, help: true }` o `{ ok: false, message }` con mensajes en español
    - Color solo si stdout es TTY y `NO_COLOR` no está definida
    - Texto de ayuda con uso, opciones, valores por defecto, constantes fijas (timeout de conexión 10 s, feed máximo 10 MiB) y códigos de salida 0, 1 y 2
    - _Requirements: 1.1, 1.2, 1.5, 8.8, 9.5_

  - [x] 2.2 Escribir pruebas de `cli`
    - Tabla de entradas válidas e inválidas (sin URL, opción desconocida, valor no numérico, más de una URL), valores por defecto y `--help`
    - _Requirements: 1.1, 1.2, 1.5_

- [x] 3. Implementar la validación de URL y la protección SSRF (`url-guard.js`)
  - [x] 3.1 Implementar `validateInputUrl`, `assertSafeProtocol` e `isPrivateAddress`
    - Rechazar esquemas distintos de `http`/`https` y URLs con credenciales
    - `isPrivateAddress` cubre los rangos IPv4 e IPv6 del diseño, incluidas direcciones IPv4 mapeadas
    - _Requirements: 1.3, 1.4, 9.1, 9.4_

  - [x] 3.2 Implementar `resolveSafe(hostname, { allowPrivate, lookup })`
    - Literal IP evaluado directamente; si no, `dns.promises.lookup(host, { all: true, verbatim: true })`
    - Bloquear si cualquiera de las direcciones es privada; devolver la primera pública; con `allowPrivate` omitir el chequeo
    - Exportar `BlockedHostError` y `BlockedProtocolError`; `lookup` inyectable
    - _Requirements: 9.1, 9.2, 9.3_

  - [x] 3.3 Escribir pruebas de `url-guard`
    - Una lista por cada rango privado (IPv4, IPv6 y mapeada), registros DNS mixtos y `allowPrivate`
    - _Requirements: 9.1, 9.2, 9.3, 9.4_

- [x] 4. Implementar el parser XML mínimo y estricto (`xml.js`)
  - [x] 4.1 Implementar `parse(text)` y los helpers `child`, `children` y `text`
    - Soportar declaración XML, comentarios, CDATA, atributos, entidades predefinidas y numéricas, y resolución de namespaces por URI
    - Rechazar con `XmlError(message, line, column)`: `DOCTYPE`, `ENTITY`, entidades no predefinidas, etiquetas mal anidadas o sin cerrar, más de una raíz, atributos duplicados, caracteres de control y entrada vacía
    - Profundidad máxima 100 con pila explícita (sin recursión)
    - _Requirements: 3.1, 3.5, 6.1_

  - [x] 4.2 Escribir pruebas de `xml`
    - Documentos bien formados con entidades, CDATA y namespaces; `&amp;` decodificado a `&`
    - Documentos malformados y hostiles (billion laughs con `DOCTYPE`, entidad externa, profundidad mayor que 100) que lanzan `XmlError` rápido
    - _Requirements: 3.1, 3.5, 6.1_

- [x] 5. Implementar el cliente HTTP (`http-client.js`)
  - [x] 5.1 Implementar `createClient` y `request` con `node:http`/`node:https`
    - Validar protocolo y resolver con `resolveSafe` en cada salto; conectar a la IP validada mediante la opción `lookup` del socket
    - `agent: false`; cabeceras `User-Agent: lb-simple-podcast-check-feed/<versión de package.json>`, `Accept: */*`, `Accept-Encoding: identity`; sin `Cookie` ni `Authorization`
    - Timeout de conexión fijo (10 s) y timeout total por llamada; mapear errores de red a `HttpError` con código estable (`dns`, `refused`, `tls`, `timeout-connect`, `timeout-total`, `reset`, `other`) y mensaje en español
    - Respuesta con `finalUrl`, `redirects`, `status`, `headers` en minúsculas, `bytesRead`, `truncated`, `head` (primeros 512 bytes) y `body` solo con `keepBody`
    - Límite de bytes por llamada (`maxBytes`), `HttpError('feed-too-large')` para el feed (`maxFeedBytes`, 10 MiB por defecto) y lectura de 4096 bytes como máximo en `GET` con `Range`
    - Redirecciones 301/302/303/307/308 con `Location` relativo, hasta `maxRedirects`, revalidando cada destino
    - _Requirements: 2.1, 2.2, 2.4, 2.5, 6.10, 9.1, 9.2, 9.4, 9.6, 9.7_

  - [x] 5.2 Crear `test-server.js` (servidor HTTP local de prueba)
    - `http.createServer` en `127.0.0.1:0` con las rutas del diseño: `/feed-ok.xml`, `/audio/ok-N.mp3`, `/audio/html-on-get.mp3` (HEAD audio y GET HTML, reproduce el bug de `enclosure-url-returns-html`), `/audio/html-body-audio-ct.mp3`, `/audio/wrong-length.mp3`, `/audio/status-404.mp3`, `/audio/status-500.mp3`, `/audio/no-ranges.mp3`, `/audio/bad-content-range.mp3`, `/audio/wrong-type.mp3`, `/audio/big.mp3`, `/audio/no-store.mp3`, `/redirect-private`, `/redirect-loop`, `/slow`, `/feed-invalid.xml`, `/feed-doctype.xml`, `/feed-no-namespaces.xml`, `/feed-no-items.xml`, `/feed-dup-guid.xml`, `/feed-404`, `/feed-huge.xml`
    - Registrar método, URL y cabeceras recibidas de cada petición; cierre limpio en `afterAll`
    - _Requirements: 10.3_

  - [x] 5.3 Escribir pruebas de `http-client`
    - Redirecciones (límite, bucle, relativas, destino privado), truncamiento por `maxBytes`, `maxFeedBytes`, timeout de conexión y total (`/slow`), `Accept-Encoding: identity`, `User-Agent` y ausencia de cookies
    - Con `allowPrivate: false` el servidor local (loopback) se bloquea y no recibe ninguna petición
    - _Requirements: 2.1, 2.2, 2.4, 2.5, 6.10, 9.1, 9.4, 9.6, 9.7_

- [x] 6. Implementar los validadores de feed y episodios
  - [x] 6.1 Implementar `feed-validator.js`
    - Raíz `rss` con `version="2.0"`; namespaces iTunes, content y atom por URI (un Fallo por cada ausente); `channel` con `title`; sin `item` es Advertencia
    - Extraer `title`, `generator`, `lastBuildDate` (validar RFC 822) e imagen (`itunes:image@href` o `image/url`); campos ausentes como Advertencia
    - Cada `Check` con `id`, `scope`, `result`, `message` en español, `expected` y `actual` según el modelo de datos
    - _Requirements: 3.2, 3.3, 3.4, 3.6, 4.1, 4.2, 4.3_

  - [x] 6.2 Implementar `item-validator.js`
    - Extraer `title`, `guid`, `pubDate`, `itunes:duration` y `url`, `length`, `type` del enclosure
    - Fallo: falta `title`, `guid` o `enclosure`; `length` que no cumple `/^[1-9]\d*$/`; `type` que no es `audio/*`; enclosure sin `url`; `guid` duplicado (adjunto a cada episodio involucrado)
    - Advertencia: falta `pubDate` o `itunes:duration`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [x] 6.3 Escribir pruebas de `feed-validator` e `item-validator`
    - Mutaciones de un feed válido que quitan raíz, namespaces, `channel` o `title` (un Fallo por cada elemento quitado); feed sin `item`; campos ausentes según la tabla de severidad; `guid` duplicado; `length` y `type` inválidos
    - _Requirements: 3.2, 3.3, 3.4, 3.6, 4.1, 4.2, 4.3, 5.1, 5.2, 5.3, 5.4, 5.5_

- [x] 7. Implementar la comprobación de enclosures (`enclosure-checker.js`)
  - [x] 7.1 Implementar `checkEnclosure(client, item, options)`
    - Tres peticiones secuenciales por episodio con la URL decodificada: `HEAD`, `GET` completo y `GET` con `Range: bytes=0-99`
    - Fallos: estado de HEAD/GET distinto de 200 y de Range distinto de 206; `Content-Type` (parte anterior a `;`, en minúsculas) distinto del `type` del feed; bytes del GET distintos de `length`; `Content-Length` de HEAD distinto de `length`; HEAD sin `Accept-Ranges: bytes`; Range sin 100 bytes o con `Content-Range` distinto de `bytes 0-99/<length>`
    - GET truncado por `--max-bytes`: `Check` con `result: "info"` ("no se pudo verificar length por bytes") sin Fallo
    - URL bloqueada: un único Fallo `enclosure.blocked` sin peticiones; error de red en un método: Fallo `enclosure.<método>.network` y las demás peticiones continúan
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10, 9.1, 9.4_

  - [x] 7.2 Implementar la detección de HTML y la paridad GET vs HEAD
    - `Content-Type` `text/html` o `application/xhtml+xml` en cualquier respuesta: Fallo `html.content-type`
    - Firma en los primeros 512 bytes del GET y del Range (sin BOM ni espacios, en minúsculas: `<!doctype html`, `<html`, `<head`, `<body`): Fallo `html.signature`, sin falso positivo con `ID3` o sync MPEG
    - Paridad de estado, `Content-Type` normalizado y `Content-Length` entre GET y HEAD (omitir `Content-Length` si el GET no lo trae); el mensaje cita los valores de ambos métodos
    - Las demás comprobaciones se siguen ejecutando cuando falla la detección de HTML
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 7.3 Implementar la advertencia opcional de `Cache-Control`
    - Con `--check-cache-control`, `no-store` en cualquier respuesta es una Advertencia; sin la opción no se evalúa el encabezado
    - _Requirements: 7.4, 7.5_

  - [x] 7.4 Escribir pruebas de `enclosure-checker` con el servidor local
    - Enclosure válido, `html-on-get` (HEAD correcto y Fallo solo por el GET), `html-body-audio-ct`, `wrong-length`, estados 404/500, `no-ranges`, `bad-content-range`, `wrong-type`, `big` (truncado), `no-store` con y sin la opción
    - Afirmar con el registro del servidor: exactamente una petición `HEAD`, un `GET` y un `GET` con `Range`, solo métodos `GET` y `HEAD`, con `User-Agent` propio y sin `Cookie` ni `Authorization`
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10, 7.1, 7.2, 7.3, 7.4, 7.5, 9.6, 9.7, 10.1, 10.3_

- [x] 8. Implementar el informe y la orquestación
  - [x] 8.1 Implementar `report.js`
    - `buildResult` con el modelo de datos del diseño (feed, canal, episodios con `head`/`get`/`range`, `totals`, `exitCode`); `exitCode` 1 si `totals.failures > 0`, si no 0
    - `renderText(result, { color })`: canal primero, luego un bloque por episodio con el motivo de cada incumplimiento y un resumen de las comprobaciones OK, y al final los totales; ANSI solo si `color` es true
    - `renderJson(result)`: un único documento con `JSON.stringify(result, null, 2)`; stdout solo con el JSON y diagnósticos a stderr
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.6, 8.7, 8.8_

  - [x] 8.2 Implementar `runner.js` y completar `scripts/check-feed.js`
    - Descargar el feed (`keepBody`, `maxFeedBytes`), parsear, validar y comprobar la imagen con `HEAD` (2xx y `image/*`; host privado es Fallo); revisar hasta `--limit` episodios y, si hay más, reportar `totals.episodesSkipped` y la Advertencia `feed.limit`
    - Un Fallo del feed (red, estado distinto de 200, tamaño, XML, raíz o canal inválidos) corta la revisión de episodios pero produce un resultado completo con exit 1
    - Salida: errores de uso con exit 2 por stderr y stdout vacío, error interno inesperado con exit 2 (pila solo con `DEBUG=check-feed`) y SIGINT con exit 130 sin resultado parcial
    - _Requirements: 1.2, 1.3, 2.2, 2.3, 4.4, 8.5, 9.1, 9.5_

  - [x] 8.3 Escribir pruebas de `report` y `runner`
    - `report`: JSON válido con `JSON.parse` y estructura del modelo, totales, `exitCode` (0 con solo Advertencias), ausencia de ANSI con color desactivado
    - `runner`: casos obligatorios con el servidor local: feed válido (sin Fallos, exit 0), XML inválido y `DOCTYPE` (Fallo `feed.xml` sin revisar episodios), enclosure con GET=HTML y HEAD=audio, `length` incorrecto, estado distinto de 200/206, host privado bloqueado (también por redirección y por DNS simulado), feed 404, feed sin namespaces, feed sin `item`, `guid` duplicado, tope de episodios y comprobación de imagen
    - _Requirements: 3.1, 3.5, 4.4, 5.4, 7.3, 8.1, 8.2, 8.3, 8.4, 8.6, 8.8, 9.1, 9.5, 10.3_

- [x] 9. Escribir la prueba de proceso real
  - `spawnSync('node', ['scripts/check-feed.js', ...])` contra el servidor local: exit 0 con feed válido, exit 1 con un Fallo, exit 2 con error de uso (stdout vacío y mensaje por stderr), `--json` con stdout que contiene solo el documento JSON y `--help` con exit 0
  - _Requirements: 1.2, 1.5, 8.3, 8.4, 8.5, 8.6, 8.7, 10.3_

- [x] 10. Checkpoint - Ensure all tests pass
  - Ejecutar `npm test` (incluye las pruebas existentes de `assets/src` y las nuevas), `npm run lint:js` si existe en `package.json`, y confirmar que `phpcs` y `phpstan` siguen sin cambios porque no se tocó PHP
  - Confirmar con `git status` que solo cambiaron `scripts/check-feed*`, `package.json`, `package-lock.json` (si npm lo modifica), `jest.config.js` y `bin/verify-zip.sh`, y que `bin/verify-zip.sh` rechaza un zip con `scripts/`
  - Verificación manual, fuera de las pruebas automatizadas: `npm run check:feed -- https://lucasbonomo.com/feed/podcast/` y comparar con el estado conocido del sitio (4 episodios, generator `LB Simple Podcast 0.8.0`, sin Fallos)
  - Ensure all tests pass, ask the user if questions arise.
  - _Requirements: 10.1, 10.2, 10.3, 10.4_
