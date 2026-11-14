# Bugfix Requirements Document

## Introduction

Las URLs de `<enclosure>` del feed de podcast devuelven HTML en lugar del MP3 cuando el cliente usa GET. En el feed de producción (`https://lucasbonomo.com/feed/podcast/`, generator LB Simple Podcast 0.5.0), los 4 enclosures tienen la forma `https://lucasbonomo.com/?lb_sp_audio=ID&lb_sp_attachment=ID` (por ejemplo `?lb_sp_audio=7874&lb_sp_attachment=7873`, `length=2016000`, `type=audio/mpeg`).

Mediciones con curl:

- HEAD sobre esa URL devuelve 200, `audio/mpeg`, `Content-Length: 2016000`, `Accept-Ranges: bytes` y `Cache-Control: private, no-store`. Es decir, `AudioDelivery::serve()` se ejecuta.
- GET (con o sin Range, con user agents de Apple Podcasts y Spotify, en HTTP/1.1 y HTTP/2) devuelve 200, `text/html`, unos 100 KB. Es la home (página 7486). Faltan los headers que fija `serve()` (`Cache-Control: no-store`, `X-Content-Type-Options`), por lo que `serve()` no entrega la respuesta. La respuesta incluye `server-timing: wp-before-template`.
- GET sobre `https://lucasbonomo.com/resumen-semanal-ia-2026-11-13/?lb_sp_audio=7874&lb_sp_attachment=7873` (permalink del post con la misma query) devuelve 200, `audio/mpeg`, 2016000 bytes. Es correcto.
- GET sobre `https://lucasbonomo.com/index.php?lb_sp_audio=7874&lb_sp_attachment=7873` devuelve 200, `audio/mpeg`, 2016000 bytes. Es correcto.
- IDs inexistentes o attachment que no corresponde devuelven 404 en HEAD.

Los clientes de podcast descargan con GET (o GET con Range) y reciben HTML. HEAD y las validaciones de `length` pasan, lo que enmascara el fallo.

La causa raíz no está confirmada. La hipótesis principal es una capa delante de WordPress (caché de página LiteSpeed/Hostinger, regla de rewrite u otro plugin) que trata los GET a la raíz con query strings desconocidas como la home, mientras que HEAD la evita. Además, el código del repo (0.7.0) construye la URL con el permalink del post, lo que puede diferir del build 0.5.0 desplegado. Esta spec cubre ambos casos sin asumir uno: la URL del enclosure debe entregar el audio ante GET sea cual sea la forma que tenga hoy y sea cual sea la capa que interfiere.

Alcance: solo las URLs de `<enclosure>`. La constante global de versión se trata en la spec `plugin-version-global-constant` y queda fuera de esta.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN un cliente de podcast hace GET a la URL de un `<enclosure>` THEN the system responde 200 con `Content-Type: text/html` y el HTML de la home, sin el MP3 ni los headers de `AudioDelivery::serve()`

1.2 WHEN un cliente de podcast hace GET con cabecera `Range` a la URL de un `<enclosure>` THEN the system responde 200 con el HTML de la home en lugar de 206 con `Content-Range` y `audio/mpeg`

1.3 WHEN el feed se publica con enclosures cuya URL apunta a la raíz del sitio con `lb_sp_audio` y `lb_sp_attachment` (build 0.5.0 desplegado) THEN the system genera una URL que una caché de página, un rewrite u otro plugin puede resolver como la home para GET

1.4 WHEN se hace HEAD a la URL de un `<enclosure>` THEN the system responde 200 con `audio/mpeg` y `Content-Length` correcto aunque el GET equivalente devuelva HTML, de modo que la verificación por HEAD y la validación de `length` no detectan el fallo

1.5 WHEN la URL de un `<enclosure>` puede ser servida por una caché de página THEN the system no impide ni señala que esa respuesta (HTML o audio) quede almacenada y reutilizada para solicitudes posteriores

### Expected Behavior (Correct)

2.1 WHEN un cliente de podcast hace GET a la URL de un `<enclosure>` THEN the system SHALL responder 200 con `Content-Type: audio/mpeg`, `Content-Length` igual al tamaño del archivo (por ejemplo 2016000), `Accept-Ranges: bytes`, `Cache-Control: private, no-store` y `X-Content-Type-Options: nosniff`, con el cuerpo MP3

2.2 WHEN un cliente de podcast hace GET con cabecera `Range` válida a la URL de un `<enclosure>` THEN the system SHALL responder 206 con `Content-Type: audio/mpeg`, `Content-Range` y `Content-Length` correspondientes al rango pedido

