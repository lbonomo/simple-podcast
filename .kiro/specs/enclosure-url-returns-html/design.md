# Enclosure URL Returns HTML Bugfix Design

## Overview

Un GET a la URL de un `<enclosure>` devuelve la home en HTML en lugar del MP3, mientras que HEAD sí llega a `AudioDelivery::serve()`. Los clientes de podcast descargan con GET, por lo que no pueden reproducir los episodios y las comprobaciones por HEAD y `length` no detectan el fallo.

La causa raíz no está confirmada. Las evidencias (faltan los headers de `serve()` en el GET, el mismo GET sobre el permalink o sobre `index.php` funciona, HEAD funciona) apuntan a una capa anterior a `serve()`: una caché de página que sirve la home para GET a la raíz con query strings desconocidas, un rewrite u otro plugin. También es posible que el build 0.5.0 desplegado genere una URL distinta a la del repo (0.7.0).

Estrategia, sin asumir una sola causa:

1. **Forma de URL robusta**: una única fuente de verdad (`AudioDelivery::url()`) que genera la URL sobre el permalink del episodio (forma que ya funciona en producción) y que usan el feed, `audio_data.url`, SEO y el player.
2. **Exclusión de caché lo más temprano posible**: detectar la petición de audio en `init` (prioridad 0), antes de cualquier salida y antes de `template_redirect`, y declarar `DONOTCACHEPAGE` y headers/acciones de exclusión de las cachés habituales.
3. **Misma respuesta en GET y HEAD**: ambos métodos pasan por `response()` y comparten estado y headers; solo cambia que HEAD no envía cuerpo.
4. **Tests PHPUnit** que fijan la forma de la URL, la paridad GET/HEAD, el 206 y la exclusión de caché. Una caché que responde antes de que arranque PHP no se puede probar en PHPUnit; se documenta como límite y se cubre con una verificación operativa.

No se modifica código en esta fase. La spec `plugin-version-global-constant` queda fuera de alcance.

## Glossary

- **Bug_Condition (C)**: GET a la URL de un `<enclosure>` válido que no es resuelta por `AudioDelivery::serve()` y recibe HTML.
- **Property (P)**: ese GET recibe `audio/mpeg` (200, o 206 con Range válido), el cuerpo del MP3 y `Cache-Control` con `no-store`.
- **Preservation**: comportamiento actual de HEAD, rangos inválidos, 403/404/405, `RequestLog`, copia protegida y redirección 302.
- **AudioDelivery::url()**: método estático en `inc/Media/AudioDelivery.php` que construye la URL de entrega con `add_query_arg()` sobre `get_permalink( $post_id )`.
- **AudioDelivery::serve()**: callback de `template_redirect` (prioridad 0) que registra la solicitud, envía headers y el cuerpo, y termina con `exit`.
- **AudioDelivery::response()**: calcula estado, headers, ruta, offset y longitud; no emite salida.
- **AudioMeta::audio_data**: campo REST en `inc/Meta/AudioMeta.php` cuyo `url` proviene de `AudioDelivery::url()`.
- **PodcastFeedRenderer / templates/feed-podcast.php**: generan el `<enclosure url length type>` del feed.
- **Capa de caché de página**: LiteSpeed Cache, WP Rocket, caché del hosting o similar, que puede responder antes o durante el arranque de WordPress.

## Bug Details

### Bug Condition

El bug aparece cuando un cliente hace GET a la URL de un `<enclosure>`. La petición no llega a `AudioDelivery::serve()`: o bien una caché de página o un rewrite la resuelve como la home, o bien el feed publicado usa una URL de raíz (`/?lb_sp_audio=…`) que esa capa trata como la home. HEAD no se cachea y llega a WordPress, por eso funciona.

**Formal Specification:**
```
FUNCTION isBugCondition(input)
  INPUT: input of type EnclosureRequest  // method, url, range?
  OUTPUT: boolean

  RETURN input.method = "GET"
         AND isEnclosureUrlOfValidEpisode(input.url)
         AND NOT reachesAudioDeliveryServe(input)
         // la respuesta es text/html servido por caché/rewrite/otro plugin
END FUNCTION
```

### Examples

