<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';

function exT(mysqli $c,string $t):bool{ $t=$c->real_escape_string($t); $r=$c->query("SHOW TABLES LIKE '{$t}'"); return $r&&$r->num_rows>0; }
function cols(mysqli $c,string $t):array{ $o=[]; if(!exT($c,$t)) return $o; $r=$c->query("SHOW COLUMNS FROM `$t`"); if($r) while($x=$r->fetch_assoc()) $o[]=$x['Field']; return $o; }

$appRealtime = app_realtime_config($conn);
$appRealtime['module'] = 'dashboard';
$nombre = $_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario';

$cfg=[]; if(exT($conn,'configuracion')){ $r=$conn->query('SELECT * FROM configuracion WHERE id=1 LIMIT 1'); if($r&&$r->num_rows) $cfg=$r->fetch_assoc(); }
$logo=(string)($cfg['logo'] ?? 'logo.png');
$fondoContenido=(string)($cfg['fondo_contenido'] ?? '');
$fondoSidebar=(string)($cfg['fondo_sidebar'] ?? '');
$logoTiktok=(string)($cfg['logo_tiktok_shop'] ?? '');

$stats=['wa'=>0,'ca'=>0,'ci'=>0,'ped'=>0,'pend'=>0,'ent'=>0,'promo'=>0];
if(exT($conn,'clientes_whatsapp')){ $r=$conn->query('SELECT COUNT(*) c FROM clientes_whatsapp'); if($r)$stats['wa']=(int)$r->fetch_assoc()['c']; }
if(exT($conn,'clientes')){
  $cc=cols($conn,'clientes');
  if(in_array('activo',$cc,true)){ $r=$conn->query('SELECT SUM(activo=1) ca, SUM(activo=0) ci FROM clientes'); if($r){$x=$r->fetch_assoc();$stats['ca']=(int)$x['ca'];$stats['ci']=(int)$x['ci'];}}
  elseif(in_array('estado',$cc,true)){ $r=$conn->query("SELECT SUM(LOWER(estado)='activo') ca, SUM(LOWER(estado)<>'activo' OR estado IS NULL) ci FROM clientes"); if($r){$x=$r->fetch_assoc();$stats['ca']=(int)$x['ca'];$stats['ci']=(int)$x['ci'];}}
}
if(exT($conn,'pedidos')){
  $r=$conn->query('SELECT COUNT(*) c FROM pedidos'); if($r) $stats['ped']=(int)$r->fetch_assoc()['c'];
  $pc=cols($conn,'pedidos'); $ec=in_array('estado',$pc,true)?'estado':(in_array('estatus',$pc,true)?'estatus':'');
  if($ec!==''){ $r=$conn->query("SELECT SUM(UPPER(TRIM($ec))='ENTREGADO') ent, SUM(UPPER(TRIM($ec))<>'ENTREGADO' OR $ec IS NULL) pend FROM pedidos"); if($r){$x=$r->fetch_assoc();$stats['ent']=(int)$x['ent'];$stats['pend']=(int)$x['pend'];}}
}
if($stats['ent']===0 && exT($conn,'pedidos_entregados')){ $r=$conn->query('SELECT COUNT(*) c FROM pedidos_entregados'); if($r)$stats['ent']=(int)$r->fetch_assoc()['c']; }
$lp=__DIR__.'/logs/promociones_whatsapp.log'; if(is_file($lp)){ $lines=@file($lp, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); if(is_array($lines))$stats['promo']=count($lines);} 

