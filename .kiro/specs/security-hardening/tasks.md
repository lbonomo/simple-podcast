# Tareas de endurecimiento de seguridad

Origen: auditoría de seguridad (noviembre 2026). Orden de ejecución: T1 primero.

## T1. Restringir todo el menú Podcast a administradores (PRIORITARIA)

- [x] Hoy `Menu::CAPABILITY` es `manage_options`. Confirmar que cubre solo a administradores (en multisite, a los admins de cada sitio) y decidir si también al super admin.
- [x] Aplicar la restricción de forma uniforme: menú principal, submenús (Dashboard, Setup, Logs), `enqueue` de `logs.js`, `row_meta` y cualquier otra pantalla o endpoint del plugin (`SettingsPage`, `MetaBox`, `EditorAssets`).
- [x] Reemplazar el `return` silencioso de `render()` en `DashboardPage`, `LogsPage` y `SettingsPage` por `wp_die()` con código 403.
- [x] Capacidad propia: descartada. Se usa `manage_options` (administradores) como única fuente en `Menu::CAPABILITY`.
- [x] Verificar que ningún REST, AJAX ni ability expone datos de la tabla `lb_sp_audio_requests`.
- [x] Tests: un editor, un autor y un anónimo reciben 403 en cada pantalla; un administrador accede.

## T2. Throttling del lado servidor y registro solo de enlaces válidos

- [x] Registrar en la base de datos únicamente solicitudes que resuelven a un audio válido (post del tipo correcto, attachment coincidente, estado publicado). No insertar para 404, 405 ni parámetros inventados.
- [x] Throttling barato en PHP/WordPress: contador por IP en transient u object cache, con ventana corta. No consultar la base de datos para decidir.
- [x] Definir el umbral por defecto, qué respuesta se da al superar el límite (429 con `Retry-After`) y si esas respuestas se registran.
- [x] Definir la fuente de la IP (`REMOTE_ADDR` por defecto, filtro para proxies de confianza).
- [x] Topes de tamaño para `request_url` (2048) y `headers` (4 KB).
- [x] Purga automática por antigüedad (WP-Cron) con retención configurable.
- [x] Tests: no se inserta para enlaces inválidos; se aplica el límite; la purga elimina solo lo antiguo.

## T3. Analizar alternativas para IP y cabeceras de proxy

- [x] Comparar: solo `REMOTE_ADDR`; lista de proxies de confianza configurable; cabeceras de CDN (`CF-Connecting-IP`); anonimizar la IP (último octeto) antes de guardarla.
- [x] Documentar la recomendación, el impacto en estadísticas y el riesgo de falsificación de `X-Forwarded-For`.
- [x] Resultado: `docs/ip-cliente-y-proxies.md` y, si corresponde, una tarea de implementación.

## T3b. Resolver la IP del cliente con proxies de confianza

Derivada de T3 (ver `docs/ip-cliente-y-proxies.md`).

- [ ] Usar `RequestThrottle::client_ip()` también en `RequestLog::record()` para que throttling y log usen la misma IP.
- [ ] Aceptar cabeceras de reenvío solo si `REMOTE_ADDR` está en una lista de proxies de confianza (CIDR IPv4 e IPv6), recorriendo `X-Forwarded-For` de derecha a izquierda.
- [ ] Configuración por constante `LB_SP_TRUSTED_PROXIES` y filtro; nombre de cabecera configurable.
- [ ] Opción para anonimizar la IP guardada (IPv4 sin el último octeto, IPv6 a /48).
- [ ] Documentar en `docs/setup.md` la opción de `real_ip` en Nginx y `mod_remoteip` en Apache.
- [ ] Tests: IP falsificada ignorada sin proxy de confianza; IP real tomada con proxy de confianza; IPv6; anonimización.

## T4. Proteger la rama `main`

- [x] Activar protección de rama: pull request obligatorio, checks de `test.yml` obligatorios, sin force push ni borrado.
- [ ] Exigir 2FA en la cuenta de GitHub y revisar los tokens con acceso al repo (manual: no se puede verificar por API).
- [x] Proteger los tags de release.
- [ ] (Fuera de alcance por ahora) Evaluar publicar un checksum del ZIP del release y verificarlo en el actualizador (`plugin-update-checker`).
- [ ] Nota: el flujo de release de `AGENTS.md` hace merge directo del PR. Ajustarlo si se exige aprobación.

Aplicado el 15/11/2026 con `gh api`:

- `main`: PR obligatorio (0 aprobaciones), 6 checks obligatorios con rama al día, sin force push ni borrado, reglas también para administradores.
- Ruleset `protect-release-tags` (id 27331472): impide actualizar, borrar o reescribir tags existentes; crear tags nuevos sigue permitido.
- Borrado automático de ramas fusionadas activado.

## T5. Mejorar el registro de errores del plugin y permitir marcarlo como leído

Reemplaza el aviso fijo basado en `lb_sp_log_error`.

- [ ] Guardar cada error con más información: fecha (GMT), origen (inserción de solicitud, migración de tabla, entrega de audio), motivo (`$wpdb->last_error` o mensaje equivalente), `post_id` y `attachment_id` cuando existan. No guardar cabeceras ni cookies.
- [ ] Persistir los errores en una opción con límite (por ejemplo, los últimos 50), sin autoload, o en una tabla propia si hace falta histórico.
- [ ] Mostrar el registro de errores en la pantalla de Settings del plugin: fecha, origen, motivo y estado (nuevo o leído).
- [ ] Permitir marcar un error como leído, todos como leídos, y vaciar el registro. Cada acción con nonce y comprobación de capacidad (ver T1).
- [ ] El aviso del admin solo aparece si hay errores sin leer, con enlace a Settings y el número de pendientes. Desaparece al marcarlos como leídos.
- [ ] Limpiar o resolver automáticamente el estado cuando `RequestLog::upgrade()` termina bien.
- [ ] Escapar toda la salida (`esc_html`); los motivos pueden incluir texto de MySQL.
- [ ] Migrar `lb_sp_log_error` a la nueva estructura y borrar las opciones nuevas en `uninstall.php`.
- [ ] Tests: se registra un error con sus datos, se respeta el límite, marcar como leído oculta el aviso, y un usuario sin permiso no puede ejecutar las acciones.