- `GET https://lucasbonomo.com/?lb_sp_audio=7874&lb_sp_attachment=7873` devuelve `200 text/html` (~100 KB, la home) en vez de `200 audio/mpeg` con 2016000 bytes.
- El mismo GET con `Range: bytes=0-1023` devuelve `200 text/html` en vez de `206` con `Content-Range: bytes 0-1023/2016000`.
- `HEAD` sobre la misma URL devuelve `200 audio/mpeg`, `Content-Length: 2016000`; el GET equivalente devuelve HTML (la validación de `length` pasa y oculta el fallo).
- `GET https://lucasbonomo.com/resumen-semanal-ia-2026-11-13/?lb_sp_audio=7874&lb_sp_attachment=7873` devuelve `200 audio/mpeg` correctamente (forma que debe quedar como estándar).
- Caso límite: `HEAD`/`GET` con attachment que no corresponde al post devuelve 404 (sin cambios).

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- HEAD válido: 200, `audio/mpeg`, `Content-Length`, `Accept-Ranges: bytes`, sin cuerpo, registrado en `RequestLog` (3.1).
- Range inválido, no satisfacible, múltiple o con `If-Range`: 416 con `Content-Range: bytes */size` o respuesta completa, según el manejo actual (3.2).
- Entrada o attachment inexistentes, no asociados o con MIME distinto de `audio/mpeg`: 404 (3.3).
- Entrada no publicada sin permiso o con contraseña: 403 (3.4).
- Método distinto de GET y HEAD: 405 con `Allow: GET, HEAD` (3.5).
- Cada entrega se registra en `RequestLog` y `Content-Length` es coherente con el cuerpo (3.6).
- El feed mantiene `guid`, `length`, `type` y `duration`; solo puede cambiar el atributo `url` (3.7).
- Copia protegida, advertencia de almacenamiento y 302 al attachment externo (3.8, 3.9).

**Scope:**
No cambia nada que no sea la URL de enclosure/entrega y las cabeceras o constantes de exclusión de caché:
- Peticiones al feed y a páginas que no llevan `lb_sp_audio`.
- Lógica de rangos, validaciones y registro en `RequestLog`.
- Esquema de la tabla de solicitudes y opciones del plugin.

## Hypothesized Root Cause

1. **Caché de página delante de WordPress (principal)**: LiteSpeed/Hostinger sirve la página cacheada de `/` para GET con query strings que no conoce y no cachea HEAD. Explica que falten los headers de `serve()` y que `server-timing: wp-before-template` indique que WordPress sí arrancó o que la capa lo añade.
   - La URL de permalink funciona porque la clave de caché es distinta y el post no tiene entrada cacheada para esa query.
   - `index.php?...` funciona porque no coincide con la regla de la home.
2. **Rewrite u otro plugin**: una regla o plugin que, para GET en `/`, resuelve la home antes de `template_redirect` (por ejemplo, un redirect canónico o una página de inicio estática que ignora la query).
3. **Build desplegado distinto al repo**: el feed publicado (0.5.0) usa la raíz del sitio. El repo (0.7.0) ya usa el permalink, así que parte del bug puede resolverse al desplegar. Hay que comprobarlo, no darlo por hecho.
4. **Exclusión de caché tardía**: `serve()` corre en `template_redirect` y llama a `nocache_headers()` justo antes de la salida. No define `DONOTCACHEPAGE` ni usa señales específicas de LiteSpeed y WP Rocket, y lo hace tarde para plugins que deciden en `init`.

## Correctness Properties

Property 1: Bug Condition - GET de enclosure entrega el MP3

_For any_ petición GET (con o sin Range válido) a la URL de un `<enclosure>` válido donde la condición de bug se cumple (isBugCondition devuelve true), la función corregida SHALL entregar 200 (o 206 con Range válido) con `Content-Type: audio/mpeg`, `Content-Length`, `Accept-Ranges: bytes`, `Cache-Control` con `no-store` y `X-Content-Type-Options: nosniff`, con el cuerpo del MP3 (o del rango), y SHALL haber declarado la exclusión de caché antes de cualquier salida. La URL generada SHALL ser la misma en el feed y en `audio_data.url`, y GET y HEAD SHALL obtener el mismo estado, `Content-Type` y `Content-Length`.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5**

Property 2: Preservation - respuestas no afectadas

