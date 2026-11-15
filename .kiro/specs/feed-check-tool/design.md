# Design Document

## Overview

La feature agrega `scripts/check-feed.js`, una herramienta de línea de comandos que recibe la URL de un feed de podcast, lo descarga, valida su estructura RSS 2.0 (namespaces iTunes, content y atom), extrae los datos del canal y de cada episodio, y comprueba cada `<enclosure>` con `HEAD`, `GET` completo y `GET` con `Range: bytes=0-99`. Automatiza la revisión manual que destapó el bug de `.kiro/specs/enclosure-url-returns-html/`, donde `GET` devolvía la home en HTML y `HEAD` devolvía `audio/mpeg`.

La herramienta solo lee: usa únicamente `GET` y `HEAD`, no escribe en el sistema de archivos y no toca el plugin ni el sitio.

Los supuestos abiertos de `requirements.md` quedan aceptados tal como se propusieron. Las decisiones de diseño son:

| Tema | Decisión |
|------|----------|
| Lenguaje | Node.js `>=26` (`.nvmrc` y `engines`), solo módulos integrados (`node:http`, `node:https`, `node:dns`, `node:net`, `node:util`). Sin dependencias nuevas. |
| Ubicación | `scripts/check-feed.js` (punto de entrada delgado) y módulos en `scripts/check-feed/`. `scripts/` ya está en `.distignore`. |
| Invocación | `"check:feed": "node scripts/check-feed.js"` en `package.json`; uso `npm run check:feed -- <url> [opciones]`, igual que `npm run logs -- [cantidad]`. |
| Cliente HTTP | `node:http`/`node:https` en lugar de `fetch`, porque `fetch` (undici) no permite fijar la dirección IP validada ni medir el timeout de conexión por separado sin una dependencia. |
| Parser XML | Parser propio mínimo y estricto en `xml.js`. Node no incluye un parser XML, y agregar `fast-xml-parser` o `xml2js` contradice "sin dependencias nuevas". Ver la justificación en Components. |
| Códigos de salida | 0 sin Fallos, 1 con Fallos, 2 error de uso o entrada. |
| `GET` completo | Se descarga por defecto, con tope `--max-bytes`. No se agrega `--no-full-get`: `--limit` y `--max-bytes` ya acotan el costo y el requisito 6.10 define el comportamiento al superar el tope. |
| `Cache-Control` | Solo con `--check-cache-control`; `no-store` es una Advertencia. |

No se modifica código del plugin en esta fase. Las otras specs quedan fuera de alcance.

## Architecture

El flujo es lineal y cada módulo es una función pura o recibe sus dependencias por parámetro, para probarlo sin red.

```
check-feed.js (entrada)
  └─ cli.parseArgs(argv)            → opciones | error de uso (exit 2)
  └─ url-guard.validateInputUrl()   → URL | error de entrada (exit 2)
  └─ http-client.createClient(opts) → request(url, {method, headers, maxBytes, bodyLimit})
        └─ url-guard.assertSafeTarget(url)  (en cada petición y cada redirección)
  └─ runner.run(feedUrl, opts, client)
        1. descarga feed ──► xml.parse ──► feed-validator
        2. channel-validator (+ HEAD de la imagen)
        3. por episodio (hasta --limit): item-validator ─► enclosure-checker
  └─ report.buildResult(...)        → objeto resultado
  └─ report.renderText | renderJson → stdout
  └─ exit code                      → 0 | 1 | 2
```

Reglas de arquitectura:

- **Un único cliente HTTP.** Toda petición (feed, imagen, enclosure, cada redirección) pasa por `http-client.request`, que invoca la protección SSRF. No hay otro camino de red.
- **Los validadores no hacen red.** Reciben datos ya parseados y devuelven `Check[]`. Solo `enclosure-checker` y la comprobación de imagen usan el cliente, inyectado.
- **Stdout vs stderr.** El resultado (texto o JSON) va a stdout. Uso, errores de entrada y diagnósticos van a stderr (Req. 8.6 y 8.7).
- **Secuencial.** Los episodios se revisan uno a uno para no saturar al servidor ni distorsionar timeouts. El tope por defecto de 50 episodios mantiene el tiempo acotado.
- **Estado.** No hay estado global ni escritura en disco. La única entrada es `argv` y las variables de entorno `NO_COLOR` y `FORCE_COLOR`.

### Estructura de archivos

```
scripts/check-feed.js                  # entrada: argv → cli → runner → report → process.exit
scripts/check-feed/cli.js              # parseo de argumentos, ayuda
scripts/check-feed/url-guard.js        # validación de URL, DNS, rangos privados
scripts/check-feed/http-client.js      # petición con timeouts, límites y redirecciones
scripts/check-feed/xml.js              # parser XML mínimo y estricto
scripts/check-feed/feed-validator.js   # raíz, namespaces, canal
scripts/check-feed/item-validator.js   # campos de episodio y enclosure, guid duplicado
scripts/check-feed/enclosure-checker.js# HEAD, GET, Range, HTML, paridad
scripts/check-feed/report.js           # resultado, totales, texto y JSON
scripts/check-feed/runner.js           # orquestación
scripts/check-feed/*.test.js           # pruebas Jest
scripts/check-feed/test-server.js      # servidor HTTP local para pruebas
```

`scripts/` ya figura en `.distignore`, así que la herramienta no entra en el zip (Req. 10.2). `bin/verify-zip.sh` no lista `scripts/` en su patrón `forbidden`; la tarea de implementación debe agregarlo para que la verificación del zip lo compruebe, sin alterar el resto del script.

