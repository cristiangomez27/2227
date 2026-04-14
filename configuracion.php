<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';
$appRealtime = app_realtime_config(isset($conn) ? $conn : null);
$appRealtime['module'] = 'configuracion';
$mensaje='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $sonidos = isset($_POST['sonidos_activos']) ? 1 : 0;
    @$conn->query("UPDATE configuracion SET sonidos_activos={$sonidos} WHERE id=1");
    $mensaje='Configuración guardada.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuración</title><link rel="stylesheet" href="assets/css/dashboard_effect.css"><style>body{margin:0;background:#050505;color:#fff;font-family:Segoe UI}main{max-width:760px;margin:30px auto;padding:16px}.card{background:rgba(15,15,15,.58);padding:18px;border-radius:12px}a{color:#c89b3c}</style></head><body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>"><main><div class="card"><h2>Configuración</h2><?php if($mensaje):?><p><?php echo htmlspecialchars($mensaje,ENT_QUOTES,'UTF-8');?></p><?php endif;?><form method="post"><label><input type="checkbox" name="sonidos_activos" value="1" checked> Sonidos activos</label><br><br><button type="submit">Guardar</button></form><p><a href="dashboard.php">Volver al dashboard</a></p></div></main><script src="assets/js/app_realtime.js"></script></body></html>
