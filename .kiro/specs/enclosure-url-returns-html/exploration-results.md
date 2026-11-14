# Resultados de la exploración del bug (tarea 1, código SIN corregir)

## Tests escritos

- `tests/phpunit/Integration/AudioDeliveryTest.php`
  - `test_bug_condition_early_nocache_is_declared_on_init` (a)
  - `test_bug_condition_url_falls_back_to_index_php_without_permalink` (c)
  - `test_bug_condition_get_returns_audio_and_valid_range_returns_206` (d)
- `tests/phpunit/Integration/FeedOutputTest.php`
  - `test_bug_condition_enclosure_url_matches_delivery_url_and_is_not_site_root` (b)
  - `test_bug_condition_enclosure_url_is_not_site_root_without_permalink` (b + c en el feed)

Ejecución (wp-env, contenedor `tests-cli`, base `wordpress_test` creada en `tests-mysql`):

```
docker exec -e LB_SP_WP_ABSPATH=/var/www/html -e LB_SP_DB_HOST=tests-mysql -e LB_SP_DB_USER=root \
  -e LB_SP_DB_PASSWORD=password -e LB_SP_DB_NAME=wordpress_test \
  -w /var/www/html/wp-content/plugins/simple-podcast $C vendor/bin/phpunit --filter bug_condition
```

Resultado: 5 tests, 4 fallan (esperado), 1 pasa.

## Contraejemplos

1. **(a) `DONOTCACHEPAGE` no definida tras `do_action( 'init' )`** con `$_GET['lb_sp_audio']` presente. No existe `early_nocache()`; `serve()` solo actúa en `template_redirect`.
2. **(c) Sin permalink**: con `get_permalink()` en `false`, `AudioDelivery::url( 7, 6 )` devuelve `?lb_sp_audio=7&lb_sp_attachment=6` (URL relativa, sin host ni `index.php`) en vez de `http://example.org/index.php?lb_sp_audio=7&lb_sp_attachment=6`.
3. **(b) Feed con permalinks planos** (configuración del entorno de test): el `url` del enclosure es `http://example.org/?p=11&lb_sp_audio=11&lb_sp_attachment=10`, que empieza por `home_url( '/?' )`. Es la forma de raíz con query que una caché puede tratar como la home. Coincide con `AudioDelivery::url()` (esa parte de la aserción pasa antes de fallar la segunda). Con permalinks bonitos este test no fallaría; el entorno de test usa estructura plana.
4. **(b) Feed sin permalink**: el `url` del enclosure es `?lb_sp_audio=13&lb_sp_attachment=12`, no empieza por `home_url( '/index.php' )`.

## Test que pasa en código sin corregir

- (d) `response()` ya devuelve 200/`audio/mpeg`/`Content-Length` en GET y 206 con `Content-Range: bytes 0-99/<size>` con Range válido. Es esperado: el defecto está en la capa anterior a `serve()` (caché/rewrite) o en la URL, no en `response()`. Queda como línea base de la propiedad.

## Límites

- `DONOTCACHEPAGE` es una constante: si otra prueba del mismo proceso la define antes, el test (a) fallaría su precondición. Tras el fix, la constante persistirá en el proceso para el resto de la suite.
- PHPUnit no puede reproducir una caché de página externa que responda antes de PHP.
- El test de `Cache-Control` con `no-store` mencionado en `tasks.md` no se escribió porque `response()` no devuelve ese header (lo envía `serve()` con `header()`); se omitió según el alcance indicado (a–d).

## Chequeo operativo previo al fix (curl contra https://lucasbonomo.com, ya ejecutado)

- `HEAD` sobre `https://lucasbonomo.com/?lb_sp_audio=7874&lb_sp_attachment=7873`: 200, `audio/mpeg`, `Content-Length: 2016000`, `Accept-Ranges: bytes`, `Cache-Control: private, no-store`.
- `GET` (con y sin `Range`, UA de Apple Podcasts y Spotify, HTTP/1.1 y HTTP/2) sobre la misma URL: 200, `text/html`, ~100 KB (la home), sin los headers de `serve()`.
- `GET` sobre el permalink del post con la misma query y sobre `/index.php?lb_sp_audio=...`: 200, `audio/mpeg`, 2016000 bytes.
- Conclusión: el GET a la raíz con query no llega a `serve()`; HEAD sí. Coherente con una capa de caché/rewrite delante de WordPress.

## Checkpoint (tarea 4)

- PHPUnit completo (wp-env): OK, 219 tests, 747 assertions.
- `composer phpcs`: sin errores ni warnings (tras corregir los de `AudioDelivery.php`, `AudioDeliveryTest.php` y `FeedOutputTest.php`).
- `composer phpstan`: `[OK] No errors`.
- **Pendiente tras el release/despliegue**: chequeo operativo con curl sobre cada `<enclosure>` del feed desplegado:
  - `curl -I <url>`: 200, `audio/mpeg`, `Content-Length` igual al `length` del feed.
  - `curl -s -o /dev/null -D- <url>`: 200, `audio/mpeg`, `Cache-Control` con `no-store`.
  - `curl -s -o /dev/null -D- -r 0-99 <url>`: 206, `Content-Range: bytes 0-99/<length>`, `audio/mpeg`.
  - Si GET sigue devolviendo `text/html`, excluir `lb_sp_audio` de la caché de página del hosting (nota en `readme.txt`) y repetir.