## Components and Interfaces

### cli.js (Req. 1, 8.8, 9.5)

`parseArgs(argv, env, isTTY)` usa `node:util.parseArgs` con `strict: true`.

| Opción | Tipo | Defecto |
|--------|------|---------|
| `<url>` (posicional, exactamente uno) | string | requerido |
| `--json` | boolean | `false` |
| `--allow-private` | boolean | `false` |
| `--limit <n>` | entero ≥ 1 | `50` |
| `--timeout <segundos>` | entero ≥ 1 (timeout total por petición) | `30` |
| `--max-redirects <n>` | entero ≥ 0 | `5` |
| `--max-bytes <n>` | entero ≥ 1 (tope de descarga por enclosure) | `104857600` (100 MiB) |
| `--check-cache-control` | boolean | `false` |
| `--no-color` | boolean | color solo si stdout es TTY y `NO_COLOR` no está definida |
| `-h`, `--help` | boolean | |

El timeout de conexión es fijo en 10 s (`CONNECT_TIMEOUT_MS`), el tamaño máximo del feed es fijo en 10 MiB (`MAX_FEED_BYTES`). Ambos son constantes exportadas de `http-client.js` y se documentan en la ayuda; Req. 2.5 pide "configurable", que se cumple con la constante exportada y la opción de la API interna `createClient({ maxFeedBytes })`, usada por las pruebas. No se agregan flags CLI para ellos porque los supuestos aceptados no los listan.

Devuelve `{ ok: true, options }`, `{ ok: true, help: true }` o `{ ok: false, message }`. Sin URL, URL no parseable, esquema distinto de `http`/`https` o credenciales (`username`/`password` no vacíos en `URL`) devuelven `ok: false` antes de cualquier petición. El mensaje de uso y la ayuda están en español y la ayuda lista opciones, valores por defecto y los códigos de salida 0, 1 y 2.

### url-guard.js (Req. 9, 1.3, 1.4)

- `validateInputUrl(text)` → `{ url } | { error }`. Rechaza esquemas no http/https y credenciales.
- `isPrivateAddress(ip)` → boolean. Cubre:
  - IPv4: `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24`, `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24`, `203.0.113.0/24`, `224.0.0.0/4`, `240.0.0.0/4`.
  - IPv6: `::`, `::1`, `fc00::/7`, `fe80::/10`, `ff00::/8`, `2001:db8::/32`, y direcciones IPv4 mapeadas (`::ffff:a.b.c.d`) evaluadas con las reglas IPv4.
- `resolveSafe(hostname, { allowPrivate, lookup })` → `{ address, family }` o lanza `BlockedHostError`.
  - Si el host es un literal IP (`net.isIP`), se evalúa directamente. La clase `URL` ya normaliza formas decimales y hexadecimales a la forma con puntos.
  - Si no, `dns.promises.lookup(host, { all: true, verbatim: true })`. **Si cualquiera** de las direcciones resueltas es privada, se bloquea (impide que un registro mixto elija la privada). Se usa la primera dirección pública.
  - Con `allowPrivate` el chequeo se omite pero la resolución se hace igual.
- `assertSafeProtocol(url)`: solo `http:` y `https:` (Req. 9.4).

`lookup` es un parámetro inyectable para que las pruebas simulen resoluciones sin DNS real.

### http-client.js (Req. 2, 6.10, 9)

`createClient({ timeoutMs, connectTimeoutMs, maxRedirects, allowPrivate, userAgent, lookup, maxFeedBytes })` devuelve `request(url, opts)`.

`request(url, { method, headers, maxBytes, keepBody, signal })` → `Promise<Response>`:

```
Response {
  finalUrl: string,
  redirects: string[],        // URLs intermedias
  status: number,
  headers: Record<string,string>,   // claves en minúsculas
  bytesRead: number,
  truncated: boolean,         // se alcanzó maxBytes y se cortó la lectura
  head: Buffer,               // primeros 512 bytes del cuerpo
  body?: Buffer               // solo si keepBody (feed, imagen no)
}
```

Mecanismo:

1. Por cada salto (inicial y redirecciones 301/302/303/307/308): `assertSafeProtocol(url)` y `resolveSafe(host)`.
2. Se abre la conexión con `http.request`/`https.request` pasando `lookup: (host, o, cb) => cb(null, address, family)` que devuelve **la dirección ya validada**. Así el socket se conecta a la misma IP que se comprobó (Req. 9.2) y no hay segunda resolución. TLS valida el certificado contra el nombre original (`servername` por defecto).
3. `agent: false` (sin keep-alive compartido), cabeceras fijas: `User-Agent: lb-simple-podcast-check-feed/<versión de package.json>`, `Accept: */*`, `Accept-Encoding: identity` (los bytes contados son los del audio, no los comprimidos). No se envían `Cookie` ni `Authorization`, y no se guardan `Set-Cookie` entre saltos (Req. 9.7).
4. Timeouts: `connectTimeoutMs` se arma al crear el socket y se cancela en el evento `connect` (`secureConnect` en HTTPS). El timeout total (`timeoutMs`) cubre desde el inicio hasta el fin del cuerpo y se implementa con un temporizador que destruye la petición. El total aplica por petición (por salto no se reinicia: es por llamada a `request`).
5. Límite de bytes: al llegar a `maxBytes` se hace `res.destroy()` y se devuelve `truncated: true`. Para el feed, superar `maxFeedBytes` lanza `HttpError('feed-too-large')`, que es un Fallo (Req. 2.5). Para enclosures es una lectura truncada, no un error (Req. 6.10).
6. `HEAD` no lee cuerpo. En `GET` con `Range` el límite de lectura es 4096 bytes: basta para detectar un cuerpo que no mide 100 bytes sin descargar un audio entero si el servidor ignora `Range`.
7. Redirecciones: más de `maxRedirects` lanza `HttpError('too-many-redirects')`. El `Location` relativo se resuelve con `new URL(location, current)`. Una redirección a un host privado o esquema no permitido lanza `BlockedHostError`/`BlockedProtocolError`.
8. Errores de red se mapean a `HttpError` con `code` estable (`dns`, `refused`, `tls`, `timeout-connect`, `timeout-total`, `reset`, `other`) y un mensaje en español para el informe (Req. 2.2).

