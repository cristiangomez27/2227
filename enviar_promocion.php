<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'mensaje'=>'Método no permitido']);
    exit;
}
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'mensaje'=>'Sesión no válida']);
    exit;
}
require_once __DIR__ . '/includes/mensajeria_helper.php';
require_once 'config/database.php';

$mensaje = trim($_POST['mensaje'] ?? '');
if ($mensaje === '') {
    echo json_encode(['ok'=>false,'mensaje'=>'Mensaje vacío']);
    exit;
}

$telefonos = [];
$q = @$conn->query("SELECT telefono FROM clientes WHERE telefono IS NOT NULL AND telefono <> '' LIMIT 200");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $telefonos[] = $r['telefono'];
    }
}

if (empty($telefonos)) {
    echo json_encode(['ok'=>false,'mensaje'=>'No hay teléfonos disponibles para envío']);
    exit;
}

$enviados=0; $errores=[];
foreach ($telefonos as $tel) {
    $resp = mensajeria_enviar_whatsapp((string)$tel, $mensaje);
    if (!empty($resp['ok'])) { $enviados++; }
    else { $errores[] = ['telefono'=>$tel,'error'=>$resp['mensaje'] ?? 'Error']; }
}

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
@file_put_contents($logDir . '/promociones_whatsapp.log', json_encode([
    'fecha'=>date('c'),
    'usuario'=>$_SESSION['usuario_id'],
    'enviados'=>$enviados,
    'errores'=>$errores
], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

echo json_encode(['ok'=>true,'enviados'=>$enviados,'errores'=>$errores], JSON_UNESCAPED_UNICODE);
