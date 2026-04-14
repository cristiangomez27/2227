<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';
$appRealtime = app_realtime_config($conn);
$appRealtime['module'] = 'analitica_finanzas';

function exT(mysqli $c,string $t):bool{ $t=$c->real_escape_string($t); $r=$c->query("SHOW TABLES LIKE '{$t}'"); return $r&&$r->num_rows>0; }

$rows = [];
for ($i=11; $i>=0; $i--) {
    $inicio = date('Y-m-01', strtotime("-$i months"));
    $fin = date('Y-m-t', strtotime($inicio));
    $label = date('M Y', strtotime($inicio));

    $ventas = 0.0; $compras = 0.0;
    if (exT($conn,'ventas')) {
        $st = $conn->prepare("SELECT COALESCE(SUM(total),0) t FROM ventas WHERE DATE(fecha) BETWEEN ? AND ?");
        $st->bind_param('ss',$inicio,$fin); $st->execute(); $ventas = (float)($st->get_result()->fetch_assoc()['t'] ?? 0); $st->close();
    }
    if (exT($conn,'facturas_proveedor')) {
        $st = $conn->prepare("SELECT COALESCE(SUM(monto),0) t FROM facturas_proveedor WHERE DATE(fecha_factura) BETWEEN ? AND ?");
        $st->bind_param('ss',$inicio,$fin); $st->execute(); $compras = (float)($st->get_result()->fetch_assoc()['t'] ?? 0); $st->close();
    } elseif (exT($conn,'facturas_gastos')) {
        $st = $conn->prepare("SELECT COALESCE(SUM(monto),0) t FROM facturas_gastos WHERE DATE(fecha) BETWEEN ? AND ?");
        $st->bind_param('ss',$inicio,$fin); $st->execute(); $compras = (float)($st->get_result()->fetch_assoc()['t'] ?? 0); $st->close();
    }

    $rows[] = ['mes'=>$label,'ventas'=>$ventas,'compras'=>$compras,'ganancia'=>$ventas-$compras];
}

if (isset($_GET['export']) && $_GET['export']==='csv') {
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=finanzas_mensual.csv');
    $o=fopen('php://output','w');
    fputcsv($o,['Mes','Ventas','Compras','Ganancia']);
    foreach($rows as $r){ fputcsv($o,[$r['mes'],$r['ventas'],$r['compras'],$r['ganancia']]); }
    fclose($o); exit;
}

$totalVentas = array_sum(array_column($rows,'ventas'));
$totalCompras = array_sum(array_column($rows,'compras'));
$totalGanancia = array_sum(array_column($rows,'ganancia'));
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Analítica de Finanzas</title>
<link rel="stylesheet" href="assets/css/dashboard_effect.css"><script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>body{margin:0;background:#050505;color:#fff;font-family:Segoe UI}main{max-width:1200px;margin:18px auto;padding:14px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.card{background:rgba(15,15,15,.6);padding:14px;border-radius:12px}.kpi{font-size:28px;color:#c89b3c;font-weight:800}@media (max-width:768px){main{padding:10px}.kpi{font-size:24px}}</style></head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>">
<main>
  <div class="card ws-hint" style="margin-bottom:10px;font-size:13px;color:#cbd5e1">WebSocket activo para actualizaciones en tiempo real (si el servidor WS está disponible).</div>
  <h1>Cuentas de ventas / ganancias / pagos a proveedores</h1>
  <div class="grid">
    <section class="card"><div>Total ventas (12m)</div><div class="kpi">$<?php echo number_format($totalVentas,2); ?></div></section>
    <section class="card"><div>Total compras/proveedores (12m)</div><div class="kpi">$<?php echo number_format($totalCompras,2); ?></div></section>
    <section class="card"><div>Total ganancias (12m)</div><div class="kpi">$<?php echo number_format($totalGanancia,2); ?></div></section>
    <section class="card"><a href="?export=csv" style="color:#c89b3c">Descargar en Excel (CSV)</a></section>
  </div>
  <section class="card" style="margin-top:12px"><canvas id="finanzasChart"></canvas></section>
</main>
<script>
const dataRows = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE); ?>;
new Chart(document.getElementById('finanzasChart'), {
  type:'bar',
  data:{ labels:dataRows.map(r=>r.mes), datasets:[
    {label:'Ventas', data:dataRows.map(r=>r.ventas), backgroundColor:'#22c55e'},
    {label:'Compras', data:dataRows.map(r=>r.compras), backgroundColor:'#ef4444'},
    {label:'Ganancia', data:dataRows.map(r=>r.ganancia), backgroundColor:'#c89b3c'}
  ]},
  options:{responsive:true,plugins:{legend:{labels:{color:'#fff'}}},scales:{x:{ticks:{color:'#ddd'}},y:{ticks:{color:'#ddd'}}}}
});
</script>
<script src="assets/js/app_realtime.js"></script>
</body></html>