### xml.js (Req. 3.1, 3.5)

`parse(text)` → árbol `{ name, prefix, local, attrs, ns, children, text }` o lanza `XmlError(message, line, column)`.

**Elección del parser.** Node no trae parser XML. Las opciones son una dependencia (`fast-xml-parser`, `xml2js`, `sax`), que contradice el supuesto "sin dependencias nuevas", o un parser propio. Se elige el propio porque el alcance es acotado (RSS) y porque el requisito 3.5 pide rechazar `DOCTYPE` y entidades externas, que en un parser propio se cumple por construcción: el tokenizador no implementa DTD ni entidades definidas por el usuario. El costo es mantener ~200 líneas de código con pruebas dedicadas.

Alcance del parser:

- Soporta: declaración `<?xml ... ?>`, comentarios, CDATA, elementos, atributos con comillas simples o dobles, texto, entidades predefinidas (`&amp; &lt; &gt; &quot; &apos;`) y numéricas (`&#10;`, `&#x1F;`), y resolución de namespaces por `xmlns` y `xmlns:prefijo` con ámbito por elemento.
- Rechaza con `XmlError`: `<!DOCTYPE`, `<!ENTITY`, cualquier entidad con nombre no predefinido, etiquetas sin cerrar o mal anidadas, más de un elemento raíz, texto fuera de la raíz distinto de espacios, atributos duplicados, caracteres de control no permitidos, entrada vacía.
- Profundidad máxima 100 y no recursión sin tope (se usa pila explícita), para que un documento hostil no agote la pila.
- El valor de atributos y texto se devuelve decodificado, de modo que `url="...&amp;..."` entrega `&` (Req. 6.1).

Helpers: `child(node, local, nsUri)`, `children(node, local, nsUri)` y `text(node)`, que buscan por URI de namespace y no por prefijo, de modo que un feed con prefijo `itunes` redefinido igual se valida.

### feed-validator.js (Req. 3, 4)

`validateFeed(root)` → `{ checks: Check[], channel: ChannelData | null, items: Node[] }`.

- Raíz `rss` con `version="2.0"` (3.2).
- Namespaces requeridos (3.3), comparados por URI:
  - iTunes: `http://www.itunes.com/dtds/podcast-1.0.dtd`
  - content: `http://purl.org/rss/1.0/modules/content/`
  - atom: `http://www.w3.org/2005/Atom`

  Se considera "declarado" si algún elemento o atributo del documento usa o declara la URI; basta que aparezca declarado en la raíz o en el canal. Se informa un Fallo por cada namespace ausente.
- `channel` presente y con `title` (3.4). Sin canal no se continúa con episodios.
- Sin `item` es Advertencia (3.6).
- Datos del canal (4.1 a 4.3): `title`, `generator`, `lastBuildDate`, `image`. La imagen se toma de `itunes:image@href` y, si falta, de `image/url`. `lastBuildDate` se valida con una expresión RFC 822/2822 (`Tue, 15 Nov 2026 10:00:00 +0000`) y `Date.parse` no `NaN`.
- La comprobación `HEAD` de la imagen (4.4) la hace `runner` con el cliente: estado 2xx y `Content-Type` que empieza con `image/`. Esa comprobación hereda la protección SSRF; un host privado es Fallo (9.1). Imagen ausente es Advertencia y no se hace petición.

### item-validator.js (Req. 5)

`validateItems(itemNodes)` → `{ items: ItemData[] }`, cada uno con sus `checks`.

- Extrae `title`, `guid`, `pubDate`, `itunes:duration`, y de `<enclosure>`: `url`, `length`, `type`.
- Fallo del episodio: falta `title`, `guid` o `<enclosure>`; `length` no es entero positivo (`/^[1-9]\d*$/`); `type` no coincide con `/^audio\/[\w.+-]+$/i`; enclosure sin `url`.
- Advertencia del episodio: falta `pubDate` o `itunes:duration`.
- `guid` duplicado entre episodios: Fallo, que se adjunta a cada episodio involucrado (y cuenta una vez en los totales por episodio afectado).
- Sin `<enclosure>` o sin `url` válida, el episodio no pasa a `enclosure-checker` (no hay nada que pedir).

### enclosure-checker.js (Req. 6, 7, 9)

`checkEnclosure(client, item, options)` → `Check[]`.

Orden de peticiones por episodio (secuencial): `HEAD`, `GET` completo, `GET` con `Range: bytes=0-99`. La URL ya viene decodificada del parser XML. Si `assertSafe...` bloquea la URL (host privado o esquema), se registra un único Fallo `enclosure.blocked` y se omiten las tres peticiones. Si una petición falla por red, se registra el Fallo `enclosure.<método>.network` con la causa y se siguen las demás.

