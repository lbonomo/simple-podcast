# Ability MCP: `lb-simple-podcast/publish-post-audio`

Adjunta un MP3 a una entrada existente para que aparezca como episodio en `/feed/podcast/`.

## Cómo se expone

El plugin registra una **ability** con la Abilities API de WordPress (≥ 6.9) en el hook `wp_abilities_api_init`, dentro de la categoría `podcast`. El plugin oficial [`mcp-adapter`](https://github.com/WordPress/mcp-adapter) la publica como tool MCP porque lleva `meta.mcp.public = true`. No existe una función `mcp_register_tool()`: el registro depende solo de `function_exists( 'wp_register_ability' )`. Si `mcp-adapter` no está activo, la ability sigue disponible por la Abilities API y REST.

| Propiedad | Valor |
| --- | --- |
| Nombre | `lb-simple-podcast/publish-post-audio` |
| Categoría | `podcast` |
| `meta.mcp` | `public: true`, `type: tool` |
| Anotaciones | `readonly: false`, `destructive: true` (reemplaza el audio anterior), `idempotent: false` |

## Entrada

| Campo | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `post_id` | integer ≥ 1 | sí | ID de la entrada. |
| `audio_url` | string | uno de los dos | URL http(s) pública de un MP3. |
| `audio_base64` | string | uno de los dos | MP3 en base64 (admite `data:audio/mpeg;base64,`). |
| `filename` | string | no | Nombre sugerido (con `audio_base64`). Se fuerza la extensión `.mp3`. |
| `title` | string | no | Título del attachment en la biblioteca. |

Se rechazan propiedades desconocidas (`additionalProperties: false`). Hay que enviar **exactamente uno** de `audio_url` o `audio_base64`.

## Salida

```json
{
  "post_id": 32,
  "attachment_id": 34,
  "url": "http://localhost:8888/wp-content/uploads/2026/11/mcp.mp3",
  "mime_type": "audio/mpeg",
  "filesize": 16571,
  "duration": 2,
  "replaced_attachment_id": 33,
  "permalink": "http://localhost:8888/2026/11/11/episodio-demo-prueba/",
  "feed_url": "http://localhost:8888/feed/podcast/"
}
```

`replaced_attachment_id` es el attachment que tenía la entrada (o `null`). Solo se borra del disco si lo había creado este plugin.

## Permisos

El usuario autenticado (p. ej. por Application Password) necesita `upload_files` y `edit_post` sobre la entrada. Todo lo demás devuelve un error de permiso genérico. Una entrada inexistente se trata igual que una sin permiso para no revelar si existe.

## Errores

| Código | Causa |
| --- | --- |
| `lb_sp_missing_audio` | No se envió `audio_url` ni `audio_base64`. |
| `lb_sp_conflicting_audio` | Se enviaron ambos. |
| `lb_sp_invalid_url` | URL no http(s), con credenciales o hacia una red privada/loopback (SSRF). |
| `lb_sp_download_failed` | La descarga falló o respondió distinto de 200. |
| `lb_sp_file_too_large` | Supera el máximo permitido por PHP/WordPress/memoria. |
| `lb_sp_invalid_base64` | Base64 vacío o inválido. |
| `lb_sp_invalid_mime` | El contenido real no es `audio/mpeg`. |
| `lb_sp_invalid_audio` | No se pudo leer la duración (archivo dañado). |
| `lb_sp_invalid_post` | La entrada no existe o no es un `post`. |
| `lb_sp_meta_failed` | No se pudo asociar el audio. |

Los errores de validación del esquema y de permisos los genera la Abilities API.

## Ejemplo con `mcp-adapter` (JSON-RPC por HTTP)

Endpoint del servidor por defecto: `/wp-json/mcp/mcp-adapter-default-server`.

```bash
URL=http://localhost:8888/wp-json/mcp/mcp-adapter-default-server
AUTH='usuario:application password'

# 1. Inicializar y capturar el id de sesión.
SID=$(curl -k -s -D - -o /dev/null -u "$AUTH" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' \
  "$URL" | grep -i mcp-session-id | cut -d' ' -f2 | tr -d '\r')

# 2. Ejecutar la ability con la tool genérica del adapter.
curl -k -s -u "$AUTH" -H 'Content-Type: application/json' \
  -H "Mcp-Session-Id: $SID" -H 'MCP-Protocol-Version: 2025-06-18' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"lb-simple-podcast/publish-post-audio","parameters":{"post_id":32,"audio_url":"https://example.org/ep1.mp3","title":"Episodio 1"}}}}' \
  "$URL"
```

El servidor por defecto expone tres tools genéricas (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`); la ability aparece en `discover-abilities`.