_For any_ petición donde la condición de bug NO se cumple (HEAD, rangos inválidos, `If-Range`, IDs o MIME inválidos, entradas no públicas, métodos no permitidos, audio no legible con 302), la función corregida SHALL producir el mismo resultado que la original, preservando estados, headers, registro en `RequestLog`, copia protegida y los valores `guid`, `length`, `type` y `duration` del feed.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9**

## Fix Implementation

### Changes Required

Suponiendo que la causa está en una capa de caché o en la forma de URL desplegada:

**File**: `inc/Media/AudioDelivery.php`

**Function**: `url()`, `register()`, nuevo `early_nocache()`, `serve()`

**Specific Changes**:
1. **URL única y explícita**: `url()` sigue construyendo la URL sobre `get_permalink( $post_id )` y se declara como única fuente de la URL de entrega. Si `get_permalink()` devuelve `false` o una URL vacía, usar como respaldo `home_url( '/index.php' )` con la misma query, nunca `home_url( '/' )`, para no depender de una regla de la home.
   - Mantener el orden de parámetros `lb_sp_audio`, `lb_sp_attachment`.
2. **Exclusión de caché temprana**: registrar `early_nocache()` en `init` con prioridad 0 (antes de `template_redirect`). Solo actúa si `$_GET['lb_sp_audio']` existe, y:
   - Define `DONOTCACHEPAGE`, `DONOTCACHEOBJECT` y `DONOTCACHEDB` si no están definidas.
   - Envía `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` y `X-LiteSpeed-Cache-Control: no-cache` con `header()` (solo si `! headers_sent()`).
   - Lanza `do_action( 'litespeed_control_set_nocache', 'lb-simple-podcast audio' )`.
   - Usa un filtro propio `lb_sp_audio_nocache_headers` para que el sitio añada otras cabeceras.
3. **Paridad GET/HEAD**: `serve()` sigue obteniendo estado y headers de `response()` para ambos métodos, y la única diferencia es el envío del cuerpo. Se mantiene `allow_head()`.
4. **`serve()` reutiliza las cabeceras tempranas**: conserva `nocache_headers()` y `Cache-Control` actuales para no cambiar el contrato (3.x) y no duplica lógica; la exclusión temprana se factoriza en un método común.
5. **Documentación operativa**: en `readme.txt` (sección de FAQ o notas) indicar que si la caché del hosting sirve HTML para la URL, hay que excluir el parámetro `lb_sp_audio` de la caché de página. Esto cubre el caso en que la caché responde antes de que arranque PHP, que ningún código del plugin puede evitar.

**Files**: `inc/Meta/AudioMeta.php`, `inc/Feed/PodcastFeedRenderer.php` / `templates/feed-podcast.php`

**Specific Changes**:
1. Confirmar que `audio_data.url` y el atributo `url` del `<enclosure>` se obtienen solo de `AudioDelivery::url()`, sin construir la URL a mano. Si el template o el renderer la arma de otra forma, sustituirla por la llamada a `AudioDelivery::url()`.
2. No cambiar `length`, `type`, `guid` ni `duration`.

**Fuera de alcance**: constante global de versión, esquema de `RequestLog`, lógica de rangos.

## Testing Strategy

### Validation Approach

Primero se buscan contraejemplos sobre el código sin corregir y luego se verifica la corrección y la preservación. PHPUnit (WP test suite, `tests/phpunit/Integration`) no puede reproducir una caché de página externa; los tests cubren lo que el plugin controla (URL, respuesta, señales de caché) y la verificación de la capa externa es operativa (curl sobre el entorno desplegado).

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples before implementing the fix. Confirm or refute the root cause analysis. If we refute, we will need to re-hypothesize.

**Test Plan**: Tests en `AudioDeliveryTest.php` y `FeedOutputTest.php` que comprueben la forma de la URL y las señales de caché, y un chequeo operativo con curl contra el sitio desplegado. Ejecutarlos sobre el código sin corregir.