| Comprobación | Fuente | Severidad |
|--------------|--------|-----------|
| `head.status` ≠ 200 | HEAD | Fallo (6.3) |
| `get.status` ≠ 200 | GET | Fallo (6.3) |
| `range.status` ≠ 206 | Range | Fallo (6.4) |
| `*.content-type` ≠ `type` declarado | las tres respuestas | Fallo (6.5) |
| `get.bytes` ≠ `length` | cuerpo del GET | Fallo (6.6); si `truncated`, se informa "no verificable" (Info, sin severidad) (6.10) |
| `head.content-length` ≠ `length` | HEAD | Fallo (6.7) |
| `head.accept-ranges` ≠ `bytes` | HEAD | Fallo (6.8) |
| `range.body` ≠ 100 bytes o `range.content-range` ≠ `bytes 0-99/<length>` | Range | Fallo (6.9) |
| `html.content-type` (`text/html`, `application/xhtml+xml`) | cualquiera | Fallo (7.1) |
| `html.signature` | primeros bytes del GET y del Range | Fallo (7.2) |
| `parity.status`, `parity.content-type`, `parity.content-length` GET vs HEAD | HEAD y GET | Fallo (7.3) |
| `cache-control.no-store` | cualquiera | Advertencia, solo con `--check-cache-control` (7.4, 7.5) |

Detalles:

- **Comparación de `Content-Type`.** Se toma la parte anterior a `;`, se recorta y se pasa a minúsculas, y se compara con el `type` del feed normalizado igual.
- **Detección de HTML por firma.** Se examinan los primeros 512 bytes del cuerpo: se descarta un BOM UTF-8 y los espacios iniciales, y se compara en minúsculas con `<!doctype html`, `<html`, `<head`, `<body`. Un MP3 válido comienza con `ID3` o con el sync `0xFF 0xFB/0xF3/0xF2`, así que no hay falso positivo con audio. La firma se evalúa en el `GET` completo y en el `GET` con `Range`, aunque el `Content-Type` diga audio (7.2).
- **Paridad (7.3).** Compara estado, `Content-Type` normalizado y `Content-Length` de `GET` contra `HEAD`. Si el `GET` no trae `Content-Length` (transferencia por chunks), esa parte no se compara y no se informa; el conteo real de bytes ya lo cubre `get.bytes`. El mensaje incluye ambos valores, por ejemplo `HEAD=audio/mpeg, GET=text/html`.
- **Reducción de ruido.** Cuando `html.content-type` o `html.signature` fallan, el resto de las comprobaciones se siguen ejecutando: el informe debe mostrar la discrepancia completa.
- **`GET` truncado.** Si `bytesRead` alcanzó `--max-bytes`, no se compara `length` por bytes y se emite un `Check` con `result: "info"` y mensaje "no se pudo verificar length por bytes (superó --max-bytes)". La comparación de `Content-Length` de `HEAD` con `length` se mantiene.

### report.js (Req. 8)

- `buildResult({ feedUrl, options, channel, items, feedChecks, startedAt, finishedAt })` → objeto resultado (ver Data Models). Calcula totales y `exitCode`.
- `renderText(result, { color })`: primero el bloque del canal, luego un bloque por episodio con cada comprobación (`OK`, `ADVERTENCIA`, `FALLO`) y el motivo de cada incumplimiento, y al final el total de episodios revisados, Fallos y Advertencias. Solo las comprobaciones con resultado distinto de OK se listan con motivo; las OK se resumen en una línea con el conteo para que la salida sea legible con 50 episodios. El color usa secuencias ANSI solo si `color` es true; con `--no-color`, `NO_COLOR` o salida no TTY se imprime texto plano (8.8).
- `renderJson(result)`: `JSON.stringify(result, null, 2)` + `\n`. En modo JSON stdout contiene únicamente ese documento (8.6); cualquier diagnóstico va a stderr (8.7).
- `exitCode(result)`: 1 si `totals.failures > 0`, si no 0.

### runner.js

`run(feedUrl, options, client)` descarga el feed (`keepBody`, límite `maxFeedBytes`), lo parsea, valida, comprueba la imagen y revisa hasta `options.limit` episodios. Si hay más episodios que el tope, el resultado incluye `totals.episodesSkipped` y una Advertencia de feed `feed.limit` con el texto "se revisaron N de M episodios". Un Fallo en el feed (red, estado ≠ 200, tamaño, XML mal formado, raíz inválida, sin canal) corta la ejecución y no revisa episodios (Req. 2.3), pero igualmente produce un resultado completo (texto o JSON) con exit 1.

### Códigos de salida (Req. 8.3 a 8.5)

| Código | Situación |
|--------|-----------|
| 0 | Ejecución completa sin Fallos (con o sin Advertencias), o `--help` |
| 1 | Al menos un Fallo de las comprobaciones, incluidas fallas de red al descargar el feed |
| 2 | Error de uso o de entrada (sin URL, URL inválida, esquema, credenciales, opción desconocida o con valor inválido). También un error interno inesperado, informado por stderr |

### package.json y Jest

- Script nuevo: `"check:feed": "node scripts/check-feed.js"`. No hay dependencias nuevas.
- `jest.config.js` hoy fija `testEnvironment: 'jsdom'` y `testMatch` solo sobre `assets/src/**/*.test.js`. Se amplía `testMatch` con `'<rootDir>/scripts/check-feed/**/*.test.js'`, y cada archivo de prueba de la herramienta declara `/** @jest-environment node */` en su cabecera, porque necesita `node:http`, `node:dns` y sockets reales que jsdom no ofrece de forma fiable. El `npm test` existente (`wp-scripts test-unit-jest --config=jest.config.js`) ejecuta ambos conjuntos. El archivo de configuración es el único existente que se toca, y el cambio es de una línea.
- Las pruebas no necesitan `babel-jest` para `scripts/` (CommonJS puro); el transform existente es inocuo para esos archivos. Los módulos usan `'use strict'` y `module.exports`, como el resto de `scripts/*.js`.

