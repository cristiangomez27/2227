<?php
/**
 * Integrador de guardado de venta.
 * Conserva intacta la lógica original si el archivo base está presente,
 * agregando compatibilidad de nomenclatura de constantes Green API.
 */

session_start();
require_once __DIR__ . '/config/database.php';

// Compatibilidad de constantes Green API (ambas nomenclaturas)
if (defined('GREENAPI_INSTANCE') && !defined('GREEN_API_INSTANCE_ID')) {
    define('GREEN_API_INSTANCE_ID', (string)GREENAPI_INSTANCE);
}
if (defined('GREEN_API_INSTANCE_ID') && !defined('GREENAPI_INSTANCE')) {
    define('GREENAPI_INSTANCE', (string)GREEN_API_INSTANCE_ID);
}
if (defined('GREENAPI_TOKEN') && !defined('GREEN_API_TOKEN')) {
    define('GREEN_API_TOKEN', (string)GREENAPI_TOKEN);
}
if (defined('GREEN_API_TOKEN') && !defined('GREENAPI_TOKEN')) {
    define('GREENAPI_TOKEN', (string)GREEN_API_TOKEN);
}

$posiblesGuardar = [
    __DIR__ . '/guardar_venta_original.php',
    __DIR__ . '/legacy/guardar_venta.php',
    __DIR__ . '/modulos/ventas/guardar_venta.php',
    __DIR__ . '/src/guardar_venta.php',
];

foreach ($posiblesGuardar as $ruta) {
    if (is_file($ruta)) {
        require_once $ruta;
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(503);
echo json_encode([
    'ok' => false,
    'mensaje' => 'No se encontró guardar_venta funcional para integrar en este entorno.',
    'rutas_esperadas' => [
        'guardar_venta_original.php',
        'legacy/guardar_venta.php',
        'modulos/ventas/guardar_venta.php',
        'src/guardar_venta.php'
    ]
], JSON_UNESCAPED_UNICODE);
