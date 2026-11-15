# IP del cliente, proxies y privacidad: análisis de alternativas

Estado: análisis (tarea T3 de `.kiro/specs/security-hardening/tasks.md`). No hay código de este documento implementado todavía.

## Situación actual

| Uso | Dónde | Fuente de la IP |
| --- | --- | --- |
| Throttling por IP | `RequestThrottle::client_ip()` | `REMOTE_ADDR`, ajustable con el filtro `lb_sp_client_ip`. Se descarta si no es una IP válida. |
| Columna `remote_addr` del log | `RequestLog::record()` | `REMOTE_ADDR` directo, sin pasar por el filtro. |
| Cabeceras guardadas | `AudioDelivery::request_headers()` | Se guardan todas, incluidas `X-Forwarded-For` y `CF-Connecting-IP`, tal como llegan del cliente. |

Consecuencias:

1. Detrás de un proxy inverso o CDN, `REMOTE_ADDR` es la IP del proxy. El throttling trata a todos los oyentes como un solo cliente y puede bloquearlos a todos a la vez. El log y las estadísticas pierden la IP real.
2. Aunque un administrador use `lb_sp_client_ip` para corregir el throttling, el log sigue guardando la IP del proxy. Los dos datos pueden divergir.
3. Las cabeceras de reenvío se guardan sin validar. Un cliente puede enviar `X-Forwarded-For: 1.2.3.4` y quedará en el log (columna `headers`) como si fuera un dato del servidor. Hoy no se usa para ninguna decisión, pero un lector humano de los logs puede confundirse.

## Riesgo central: falsificación

`X-Forwarded-For`, `Forwarded`, `X-Real-IP` y `CF-Connecting-IP` son cabeceras HTTP que cualquier cliente puede enviar. Solo son confiables si las agrega o reescribe un proxy de confianza.

- Cada proxy agrega al final de `X-Forwarded-For` la IP de la que recibió la conexión. El valor de más a la izquierda lo controla el cliente. El de más a la derecha lo agregó el proxy más cercano al servidor.
- Si el plugin lee la IP de la izquierda, o de una cabecera sin comprobar quién envió la conexión, un atacante elige su IP. Con el throttling eso significa evadir el límite rotando valores falsos, o bloquear a una víctima concreta enviando su IP.
- Regla: se acepta una cabecera de reenvío solo cuando `REMOTE_ADDR` pertenece a un proxy de confianza.

## Alternativas

### A. Solo `REMOTE_ADDR` (comportamiento actual)

- **Ventajas:** no se puede falsificar. Sin configuración. Correcto en hosting sin proxy.
- **Desventajas:** incorrecto detrás de CDN o proxy. Con un CDN el throttling puede cortar el servicio a todos.
- **Veredicto:** válido como valor por defecto, insuficiente por sí solo.

### B. Lista de proxies de confianza configurable (CIDR)

Se define una lista de rangos. Si `REMOTE_ADDR` está en la lista, se recorre `X-Forwarded-For` de derecha a izquierda y se toma la primera IP que no sea de un proxy de confianza.

- **Ventajas:** cubre cualquier arquitectura (Nginx, balanceadores, CDN). Es el método más seguro de los que leen cabeceras.
- **Desventajas:** requiere configuración. Si los rangos del CDN cambian, hay que mantenerlos.
- **Veredicto:** recomendado como opción general.

### C. Cabeceras específicas de CDN (`CF-Connecting-IP`, `True-Client-IP`, etc.)

Se confía en una cabecera concreta cuando la petición llega desde los rangos publicados del CDN.

- **Ventajas:** simple y exacta con ese CDN.
- **Desventajas:** una opción distinta por proveedor. Si el origen es accesible directamente sin pasar por el CDN, la cabecera se falsifica. Hay que restringir el firewall del origen a los rangos del CDN.
- **Veredicto:** se resuelve como un caso particular de B (rangos de confianza más nombre de cabecera), sin lógica propia por proveedor.

### D. Confiar en cualquier cabecera de reenvío

- **Veredicto:** descartado. Es trivial de falsificar.

### E. Delegar al servidor web

Nginx (`real_ip_module`) y Apache (`mod_remoteip`) pueden reescribir `REMOTE_ADDR` con la IP real a partir de una lista de proxies de confianza.

- **Ventajas:** el plugin no cambia, y también corrige a WordPress y a otros plugins. La validación vive en el servidor.
- **Desventajas:** depende de que el administrador tenga acceso a esa configuración (no siempre en hosting compartido).
- **Veredicto:** es la mejor recomendación operativa. Se documenta como primera opción cuando es posible.

### F. Anonimizar la IP antes de guardarla

Se guarda la IPv4 sin el último octeto (`192.0.2.0`) y la IPv6 recortada a los primeros 48 bits. Es un dato personal menos en la base de datos.

- **Ventajas:** reduce el impacto de una fuga del log y las obligaciones de privacidad. Sigue sirviendo para estadísticas agregadas.
- **Desventajas:** pierde precisión para investigar abusos puntuales. No afecta al throttling, que usa la IP completa solo en un contador temporal (vida de 60 segundos) y no la persiste en la tabla.
- **Veredicto:** ofrecerla como ajuste, con la anonimización activada por defecto o no según la decisión de producto.

## Impacto en las estadísticas

- Las estadísticas actuales (`RequestStats`) cuentan solicitudes y episodios. No agrupan por IP, así que no cambian con ninguna alternativa.
- Si en el futuro se cuentan "oyentes únicos", la IP detrás de un proxy sin resolver sería la misma para todos y subestimaría el dato. Con B o E, y con F, el recuento seguiría siendo aproximado (IPs compartidas, NAT).

## Recomendación

1. Mantener `REMOTE_ADDR` como valor por defecto.
2. Implementar la alternativa B con un único punto de resolución, `RequestThrottle::client_ip()`, usado por el throttling y por el log. Ajustes: lista de rangos de confianza (constante `LB_SP_TRUSTED_PROXIES` o filtro) y, opcionalmente, nombre de cabecera (`X-Forwarded-For` por defecto, o `CF-Connecting-IP`).
3. Documentar la opción E en `docs/setup.md` como primera opción para quien controle el servidor.
4. Añadir la alternativa F como ajuste de anonimización de la columna `remote_addr`.
5. Dejar de guardar sin marca las cabeceras de reenvío no verificadas, o al menos marcar en la pantalla de logs que provienen del cliente.

## Tarea de implementación derivada

Ver T3b en `.kiro/specs/security-hardening/tasks.md`.