## Data Models

### Check

```
Check {
  id: string,                 // estable, p. ej. "range.status"
  scope: "feed" | "channel" | "item" | "enclosure",
  result: "ok" | "warning" | "failure" | "info",
  message: string,            // en español; para incumplimientos incluye valores obtenidos y esperados
  expected?: string | number,
  actual?: string | number,
  method?: "HEAD" | "GET" | "RANGE"
}
```

`info` se usa solo para "no verificable" (Req. 6.10) y no suma a Fallos ni Advertencias.

### Resultado JSON (Req. 8.6)

```
{
  "tool": "check-feed",
  "version": "0.8.0",                 // versión de package.json
  "feedUrl": "https://…/feed/podcast/",
  "finalUrl": "https://…/feed/podcast/",
  "startedAt": "ISO 8601",
  "durationMs": 1234,
  "options": { "limit": 50, "timeout": 30, "maxRedirects": 5, "maxBytes": 104857600,
               "allowPrivate": false, "checkCacheControl": false },
  "feed": {
    "status": 200,
    "checks": [ Check ]
  },
  "channel": {
    "title": string | null,
    "generator": string | null,
    "lastBuildDate": string | null,
    "image": { "url": string | null, "status": number | null, "contentType": string | null },
    "checks": [ Check ]
  } | null,
  "items": [
    {
      "index": 1,
      "title": string | null,
      "guid": string | null,
      "pubDate": string | null,
      "duration": string | null,
      "enclosure": {
        "url": string | null, "length": number | null, "type": string | null,
        "head":  { "status": number|null, "contentType": string|null, "contentLength": number|null, "acceptRanges": string|null, "error": string|null },
        "get":   { "status": number|null, "contentType": string|null, "contentLength": number|null, "bytes": number|null, "truncated": boolean, "htmlSignature": boolean, "error": string|null },
        "range": { "status": number|null, "contentType": string|null, "contentRange": string|null, "bytes": number|null, "error": string|null }
      } | null,
      "checks": [ Check ],
      "failures": number,
      "warnings": number
    }
  ],
  "totals": {
    "episodesFound": number,
    "episodesChecked": number,
    "episodesSkipped": number,
    "failures": number,
    "warnings": number
  },
  "exitCode": 0 | 1
}
```

Los totales de Fallos y Advertencias suman las comprobaciones de feed, canal y episodios. Los errores de uso (exit 2) no producen este documento: se informan por stderr y stdout queda vacío.

### Tabla de severidad (Supuesto 9)

| Comprobación | Severidad | Req. |
|--------------|-----------|------|
| Error de red al descargar el feed | Fallo | 2.2 |
| Estado del feed ≠ 200 | Fallo | 2.3 |
| Feed supera tamaño máximo | Fallo | 2.5 |
| XML mal formado, DOCTYPE o entidades | Fallo | 3.1, 3.5 |
| Raíz no es `rss` 2.0 | Fallo | 3.2 |
| Namespace iTunes, content o atom ausente (uno por cada uno) | Fallo | 3.3 |
| Sin `channel` o sin `title` | Fallo | 3.4 |
| Sin `item` | Advertencia | 3.6 |
| Falta `generator`, `lastBuildDate` o imagen del canal | Advertencia | 4.2 |
| `lastBuildDate` no es RFC 822 | Advertencia | 4.3 |
| Imagen del canal no responde 2xx con `image/*` | Fallo | 4.4 |
| Falta `title`, `guid` o `enclosure` | Fallo | 5.2 |
| Falta `pubDate` o `itunes:duration` | Advertencia | 5.3 |
| `guid` duplicado | Fallo | 5.4 |
| `length` no es entero positivo o `type` no es `audio/*` | Fallo | 5.5 |
| HEAD o GET con estado ≠ 200 | Fallo | 6.3 |
| Range con estado ≠ 206 | Fallo | 6.4 |
| `Content-Type` distinto del `type` declarado | Fallo | 6.5 |
| Bytes del GET ≠ `length` | Fallo | 6.6 |
| `Content-Length` de HEAD ≠ `length` | Fallo | 6.7 |
| HEAD sin `Accept-Ranges: bytes` | Fallo | 6.8 |
| Range sin 100 bytes o con `Content-Range` incorrecto | Fallo | 6.9 |
| GET truncado por `--max-bytes` (no verificable) | Info, sin severidad | 6.10 |
| `Content-Type` HTML | Fallo | 7.1 |
| Cuerpo que empieza con marcador HTML | Fallo | 7.2 |
| GET difiere de HEAD en estado, tipo o longitud | Fallo | 7.3 |
| `Cache-Control: no-store` (solo con `--check-cache-control`) | Advertencia | 7.4 |
| Host privado, esquema no permitido o redirección bloqueada | Fallo | 9.1, 9.4 |
| Hay más episodios que `--limit` | Advertencia | 9.5 |

## Correctness Properties

Cada propiedad es universal y se prueba con casos generados a mano o por tabla; no se agrega una librería de pruebas basadas en propiedades por la regla de no sumar dependencias. Los generadores son funciones deterministas dentro de los tests (por ejemplo, listas de IPs de cada rango, mutaciones de un feed válido).

