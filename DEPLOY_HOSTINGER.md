# Checklist final de despliegue (Hostinger)

## 1) Valores a configurar

### MySQL Hostinger
Configura estas variables en el entorno (o en `database.php` si usas config fija):

- `DB_HOST` = host MySQL de Hostinger (ej. `srv1234.hstgr.io`)
- `DB_PORT` = `3306`
- `DB_NAME` = nombre de tu base (ej. `uXXXX_db`)
- `DB_USER` = usuario DB (ej. `uXXXX_user`)
- `DB_PASS` = contraseña DB
- `APP_URL` = URL final del sistema (ej. `https://tu-dominio.com`)

### Base/rutas del sistema
- Document root: `public_html/`
- `private/secure_greenapi.php` debe existir fuera o protegido según tu estructura.
- Carpetas requeridas (si no existen):
  - `uploads/disenos/originales`
  - `uploads/disenos/preview`
  - `uploads/disenos/descargas`
  - `uploads/sonidos`
  - `uploads/logos`
  - `uploads/fondos`
  - `logs`
  - `cache`
  - `temp`

### WebSocket (entorno real)
Opcional por variables:
- `WEBSOCKET_URL` = `wss://tu-dominio.com:8080`
- `WEBSOCKET_ENABLED` = `true`

Si no defines `WEBSOCKET_URL`, el sistema calcula `ws://host:8080` o `wss://host:8080` automáticamente.

### Green API (entorno real)
En `private/secure_greenapi.php` deben existir:
- `GREENAPI_INSTANCE`
- `GREENAPI_TOKEN`

> No se modifica el archivo; solo se consume externamente.

---

## 2) Archivos a subir
Sube todo el proyecto, incluyendo:
- PHP de raíz
- `assets/`
- `config/`, `helpers/`, `includes/`, `modulos/`, `ajax/`
- `database/migrations/20260414_diseno_modulo.sql`

## 3) Permisos recomendados
- Carpetas: `755`
- Archivos: `644`
- Escritura obligatoria para runtime:
  - `uploads/`
  - `logs/`
  - `cache/`
  - `temp/`

## 4) Configuración de BD
1. Crear BD en Hostinger.
2. Importar estructura base del sistema.
3. Ejecutar migración:
   - `database/migrations/20260414_diseno_modulo.sql`
4. Verificar tabla `configuracion` con `id=1` y campos de sonido/websocket si existen.

## 5) Prueba rápida de login
1. Abrir `index.php`
2. Iniciar sesión con usuario válido
3. Verificar redirección a `dashboard.php`

## 6) Prueba rápida de Diseño
1. Entrar a `diseno.php` con rol ADMIN o PRODUCCIÓN
2. Subir PNG/JPG/WEBP
3. Ver vista previa
4. Descargar archivo y validar calidad original
5. Verificar registro en `disenos_archivos`

## 7) Prueba rápida de Promociones WhatsApp
1. Abrir `promociones_whatsapp.php`
2. Escribir mensaje y enviar
3. Confirmar respuesta en pantalla (enviados/errores)
4. Revisar log: `logs/promociones_whatsapp.log`

## 8) Prueba rápida de WebSocket
1. Levantar servidor websocket en puerto 8080 (o URL definida)
2. Abrir dashboard/pedidos/producción/diseño
3. Ver badge: “Tiempo real conectado”
4. Simular evento y validar toast/actualización

## 9) Manejo de errores controlado
- Si falla MySQL: respuesta controlada 503 y log en `logs/php_errors.log`.
- Si falla WebSocket: estado “reconectando” sin romper UI.

## Integración de ventas/guardar_venta
Si tu flujo funcional actual está en archivos personalizados, colócalos en alguna ruta detectada por el integrador:
- `ventas_original.php` o `legacy/ventas.php` o `modulos/ventas/ventas.php` o `src/ventas.php`
- `guardar_venta_original.php` o `legacy/guardar_venta.php` o `modulos/ventas/guardar_venta.php` o `src/guardar_venta.php`

El archivo `ventas.php` y `guardar_venta.php` del paquete final no simplifican lógica; solo enrutan al archivo funcional existente.