$resumenPedidos=[];
if(exT($conn,'pedidos')){
  $pc=cols($conn,'pedidos'); $ec=in_array('estado',$pc,true)?'estado':(in_array('estatus',$pc,true)?'estatus':'');
  if($ec!==''){
    $q=$conn->query("SELECT UPPER(TRIM($ec)) estado, COUNT(*) c FROM pedidos GROUP BY UPPER(TRIM($ec)) ORDER BY c DESC");
    if($q) while($row=$q->fetch_assoc()) $resumenPedidos[]=$row;
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="assets/css/dashboard_effect.css">
<style>
body{margin:0;color:#fff;font-family:Segoe UI,sans-serif;background:<?php echo $fondoContenido?"linear-gradient(rgba(0,0,0,.55),rgba(0,0,0,.72)),url('".htmlspecialchars($fondoContenido,ENT_QUOTES,'UTF-8')."') center/cover fixed":"#050505";?>}
.layout{display:grid;grid-template-columns:88px 1fr;min-height:100vh}.sidebar{background:<?php echo $fondoSidebar?"linear-gradient(rgba(0,0,0,.88),rgba(0,0,0,.88)),url('".htmlspecialchars($fondoSidebar,ENT_QUOTES,'UTF-8')."') center/cover":"rgba(0,0,0,.88)";?>;display:flex;flex-direction:column;align-items:center;gap:15px;padding:14px 0;border-right:1px solid rgba(255,255,255,.1)}
.sidebar a{color:#666;font-size:20px}.sidebar a:hover,.sidebar a.active{color:#c89b3c}
.main{padding:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:12px}.card{background:rgba(15,15,15,.58);padding:14px;border-radius:12px}
.kpi{font-size:29px;color:#c89b3c;font-weight:800}.sub{color:#c8c8c8;font-size:13px}.head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.chat{display:grid;grid-template-columns:220px 1fr;gap:10px}.users{max-height:280px;overflow:auto}.msgbox{max-height:230px;overflow:auto;background:#0f0f0f;padding:10px;border-radius:8px}
.msg{margin:6px 0;padding:7px 9px;border-radius:8px;background:#1b1b1b}.me{background:#23422d}.tools{display:flex;gap:8px;margin-top:8px}
@media (max-width:900px){.chat{grid-template-columns:1fr}.layout{grid-template-columns:1fr}.sidebar{position:sticky;top:0;z-index:10;flex-direction:row;justify-content:center;padding:10px}.main{padding:10px}}
</style></head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>">
<div class="layout">
<aside class="sidebar">
<img src="<?php echo htmlspecialchars($logo,ENT_QUOTES,'UTF-8');?>" alt="logo" style="width:50px;border-radius:8px">
<a class="active" href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
<a href="clientes.php" title="Clientes"><i class="fas fa-users"></i></a>
<a href="pedidos.php" title="Pedidos"><i class="fas fa-clipboard-list"></i></a>
<a href="produccion.php" title="Producción"><i class="fas fa-industry"></i></a>
<a href="pedidos_entregados.php" title="Entregados"><i class="fas fa-box-open"></i></a>
<a href="diseno.php" title="Diseño"><i class="fas fa-palette"></i></a>
<a href="promociones_whatsapp.php" title="Promociones"><i class="fab fa-whatsapp"></i></a>
<a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
<a href="usuarios.php" title="Usuarios"><i class="fas fa-user-cog"></i></a>
<a href="logout.php" title="Salir"><i class="fas fa-power-off"></i></a>
</aside>
<main class="main">
<div class="head card"><div><h1 style="margin:.1rem 0">Dashboard</h1><div class="sub">Hola, <?php echo htmlspecialchars($nombre,ENT_QUOTES,'UTF-8');?> · Resumen global</div></div><?php if($logoTiktok!==''):?><img src="<?php echo htmlspecialchars($logoTiktok,ENT_QUOTES,'UTF-8');?>" style="height:44px" alt="TikTok"><?php endif;?></div>
<div class="grid">
<section class="card"><div class="sub">Mensajería tipo WhatsApp</div><div class="kpi"><?php echo number_format($stats['wa']);?></div></section>
<section class="card"><div class="sub">Clientes activos</div><div class="kpi"><?php echo number_format($stats['ca']);?></div></section>
<section class="card"><div class="sub">Clientes no activos</div><div class="kpi"><?php echo number_format($stats['ci']);?></div></section>
<section class="card"><div class="sub">Pedidos</div><div class="kpi"><?php echo number_format($stats['ped']);?></div></section>
<section class="card"><div class="sub">Pedidos por entregar</div><div class="kpi"><?php echo number_format($stats['pend']);?></div></section>
<section class="card"><div class="sub">Pedidos entregados</div><div class="kpi"><?php echo number_format($stats['ent']);?></div></section>
<section class="card"><div class="sub">Promociones WhatsApp</div><div class="kpi"><?php echo number_format($stats['promo']);?></div></section>
</div>

<div class="grid" style="margin-top:12px;grid-template-columns:1.1fr 1fr">
<section class="card"><h3>Vistas de pedidos</h3><?php if($resumenPedidos): foreach($resumenPedidos as $rp): ?><div class="tools"><span class="sub" style="min-width:110px"><?php echo htmlspecialchars($rp['estado']?:'SIN ESTADO',ENT_QUOTES,'UTF-8');?></span><strong><?php echo (int)$rp['c'];?></strong></div><?php endforeach; else: ?><div class="sub">Sin datos.</div><?php endif; ?><div class="tools" style="margin-top:10px"><a href="pedidos.php" style="color:#c89b3c">Ir a pedidos</a><a href="produccion.php" style="color:#c89b3c">Ir a producción</a></div></section>

<section class="card"><h3>Mensajería interna</h3>
  <div class="chat">
    <div>
      <select id="tipoMsg" style="width:100%;padding:8px;border-radius:8px;background:#111;color:#fff"><option value="grupal">Grupal</option><option value="privado">Privado</option></select>
      <div class="users" id="usuariosLista" style="margin-top:8px"></div>
    </div>
    <div>
      <div id="mensajesBox" class="msgbox"></div>
      <div class="tools"><input id="mensajeTxt" type="text" placeholder="Escribe mensaje..." style="flex:1;padding:8px;border-radius:8px;border:1px solid #333;background:#111;color:#fff"><button id="enviarBtn" style="padding:8px 12px;background:#c89b3c;border:none;border-radius:8px">Enviar</button></div>
    </div>
  </div>
</section>
</div>
</main></div>
<script src="assets/js/app_realtime.js"></script>
<script>
let destinatarioId = 0;
async function cargarMensajes(){
  const tipo = document.getElementById('tipoMsg').value;
  const q = new URLSearchParams({ tipo, destinatario_id: destinatarioId });
  const r = await fetch('mensajes_cargar.php?'+q.toString());
  const data = await r.json();
  if(!data.ok) return;
  const ul = document.getElementById('usuariosLista');
  ul.innerHTML = '';
  (data.usuarios||[]).forEach(u=>{ const b=document.createElement('button'); b.textContent=u.nombre; b.style.cssText='display:block;width:100%;margin:4px 0;padding:7px;border-radius:7px;background:#1b1b1b;color:#fff;border:1px solid #333'; b.onclick=()=>{destinatarioId=u.id;document.getElementById('tipoMsg').value='privado';cargarMensajes();}; ul.appendChild(b); });
  const box = document.getElementById('mensajesBox');
  box.innerHTML='';
  (data.mensajes||[]).forEach(m=>{ const d=document.createElement('div'); d.className='msg'+(m.remitente_id==<?php echo (int)$_SESSION['usuario_id'];?>?' me':''); d.textContent = m.remitente_nombre+': '+m.mensaje; box.appendChild(d); });
  box.scrollTop = box.scrollHeight;
}
async function enviarMensaje(){
  const tipo = document.getElementById('tipoMsg').value;
  const mensaje = document.getElementById('mensajeTxt').value.trim(); if(!mensaje) return;
  const fd = new FormData(); fd.append('tipo',tipo); fd.append('destinatario_id',destinatarioId); fd.append('mensaje',mensaje);
  const r = await fetch('mensajes_enviar.php',{method:'POST',body:fd}); const data = await r.json(); if(data.ok){ document.getElementById('mensajeTxt').value=''; cargarMensajes(); }
}
document.getElementById('enviarBtn').addEventListener('click', enviarMensaje);
document.getElementById('tipoMsg').addEventListener('change', ()=>{ if(document.getElementById('tipoMsg').value==='grupal') destinatarioId=0; cargarMensajes(); });
setInterval(()=>{ fetch('mensajes_estado.php').catch(()=>{}); cargarMensajes().catch(()=>{}); }, 5000);
cargarMensajes().catch(()=>{});
</script>
</body></html>