Property 1: Validación de entrada previa a la red
_For any_ argumento ausente, URL no parseable, con esquema distinto de `http`/`https` o con credenciales, `parseArgs` SHALL devolver error de uso, la herramienta SHALL terminar con código 2 y el cliente HTTP SHALL recibir cero llamadas.
**Validates: Requirements 1.2, 1.3, 1.4, 8.5**

Property 2: Ayuda sin efectos
_For any_ invocación que incluya `-h` o `--help`, la herramienta SHALL imprimir uso, opciones y códigos de salida y terminar con 0 sin realizar peticiones.
**Validates: Requirements 1.5**

Property 3: Códigos de salida coherentes con los Fallos
_For any_ resultado, `exitCode` SHALL ser 0 si y solo si `totals.failures` es 0, aunque `totals.warnings` sea mayor que 0, y 1 en caso contrario. Un error de uso SHALL producir 2, distinto de 1.
**Validates: Requirements 8.3, 8.4, 8.5**

Property 4: Bloqueo de hosts privados
_For any_ dirección en un rango privado, loopback, link-local o reservado (IPv4, IPv6 e IPv4 mapeada), `isPrivateAddress` SHALL devolver `true`; _for any_ URL de feed, redirección, imagen o enclosure cuyo host resuelva a ella sin `--allow-private`, el cliente SHALL no abrir ningún socket y el resultado SHALL contener un Fallo. Con `--allow-private` la petición SHALL realizarse.
**Validates: Requirements 9.1, 9.3, 9.4**

Property 5: Conexión a la dirección validada
_For any_ resolución DNS que devuelva direcciones, si alguna es privada el host SHALL bloquearse, y si todas son públicas el socket SHALL conectarse a la dirección que se validó, sin una segunda resolución.
**Validates: Requirements 9.2**

Property 6: Revalidación en cada redirección
_For any_ cadena de redirecciones en la que algún destino sea privado o use un esquema no permitido, el cliente SHALL detenerse en ese salto sin pedirlo e informar un Fallo; más de `--max-redirects` saltos SHALL producir un error de redirecciones.
**Validates: Requirements 2.4, 9.1, 9.4**

Property 7: Límites de tamaño y tiempo
_For any_ respuesta de feed mayor que el máximo, la descarga SHALL abortarse con Fallo. _For any_ enclosure mayor que `--max-bytes`, la lectura SHALL cortarse sin Fallo por `length`. _For any_ servidor que no responde dentro del timeout, la petición SHALL fallar con causa `timeout-connect` o `timeout-total`.
**Validates: Requirements 2.1, 2.2, 2.5, 6.10**

Property 8: Parser XML estricto y seguro
_For any_ texto con `<!DOCTYPE`, `<!ENTITY`, entidades no predefinidas, etiquetas mal anidadas o sin cerrar, o más de una raíz, `xml.parse` SHALL lanzar `XmlError` sin resolver ninguna entidad. _For any_ documento bien formado, `parse` SHALL devolver atributos y texto con las entidades predefinidas decodificadas (`&amp;` pasa a `&`).
**Validates: Requirements 3.1, 3.5, 6.1**

Property 9: Validación estructural del feed
_For any_ mutación de un feed válido que quite la raíz `rss` 2.0, un namespace requerido, el `channel` o su `title`, el resultado SHALL contener un Fallo por cada elemento quitado; un feed sin `item` SHALL contener solo una Advertencia por esa causa.
**Validates: Requirements 3.2, 3.3, 3.4, 3.6**

Property 10: Datos de canal y episodio
_For any_ feed válido, el resultado SHALL contener `title`, `generator`, `lastBuildDate` e imagen del canal, y para cada episodio `title`, `guid`, `pubDate`, `duration` y `url`, `length` y `type` del enclosure. Cada campo ausente SHALL clasificarse según la tabla de severidad, y dos episodios con el mismo `guid` SHALL generar un Fallo.
**Validates: Requirements 4.1, 4.2, 4.3, 5.1, 5.2, 5.3, 5.4, 5.5**

Property 11: Tres peticiones por enclosure
_For any_ enclosure con URL permitida, el cliente SHALL recibir exactamente una petición `HEAD`, un `GET` sin `Range` y un `GET` con `Range: bytes=0-99`, todas con la URL decodificada y con el `User-Agent` de la herramienta, sin `Cookie` ni `Authorization`.
**Validates: Requirements 6.1, 6.2, 9.6, 9.7**

Property 12: Fallos de enclosure por discrepancia
_For any_ enclosure cuyo estado, `Content-Type`, bytes del cuerpo, `Content-Length` de `HEAD`, `Accept-Ranges`, cuerpo del Range o `Content-Range` difieran de lo esperado, el resultado SHALL contener un Fallo con la comprobación afectada y los valores obtenido y esperado, y SHALL no marcar como Fallo ninguna comprobación que sí coincide.
**Validates: Requirements 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9**

Property 13: Detección de HTML y paridad GET vs HEAD
_For any_ enclosure donde `HEAD` responde 200 con `audio/mpeg` y `GET` responde con cuerpo HTML (con `Content-Type` `text/html` o con `Content-Type` de audio y cuerpo que empieza con `<!DOCTYPE html`, `<html`, `<head` o `<body`), el resultado SHALL contener Fallos de HTML y de paridad que citen los valores de ambos métodos. _For any_ cuerpo de audio válido (`ID3` o sync MPEG) SHALL no haber falso positivo.
**Validates: Requirements 7.1, 7.2, 7.3**

