<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';
$appRealtime = app_realtime_config(isset($conn) ? $conn : null);
$appRealtime['module'] = 'dashboard';
$nombre = $_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/dashboard_effect.css">
<style>
body{margin:0;background:#050505;color:#fff;font-family:Segoe UI,sans-serif}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;padding:18px}
.card{background:rgba(15,15,15,.58);border-radius:14px;padding:14px;text-decoration:none;color:#fff;min-height:88px;display:flex;align-items:center;gap:10px}
.top{padding:18px}
@media (max-width:680px){.grid{grid-template-columns:1fr 1fr}.top{padding:14px}}
</style>
</head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>">
<div class="top"><h1>Dashboard</h1><p>Bienvenido, <?php echo htmlspecialchars($nombre,ENT_QUOTES,'UTF-8');?>.</p></div>
<div class="grid">
<a class="card" href="clientes.php"><i class="fas fa-users"></i>Clientes</a>
<a class="card" href="pedidos.php"><i class="fas fa-clipboard-list"></i>Pedidos</a>
<a class="card" href="produccion.php"><i class="fas fa-industry"></i>Producción</a>
<a class="card" href="diseno.php"><i class="fas fa-palette"></i>Diseño</a>
<a class="card" href="productos.php"><i class="fas fa-shirt"></i>Productos</a>
<a class="card" href="reportes.php"><i class="fas fa-chart-line"></i>Reportes</a>
<a class="card" href="usuarios.php"><i class="fas fa-user-cog"></i>Usuarios</a>
<a class="card" href="logout.php"><i class="fas fa-power-off"></i>Salir</a>
</div>
<script src="assets/js/app_realtime.js"></script>
</body></html>
