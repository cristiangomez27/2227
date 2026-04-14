<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';
$appRealtime = app_realtime_config($conn);
$appRealtime['module'] = 'proveedores_facturas_control';

$conn->query("CREATE TABLE IF NOT EXISTS proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(140) NOT NULL,
    telefono VARCHAR(40) DEFAULT NULL,
    correo VARCHAR(120) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS facturas_proveedor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proveedor_id INT NOT NULL,
    numero_factura VARCHAR(100) DEFAULT NULL,
    fecha_factura DATE NOT NULL,
    concepto VARCHAR(255) DEFAULT NULL,
    monto DECIMAL(12,2) NOT NULL DEFAULT 0,
    observaciones TEXT NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_proveedor_id (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['guardar_proveedor'])) {
    $n = trim($_POST['nombre'] ?? '');
    if ($n !== '') {
        $t = trim($_POST['telefono'] ?? '');
        $c = trim($_POST['correo'] ?? '');
        $st=$conn->prepare("INSERT INTO proveedores (nombre,telefono,correo,activo) VALUES (?,?,?,1)");
        $st->bind_param('sss',$n,$t,$c); $st->execute(); $st->close();
        $msg='Proveedor guardado.';
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['guardar_factura'])) {
    $pid = (int)($_POST['proveedor_id'] ?? 0);
    $num = trim($_POST['numero_factura'] ?? '');
    $fec = trim($_POST['fecha_factura'] ?? date('Y-m-d'));
    $con = trim($_POST['concepto'] ?? '');
    $mon = (float)($_POST['monto'] ?? 0);
    $obs = trim($_POST['observaciones'] ?? '');
    if ($pid>0 && $mon>0) {
        $st=$conn->prepare("INSERT INTO facturas_proveedor (proveedor_id,numero_factura,fecha_factura,concepto,monto,observaciones) VALUES (?,?,?,?,?,?)");
        $st->bind_param('isssds',$pid,$num,$fec,$con,$mon,$obs); $st->execute(); $st->close();
        $msg='Factura guardada.';
    }
}

$proveedores=[]; $q=$conn->query("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre"); if($q) while($r=$q->fetch_assoc()) $proveedores[]=$r;
$facturas=[]; $q2=$conn->query("SELECT f.*,p.nombre proveedor FROM facturas_proveedor f JOIN proveedores p ON p.id=f.proveedor_id ORDER BY f.id DESC LIMIT 50"); if($q2) while($r=$q2->fetch_assoc()) $facturas[]=$r;

$mes=date('Y-m');
$stV=$conn->prepare("SELECT COALESCE(SUM(total),0) t FROM ventas WHERE DATE_FORMAT(fecha,'%Y-%m')=?"); $stV->bind_param('s',$mes); $stV->execute(); $ventasMes=(float)$stV->get_result()->fetch_assoc()['t']; $stV->close();
$stC=$conn->prepare("SELECT COALESCE(SUM(monto),0) t FROM facturas_proveedor WHERE DATE_FORMAT(fecha_factura,'%Y-%m')=?"); $stC->bind_param('s',$mes); $stC->execute(); $comprasMes=(float)$stC->get_result()->fetch_assoc()['t']; $stC->close();
$gananciaMes=$ventasMes-$comprasMes;

if(isset($_GET['export'])&&$_GET['export']==='csv'){
 header('Content-Type:text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=facturas_proveedor.csv'); $o=fopen('php://output','w'); fputcsv($o,['Proveedor','No Factura','Fecha','Concepto','Monto']); foreach($facturas as $f){fputcsv($o,[$f['proveedor'],$f['numero_factura'],$f['fecha_factura'],$f['concepto'],$f['monto']]);} fclose($o); exit;
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Control Proveedores y Facturas</title><link rel="stylesheet" href="assets/css/dashboard_effect.css"><style>body{margin:0;background:#050505;color:#fff;font-family:Segoe UI}main{max-width:1200px;margin:16px auto;padding:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px}.card{background:rgba(15,15,15,.6);padding:14px;border-radius:12px}input,select,textarea{width:100%;padding:9px;border-radius:8px;border:1px solid #333;background:#111;color:#fff}button{padding:10px 13px;border:none;border-radius:8px;background:#c89b3c;color:#111;font-weight:700}table{width:100%;border-collapse:collapse}.table-wrap{overflow:auto;-webkit-overflow-scrolling:touch}th,td{padding:8px;border-bottom:1px solid #2a2a2a}@media (max-width:768px){main{padding:10px}.grid{grid-template-columns:1fr}button{width:100%}}</style></head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>">
<main>
<div class="card" style="margin-bottom:10px;font-size:13px;color:#cbd5e1">Conectado a WebSocket y optimizado para móvil/tablet.</div>
<h1>Proveedores y Facturas conectados a ventas/ganancias por mes</h1>
<?php if($msg):?><div class="card" style="margin-bottom:10px;color:#86efac"><?php echo htmlspecialchars($msg,ENT_QUOTES,'UTF-8');?></div><?php endif; ?>
<div class="grid"><section class="card"><div>Ventas del mes</div><h2>$<?php echo number_format($ventasMes,2); ?></h2></section><section class="card"><div>Pagos a proveedores del mes</div><h2>$<?php echo number_format($comprasMes,2); ?></h2></section><section class="card"><div>Ganancia del mes</div><h2>$<?php echo number_format($gananciaMes,2); ?></h2></section><section class="card"><a href="?export=csv" style="color:#c89b3c">Descargar Excel (CSV)</a></section></div>

<div class="grid" style="margin-top:12px">
<section class="card"><h3>Ingresar proveedor</h3><form method="post"><input type="hidden" name="guardar_proveedor" value="1"><label>Nombre</label><input name="nombre" required><label>Teléfono</label><input name="telefono"><label>Correo</label><input name="correo" type="email"><br><button>Guardar proveedor</button></form></section>
<section class="card"><h3>Ingresar factura</h3><form method="post"><input type="hidden" name="guardar_factura" value="1"><label>Proveedor</label><select name="proveedor_id" required><option value="">Seleccionar</option><?php foreach($proveedores as $p):?><option value="<?php echo (int)$p['id'];?>"><?php echo htmlspecialchars($p['nombre'],ENT_QUOTES,'UTF-8');?></option><?php endforeach;?></select><label>No. Factura</label><input name="numero_factura"><label>Fecha</label><input type="date" name="fecha_factura" value="<?php echo date('Y-m-d');?>"><label>Concepto</label><input name="concepto"><label>Monto</label><input type="number" step="0.01" name="monto" required><label>Observaciones</label><textarea name="observaciones"></textarea><br><button>Guardar factura</button></form></section>
</div>

<section class="card" style="margin-top:12px"><h3>Últimas facturas</h3><div class="table-wrap"><table><thead><tr><th>Proveedor</th><th>Factura</th><th>Fecha</th><th>Concepto</th><th>Monto</th></tr></thead><tbody><?php foreach($facturas as $f):?><tr><td><?php echo htmlspecialchars($f['proveedor'],ENT_QUOTES,'UTF-8');?></td><td><?php echo htmlspecialchars((string)$f['numero_factura'],ENT_QUOTES,'UTF-8');?></td><td><?php echo htmlspecialchars((string)$f['fecha_factura'],ENT_QUOTES,'UTF-8');?></td><td><?php echo htmlspecialchars((string)$f['concepto'],ENT_QUOTES,'UTF-8');?></td><td>$<?php echo number_format((float)$f['monto'],2);?></td></tr><?php endforeach;?></tbody></table></div></section>
</main>
<script src="assets/js/app_realtime.js"></script>
</body></html>