Property 14: `Cache-Control` opcional
_For any_ respuesta con `Cache-Control: no-store`, el resultado SHALL contener una Advertencia si y solo si `--check-cache-control` está activo; sin la opción el encabezado SHALL no evaluarse.
**Validates: Requirements 7.4, 7.5**

Property 15: Salida estructurada y canales
_For any_ ejecución con `--json`, stdout SHALL contener un único documento JSON válido con canal, episodios, comprobaciones y totales, y ningún otro texto; todo diagnóstico SHALL ir a stderr. _For any_ ejecución sin color habilitado, stdout SHALL no contener secuencias de escape ANSI.
**Validates: Requirements 8.1, 8.2, 8.6, 8.7, 8.8**

Property 16: Tope de episodios
_For any_ feed con más episodios que `--limit` (por defecto 50), la herramienta SHALL revisar como máximo `limit` episodios, SHALL informar los omitidos en `totals.episodesSkipped` y SHALL emitir una Advertencia.
**Validates: Requirements 9.5**

Property 17: Solo lectura
_For any_ ejecución, los métodos HTTP enviados SHALL ser únicamente `GET` y `HEAD`, y la herramienta SHALL no crear ni modificar archivos del proyecto. El código del plugin no cambia y `scripts/` queda fuera del zip.
**Validates: Requirements 10.1, 10.2**

## Error Handling

| Situación | Tratamiento | Salida |
|-----------|-------------|--------|
| Sin URL, URL inválida, esquema o credenciales no permitidos, opción desconocida o valor no numérico | Mensaje de uso en español por stderr; sin peticiones | exit 2 |
| Error interno inesperado (excepción no controlada) | Mensaje corto por stderr, sin volcar la pila salvo con `DEBUG=check-feed`; stdout vacío | exit 2 |
| DNS, conexión rechazada, TLS o timeout al descargar el feed | `HttpError` con causa en español; Fallo `feed.fetch`; no se revisan episodios | exit 1 |
| Feed con estado ≠ 200 | Fallo `feed.status` con el estado; no se revisan episodios | exit 1 |
| Feed mayor que 10 MiB | Descarga abortada; Fallo `feed.size` | exit 1 |
| XML mal formado, DOCTYPE o entidades | `XmlError` con línea y columna; Fallo `feed.xml`; el documento no se interpreta | exit 1 |
| Host privado, esquema no permitido o redirección bloqueada | `BlockedHostError`/`BlockedProtocolError`; no se abre el socket; Fallo con el host y la dirección | exit 1 |
| Demasiadas redirecciones | Fallo `redirects.exceeded` con la cadena | exit 1 |
| Error de red en un enclosure o en la imagen | Fallo de ese método con la causa; las demás peticiones del episodio continúan; los demás episodios continúan | exit 1 |
| `GET` de enclosure truncado por `--max-bytes` | Check `info` "no verificable"; sin Fallo | exit sin cambio |
| Un episodio falla | Nunca aborta la ejecución: se acumulan los resultados y se reportan todos | |
| SIGINT | Se destruyen las peticiones activas y se sale con 130 sin imprimir un resultado parcial como si estuviera completo | exit 130 |

Los mensajes de error nunca incluyen cabeceras de petición. Las URLs en los informes se imprimen tal cual vienen del feed (sin credenciales, porque se rechazan en la entrada; una URL de enclosure con credenciales se bloquea como esquema/URL no permitida y se informa con las credenciales enmascaradas).

## Testing Strategy

### Enfoque

Pruebas Jest en `scripts/check-feed/*.test.js` con `@jest-environment node`, ejecutadas con `npm test`. No dependen de un sitio externo (Req. 10.3). Hay tres niveles:

1. **Unitarias puras** de `cli`, `url-guard`, `xml`, `feed-validator`, `item-validator` y `report`, sin red. La resolución DNS se simula con `lookup` inyectado.
2. **Cliente y verificador con servidor local.** `test-server.js` levanta un `http.createServer` en `127.0.0.1:0` (puerto efímero) con rutas configurables por prueba y se cierra en `afterAll`. Como el servidor es loopback, estas pruebas usan `allowPrivate: true`. Una prueba aparte confirma que sin esa opción el mismo servidor se bloquea.
3. **Extremo a extremo.** `runner.run` más `report` contra el servidor local, y una prueba que invoca `node scripts/check-feed.js` con `child_process.spawnSync` para verificar los códigos de salida y la separación stdout/stderr.

### Servidor de prueba

Rutas del servidor (cada una devuelve lo necesario para un caso):

