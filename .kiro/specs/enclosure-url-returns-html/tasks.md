# Implementation Plan

- [x] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - GET de enclosure entrega el MP3
  - **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior - it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate the bug exists
  - **Scoped PBT Approach**: Scope the property to los casos concretos que el plugin controla (PHPUnit no puede reproducir una caché externa)
  - En `tests/phpunit/Integration/AudioDeliveryTest.php` y `FeedOutputTest.php`, escribir tests que verifiquen (isBugCondition: GET a la URL de un enclosure válido que no llega a `serve()`):
    - Con `$_GET['lb_sp_audio']` definido, tras `do_action( 'init' )` está definida `DONOTCACHEPAGE` (antes de `template_redirect`)
    - El `url` del `<enclosure>` del feed es igual a `AudioDelivery::url()` y no empieza por `home_url( '/?' )`
    - Con `get_permalink()` devolviendo `false`, `AudioDelivery::url()` usa `home_url( '/index.php' )` con la query y nunca `home_url( '/' )`
    - `response()` en GET devuelve 200/`audio/mpeg`/`Content-Length`/`Cache-Control` con `no-store`, y en GET con Range válido devuelve 206 con `Content-Range`
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS (this is correct - it proves the bug exists)
  - Document counterexamples found (por ejemplo, `DONOTCACHEPAGE` no definida en `init`; URL de raíz cuando no hay permalink)
  - Chequeo operativo previo al fix (documentar el resultado, no es automatizable): `curl -I <url>`, `curl -s -o /dev/null -D- <url>` y `curl -s -o /dev/null -D- -r 0-99 <url>` sobre un enclosure del feed desplegado; confirmar que GET devuelve `text/html` y HEAD `audio/mpeg`
  - Mark task complete when test is written, run, and failure is documented
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.5_

- [x] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Respuestas no afectadas
  - **IMPORTANT**: Follow observation-first methodology
  - Observe behavior on UNFIXED code for inputs where isBugCondition is false: HEAD válido, Range inválido/no satisfacible/múltiple/`If-Range`, IDs o MIME inválidos (404), entrada no pública o con contraseña (403), método no permitido (405), audio no legible con attachment externo (302)
  - Write property-based tests (data provider de método, Range, `If-Range`, IDs) capturando los estados y headers observados de `response()`
  - Verificar que HEAD nunca envía cuerpo y que se registra en `RequestLog`
  - Verificar que el feed mantiene `guid`, `length`, `type` y `duration` (solo `url` puede variar)
  - Mantener sin cambios `test_ranges_and_head`, `test_invalid_association_and_private_posts_are_not_public` y `test_unavailable_local_file_redirects_to_attachment_url`
  - Run tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9_

- [x] 3. Fix for enclosure URL que devuelve HTML en GET

  - [x] 3.1 Unificar la URL de entrega con respaldo `index.php`
    - En `inc/Media/AudioDelivery.php`, `url()` sigue usando `get_permalink( $post_id )` con `add_query_arg()` y orden `lb_sp_audio`, `lb_sp_attachment`
    - Si `get_permalink()` devuelve `false` o vacío, usar `home_url( '/index.php' )` con la misma query, nunca `home_url( '/' )`
    - _Bug_Condition: isBugCondition(input) donde input.method = GET y la URL del enclosure no llega a `AudioDelivery::serve()`_
    - _Expected_Behavior: expectedBehavior(result) from design_
    - _Preservation: Preservation Requirements from design_
    - _Requirements: 2.3, 3.7_

  - [x] 3.2 Añadir exclusión de caché temprana en `init`
    - Crear `early_nocache()` y registrarlo en `init` con prioridad 0; actúa solo si existe `$_GET['lb_sp_audio']`
    - Definir `DONOTCACHEPAGE`, `DONOTCACHEOBJECT` y `DONOTCACHEDB` si no están definidas
    - Enviar `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` y `X-LiteSpeed-Cache-Control: no-cache` solo si `! headers_sent()`
    - Ejecutar `do_action( 'litespeed_control_set_nocache', 'lb-simple-podcast audio' )` y aplicar el filtro `lb_sp_audio_nocache_headers`
    - Factorizar la lógica común en un método que `serve()` también use, conservando `nocache_headers()` y el `Cache-Control` actual
    - _Bug_Condition: isBugCondition(input) from design_
    - _Expected_Behavior: expectedBehavior(result) from design_
    - _Preservation: Preservation Requirements from design_
    - _Requirements: 1.5, 2.5, 3.6_

  - [x] 3.3 Garantizar paridad GET/HEAD
    - Verificar que `serve()` obtiene estado y headers de `response()` para ambos métodos y que solo difiere el envío del cuerpo
    - Mantener `allow_head()`
    - _Bug_Condition: isBugCondition(input) from design_
    - _Expected_Behavior: expectedBehavior(result) from design_
    - _Preservation: Preservation Requirements from design_
    - _Requirements: 1.4, 2.4, 3.1_

  - [x] 3.4 Revisar feed y `audio_data.url`
    - Confirmar que `inc/Meta/AudioMeta.php`, `inc/Feed/PodcastFeedRenderer.php` y `templates/feed-podcast.php` obtienen la URL solo de `AudioDelivery::url()`; si alguno la arma a mano, sustituirla por esa llamada
    - No cambiar `length`, `type`, `guid` ni `duration`
    - _Bug_Condition: isBugCondition(input) from design_
    - _Expected_Behavior: expectedBehavior(result) from design_
    - _Preservation: Preservation Requirements from design_
    - _Requirements: 2.3, 3.7_

  - [x] 3.5 Documentar la exclusión de caché en `readme.txt`
    - Añadir una nota en FAQ o notas: si la caché de página del hosting sirve HTML para la URL del enclosure, excluir el parámetro `lb_sp_audio` de la caché
    - No cambiar la versión (fuera de alcance; no tocar la spec `plugin-version-global-constant`)
    - _Requirements: 2.5_

  - [x] 3.6 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - GET de enclosure entrega el MP3
    - **IMPORTANT**: Re-run the SAME test from task 1 - do NOT write a new test
    - The test from task 1 encodes the expected behavior
    - When this test passes, it confirms the expected behavior is satisfied
    - Run bug condition exploration test from step 1
    - **EXPECTED OUTCOME**: Test PASSES (confirms bug is fixed)
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

  - [x] 3.7 Verify preservation tests still pass
    - **Property 2: Preservation** - Respuestas no afectadas
    - **IMPORTANT**: Re-run the SAME tests from task 2 - do NOT write new tests
    - Run preservation property tests from step 2
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - Confirm all tests still pass after fix (no regressions)

- [x] 4. Checkpoint - Ensure all tests pass
  - Ejecutar la suite completa de PHPUnit, `phpcs` y `phpstan`; todo debe pasar
  - Tras desplegar, chequeo operativo con curl sobre cada `<enclosure>` del feed desplegado:
    - `curl -I <url>` (HEAD): 200, `audio/mpeg`, `Content-Length` igual al `length` del feed
    - `curl -s -o /dev/null -D- <url>` (GET): 200, `audio/mpeg`, `Cache-Control` con `no-store`
    - `curl -s -o /dev/null -D- -r 0-99 <url>` (GET con Range): 206, `Content-Range: bytes 0-99/<length>`, `audio/mpeg`
  - Si GET sigue devolviendo `text/html` con la URL del permalink, la causa está en la capa externa: excluir `lb_sp_audio` de la caché de página del hosting (según la nota de `readme.txt`) y repetir
  - Ensure all tests pass, ask the user if questions arise.
