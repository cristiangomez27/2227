<?php
/**
 * Integrador de ventas.
 *
 * Este archivo NO reemplaza la lógica funcional de ventas:
 * - registro de venta
 * - creación automática de pedido
 * - generación de remisión
 * - envío por WhatsApp / Green API
 *
 * Solo actúa como punto de entrada estable dentro de la estructura final.
 */

session_start();

$posiblesVentas = [
    __DIR__ . '/ventas_original.php',
    __DIR__ . '/legacy/ventas.php',
    __DIR__ . '/modulos/ventas/ventas.php',
    __DIR__ . '/src/ventas.php',
];

foreach ($posiblesVentas as $ruta) {
    if (is_file($ruta)) {
        require_once $ruta;
        exit;
    }
}

http_response_code(503);
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ventas no integrado</title>
<link rel="stylesheet" href="assets/css/dashboard_effect.css">
<style>body{margin:0;background:#050505;color:#fff;font-family:Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{max-width:760px;background:rgba(15,15,15,.65);padding:18px;border-radius:12px;border:1px solid rgba(255,255,255,.15)}code{color:#f59e0b}</style>
</head><body><div class="card">
<h2>Módulo de ventas pendiente de integrar archivo base</h2>
<p>No se encontró el archivo funcional de ventas en ninguna ruta esperada.</p>
<p>Coloca tu archivo funcional en alguna de estas rutas:</p>
<ul>
<li><code>ventas_original.php</code></li>
<li><code>legacy/ventas.php</code></li>
<li><code>modulos/ventas/ventas.php</code></li>
<li><code>src/ventas.php</code></li>
</ul>
<p>Este integrador mantendrá intacta su lógica original.</p>
</div></body></html>