| Ruta | Comportamiento |
|------|----------------|
| `/feed-ok.xml` | Feed válido con 2 episodios; los `url` apuntan a `/audio/ok-1.mp3` y `/audio/ok-2.mp3` (con `&amp;` en la query) |
| `/audio/ok-N.mp3` | `HEAD` y `GET` 200 `audio/mpeg`, `Content-Length` correcto, `Accept-Ranges: bytes`; `Range: bytes=0-99` → 206 con `Content-Range: bytes 0-99/<len>` y 100 bytes. El cuerpo empieza con `ID3` |
| `/audio/html-on-get.mp3` | **Reproduce el bug de `enclosure-url-returns-html`:** `HEAD` → 200 `audio/mpeg` con `Content-Length` igual a `length`; `GET` (con o sin Range) → 200 `text/html` con un cuerpo `<!DOCTYPE html><html>…` |
| `/audio/html-body-audio-ct.mp3` | `GET` con `Content-Type: audio/mpeg` pero cuerpo HTML (prueba de firma) |
| `/audio/wrong-length.mp3` | Cuerpo de 1000 bytes con `length` declarado 2000 en el feed |
| `/audio/status-404.mp3`, `/audio/status-500.mp3` | Estados distintos de 200/206 |
| `/audio/no-ranges.mp3` | Sin `Accept-Ranges`, el `Range` se ignora y devuelve 200 |
| `/audio/bad-content-range.mp3` | 206 con `Content-Range` distinto de `bytes 0-99/<len>` |
| `/audio/wrong-type.mp3` | `Content-Type: audio/ogg` con `type="audio/mpeg"` en el feed |
| `/audio/big.mp3` | Cuerpo mayor que el `--max-bytes` de la prueba |
| `/audio/no-store.mp3` | Incluye `Cache-Control: no-store` |
| `/redirect-private` | 302 a una URL que resuelve a un host privado simulado |
| `/redirect-loop` | Redirección cíclica |
| `/slow` | Retiene la respuesta para probar el timeout total |
| `/feed-invalid.xml`, `/feed-doctype.xml`, `/feed-no-namespaces.xml`, `/feed-no-items.xml`, `/feed-dup-guid.xml` | Variantes del feed |
| `/feed-404`, `/feed-huge.xml` | Estado 404 y feed mayor que `maxFeedBytes` |

El servidor registra los métodos, URLs y cabeceras recibidas, lo que permite afirmar la Property 11 (tres peticiones, `User-Agent`, sin `Cookie`) y la Property 17 (solo `GET` y `HEAD`).

### Casos obligatorios (Req. 10.3)

1. **Feed válido**: resultado sin Fallos, exit 0, canal y episodios extraídos.
2. **XML inválido** (`/feed-invalid.xml`, `/feed-doctype.xml`): Fallo `feed.xml`, exit 1, sin revisar episodios.
3. **Enclosure con `GET`=HTML y `HEAD`=audio** (`/audio/html-on-get.mp3` y `/audio/html-body-audio-ct.mp3`): Fallos `html.content-type` o `html.signature`, `parity.content-type`, y exit 1. La prueba comprueba que `HEAD` solo pasa sus comprobaciones y que el Fallo se detecta únicamente por el `GET`.
4. **`length` incorrecto** (`/audio/wrong-length.mp3`): Fallo `get.bytes` con ambos valores.
5. **Estado distinto de 200/206** (`/audio/status-404.mp3`, `/audio/no-ranges.mp3`): Fallos de estado indicando el recibido.
6. **Host privado bloqueado**: con `allowPrivate: false` y el servidor en `127.0.0.1`, el cliente no recibe ninguna petición y el resultado trae el Fallo; también para una redirección a un host privado y para una resolución DNS simulada con una dirección privada.

### Otras pruebas

- `cli`: tabla de entradas válidas e inválidas, valores por defecto, `--help` (Properties 1 y 2).
- `url-guard`: una lista por cada rango de `isPrivateAddress`, incluyendo IPv6 y mapeada; resolución con registros mixtos (Properties 4 y 5).
- `http-client`: redirecciones (límite, bucle, relativas), truncamiento por `maxBytes`, timeout de conexión (dirección no enrutable simulada con un servidor que no hace `accept`, o un puerto que retiene) y timeout total con `/slow`, `Accept-Encoding: identity`, sin cookies (Properties 6, 7 y 11).
- `xml`: documentos bien formados con entidades, CDATA y namespaces; lista de documentos malformados y hostiles (billion laughs con `DOCTYPE`, entidad externa, profundidad mayor que 100) que deben lanzar `XmlError` rápido (Property 8).
- `report`: JSON válido con `JSON.parse`, estructura del modelo de datos, totales, `exitCode`, ausencia de ANSI con color desactivado, un único documento en stdout (Properties 3, 15 y 16).
- `cache-control`: con y sin `--check-cache-control` sobre `/audio/no-store.mp3` (Property 14).
- Proceso real: `spawnSync('node', ['scripts/check-feed.js', ...])` para verificar exit 0, 1 y 2, stdout vacío ante error de uso y stdout solo JSON con `--json` (Properties 1, 3 y 15).

### Trazabilidad

| Propiedad | Requisitos | Pruebas principales |
|-----------|------------|---------------------|
| 1, 2 | 1.2 a 1.5, 8.5 | `cli.test.js`, proceso real |
| 3 | 8.3 a 8.5 | `report.test.js`, proceso real |
| 4, 5, 6 | 2.4, 9.1 a 9.4 | `url-guard.test.js`, `http-client.test.js`, caso 6 |
| 7 | 2.1, 2.2, 2.5, 6.10 | `http-client.test.js` |
| 8, 9 | 3.1 a 3.6, 6.1 | `xml.test.js`, `feed-validator.test.js` |
| 10 | 4.1 a 4.3, 5.1 a 5.5 | `feed-validator.test.js`, `item-validator.test.js` |
| 11, 12 | 6.1 a 6.9, 9.6, 9.7 | `enclosure-checker.test.js` con el servidor local |
| 13 | 7.1 a 7.3 | caso 3, `enclosure-checker.test.js` |
| 14 | 7.4, 7.5 | `enclosure-checker.test.js` |
| 15, 16 | 8.1 a 8.8, 9.5 | `report.test.js`, `runner.test.js` |
| 17 | 10.1, 10.2 | registro del servidor, revisión de `.distignore` y `bin/verify-zip.sh` |

### Verificación manual

Después de implementar, ejecutar `npm run check:feed -- https://lucasbonomo.com/feed/podcast/` y comprobar el resultado contra el estado conocido del sitio. Esta ejecución usa un sitio externo, por lo que no forma parte de las pruebas automatizadas.