2.3 WHEN el plugin genera la URL de un `<enclosure>` THEN the system SHALL usar una forma de URL que no dependa de que una caché de página o un rewrite trate la raíz con query strings desconocidas como la home, por ejemplo el permalink del post con la query o un endpoint/rewrite dedicado del plugin, y SHALL generar esa misma forma tanto en el feed como en `audio_data.url`

2.4 WHEN se hace GET o HEAD a la URL de un `<enclosure>` THEN the system SHALL entregar la misma respuesta de audio (estado, `Content-Type`, `Content-Length`) en ambos métodos

2.5 WHEN la URL de un `<enclosure>` se solicita a través de una capa de caché de página THEN the system SHALL enviar las cabeceras y/o constantes de exclusión de caché necesarias (por ejemplo `DONOTCACHEPAGE` y `Cache-Control: private, no-store`) antes de cualquier salida, para que la respuesta no se almacene ni se sustituya por HTML cacheado

### Unchanged Behavior (Regression Prevention)

3.1 WHEN se hace HEAD a la URL de un `<enclosure>` válida THEN the system SHALL CONTINUE TO responder 200 con `audio/mpeg`, `Content-Length` y `Accept-Ranges: bytes` sin enviar cuerpo, y SHALL CONTINUE TO registrar la solicitud en `RequestLog`

3.2 WHEN se hace GET con Range inválido, no satisfacible o con `If-Range` THEN the system SHALL CONTINUE TO responder según el manejo actual (416 con `Content-Range: bytes */size` para rangos no satisfacibles, respuesta completa para rangos mal formados, múltiples o con `If-Range`)

3.3 WHEN la entrada o el attachment no existen, no corresponden entre sí o el MIME no es `audio/mpeg` THEN the system SHALL CONTINUE TO responder 404

3.4 WHEN la entrada no está publicada sin permiso de lectura o requiere contraseña THEN the system SHALL CONTINUE TO responder 403

3.5 WHEN el método HTTP no es GET ni HEAD THEN the system SHALL CONTINUE TO responder 405 con `Allow: GET, HEAD`

3.6 WHEN se entrega un audio THEN the system SHALL CONTINUE TO registrar cada solicitud en `RequestLog` con estado, cabeceras y IDs, y SHALL CONTINUE TO fijar `Content-Length` coherente con el cuerpo enviado

3.7 WHEN se genera el feed THEN the system SHALL CONTINUE TO emitir `guid`, `length`, `type` y `duration` de cada `<enclosure>`/ítem con el mismo formato y valores que antes, cambiando solo el valor del atributo `url` si la forma de la URL cambia

3.8 WHEN se entrega un audio THEN the system SHALL CONTINUE TO servir desde la copia protegida (o el archivo original con advertencia) y SHALL CONTINUE TO impedir el acceso directo no registrado según la protección actual del audio

3.9 WHEN el audio no se puede leer localmente y existe una URL de attachment externa THEN the system SHALL CONTINUE TO responder 302 a esa URL y marcar la advertencia de almacenamiento

## Bug Condition

```pascal
FUNCTION isBugCondition(X)
  INPUT: X of type EnclosureRequest  // método, URL del enclosure, cabecera Range opcional
  OUTPUT: boolean

  RETURN X.method = "GET"
         AND X.url es la URL de un <enclosure> válido del feed
         AND la URL no es resuelta por AudioDelivery::serve()
             (caché de página, rewrite u otro plugin la sirve como HTML)
END FUNCTION
```

```pascal
// Property: Fix Checking
FOR ALL X WHERE isBugCondition(X) DO
  result ← enclosureResponse'(X)
  ASSERT result.content_type = "audio/mpeg"
         AND (result.status = 200 OR (X.range válido AND result.status = 206))
         AND result.body es el contenido del MP3 (o el rango pedido)
         AND result.cache_control contiene "no-store"
END FOR
```

```pascal
// Property: Preservation Checking
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT enclosureResponse(X) = enclosureResponse'(X)
END FOR
```

Contraejemplo: `GET https://lucasbonomo.com/?lb_sp_audio=7874&lb_sp_attachment=7873` devuelve `200 text/html` (home) en lugar de `200 audio/mpeg` con 2016000 bytes.

## Criterios de verificación (PHPUnit)

- La forma de la URL de `<enclosure>` generada por el feed coincide con `AudioDelivery::url()` y con `audio_data.url`.
- La respuesta de `AudioDelivery::response()` para esa URL es 200/`audio/mpeg`/`Content-Length` para GET, 206 para GET con Range y el mismo estado para HEAD.
- Los casos preservados (3.2 a 3.5) siguen con sus pruebas actuales sin cambios.