**Test Cases**:
1. **Señales de caché tempranas**: simular `$_GET['lb_sp_audio']`, ejecutar `do_action( 'init' )` y comprobar `DONOTCACHEPAGE` (fallará en el código sin corregir).
2. **Forma de URL en el feed**: el `url` del `<enclosure>` debe ser igual a `AudioDelivery::url()` y no empezar por `home_url( '/?' )` (puede fallar si el feed la arma distinto).
3. **URL con permalink inexistente**: con `get_permalink()` en `false`, la URL no debe ser la raíz con query (fallará en el código sin corregir).
4. **Chequeo operativo**: `curl -s -o /dev/null -D- -r 0-99 <url>` y `curl -I <url>`; comparar `Content-Type`. Si el GET del feed desplegado sigue siendo HTML con la URL del permalink, la causa está en la capa externa.

**Expected Counterexamples**:
- `DONOTCACHEPAGE` no definida al llegar a `template_redirect`.
- Feed desplegado con URL de raíz distinta a la del repo.
- Posibles causas: caché de página, rewrite, build desplegado distinto, exclusión de caché tardía.

### Fix Checking

**Goal**: Verify that for all inputs where the bug condition holds, the fixed function produces the expected behavior.

**Pseudocode:**
```
FOR ALL input WHERE isBugCondition(input) DO
  result := enclosureResponse_fixed(input)
  ASSERT result.content_type = "audio/mpeg"
         AND (result.status = 200 OR (input.range valid AND result.status = 206))
         AND result.cache_control CONTAINS "no-store"
         AND DONOTCACHEPAGE is defined before output
END FOR
```

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold, the fixed function produces the same result as the original function.

**Pseudocode:**
```
FOR ALL input WHERE NOT isBugCondition(input) DO
  ASSERT enclosureResponse_original(input) = enclosureResponse_fixed(input)
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many test cases automatically across the input domain
- It catches edge cases that manual unit tests might miss
- It provides strong guarantees that behavior is unchanged for all non-buggy inputs

**Test Plan**: Observe behavior on UNFIXED code first for HEAD, rangos inválidos, 403/404/405 y 302, then write tests capturing that behavior. Los tests existentes de `AudioDeliveryTest` (`test_ranges_and_head`, `test_invalid_association_and_private_posts_are_not_public`, `test_unavailable_local_file_redirects_to_attachment_url`) se mantienen sin cambios. PHPUnit no incluye un framework de PBT; se usa un data provider con combinaciones de método, Range e `If-Range`.

**Test Cases**:
1. **HEAD y Range inválidos**: los resultados de `response()` coinciden con los actuales (3.1, 3.2).
2. **403, 404, 405**: sin cambios (3.3 a 3.5).
3. **Feed**: `guid`, `length`, `type`, `duration` iguales; solo `url` puede variar (3.7).
4. **302 y copia protegida**: sin cambios (3.8, 3.9).

### Unit Tests

- `AudioDelivery::url()` usa el permalink del post, también con página de inicio estática (test existente) y con respaldo `index.php` si no hay permalink.
- `early_nocache()` no hace nada sin `lb_sp_audio` y define `DONOTCACHEPAGE` y dispara `litespeed_control_set_nocache` con él.
- `response()` para GET devuelve 200/`audio/mpeg`/`Content-Length`; con Range válido devuelve 206 con `Content-Range` y `Content-Length` correctos.
- Paridad: para el mismo `post_id` y `attachment_id`, `response()` en GET y HEAD devuelve el mismo `status`, `Content-Type` y `Content-Length`.

### Property-Based Tests

- Data provider de (método, Range, If-Range, IDs): GET y HEAD coinciden en estado y headers de identidad; HEAD nunca envía cuerpo (`path` usable pero sin lectura en `serve()`).
- Data provider de Range (válidos, malformados, múltiples, no satisfacibles): la salida coincide con las expectativas actuales de `test_ranges_and_head`.
- Para varios posts con audio, la URL del `<enclosure>` es igual a `audio_data.url` y a `AudioDelivery::url()`.

### Integration Tests

- `FeedOutputTest`: el `url` del `<enclosure>` coincide con `AudioDelivery::url()` y con `audio_data.url` (`PostMetaTest`).
- Flujo completo del feed a `response()`: se parsea la URL del `<enclosure>`, se extraen `lb_sp_audio` y `lb_sp_attachment`, y `response()` devuelve 200/`audio/mpeg` con el `length` publicado en el feed.
- Verificación operativa tras desplegar: `curl` GET, GET con Range y HEAD sobre cada enclosure del feed; `Content-Type: audio/mpeg` y `Cache-Control` con `no-store` en los tres.
