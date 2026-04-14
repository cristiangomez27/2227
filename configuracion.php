<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';
require_once __DIR__ . '/greenapi_helper.php';

function exTab(mysqli $c,string $t):bool{ $t=$c->real_escape_string($t); $r=$c->query("SHOW TABLES LIKE '{$t}'"); return $r&&$r->num_rows>0; }
function colsTab(mysqli $c,string $t):array{ $o=[]; if(!exTab($c,$t)) return $o; $r=$c->query("SHOW COLUMNS FROM `$t`"); if($r) while($x=$r->fetch_assoc()) $o[]=$x['Field']; return $o; }
function subirArchivoConfig(string $input, string $dirRel, array $extPermitidas): ?string {
    if (empty($_FILES[$input]['name']) || ($_FILES[$input]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$input]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extPermitidas, true)) return null;
    $dirAbs = __DIR__ . '/' . $dirRel;
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);
    $filename = $input . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destRel = rtrim($dirRel,'/') . '/' . $filename;
    $destAbs = __DIR__ . '/' . $destRel;
    if (move_uploaded_file($_FILES[$input]['tmp_name'], $destAbs)) return $destRel;
    return null;
}

$appRealtime = app_realtime_config($conn);
$appRealtime['module'] = 'configuracion';
$mensaje=''; $error='';

if (!exTab($conn,'configuracion')) {
    $conn->query("CREATE TABLE IF NOT EXISTS configuracion (
        id INT PRIMARY KEY,
        nombre_negocio VARCHAR(120) DEFAULT NULL,
        telefono_negocio VARCHAR(30) DEFAULT NULL,
        direccion_negocio VARCHAR(255) DEFAULT NULL,
        logo VARCHAR(255) DEFAULT NULL,
        logo_tiktok_shop VARCHAR(255) DEFAULT NULL,
        imagen_publicitaria VARCHAR(255) DEFAULT NULL,
        fondo_login VARCHAR(255) DEFAULT NULL,
        fondo_sidebar VARCHAR(255) DEFAULT NULL,
        fondo_contenido VARCHAR(255) DEFAULT NULL,
        sonido_nuevo_pedido VARCHAR(255) DEFAULT NULL,
        sonido_pedido_vencer VARCHAR(255) DEFAULT NULL,
        sonidos_activos TINYINT(1) DEFAULT 1,
        websocket_url VARCHAR(255) DEFAULT NULL,
        websocket_enabled TINYINT(1) DEFAULT 1,
        transparencia_panel DECIMAL(4,2) DEFAULT 0.32,
        transparencia_sidebar DECIMAL(4,2) DEFAULT 0.88,
        remision_whatsapp_texto TEXT NULL,
        modulos_activos TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT IGNORE INTO configuracion (id) VALUES (1)");
}

$cols = colsTab($conn,'configuracion');

if (isset($_GET['accion']) && $_GET['accion'] === 'optimizar') {
    $q = $conn->query('SHOW TABLES');
    if ($q) {
        while ($row = $q->fetch_row()) {
            $tabla = $row[0];
            @$conn->query("OPTIMIZE TABLE `$tabla`");
        }
        $mensaje = 'Optimización ejecutada.';
    }
}

if (isset($_GET['accion']) && $_GET['accion'] === 'reiniciar_cache') {
    foreach (['cache','temp'] as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (is_dir($path)) {
            $files = glob($path . '/*');
            if (is_array($files)) {
                foreach ($files as $f) { if (is_file($f)) @unlink($f); }
            }
        }
    }
    $mensaje = 'Cache/temp limpiados.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates=[];
    foreach (['nombre_negocio','telefono_negocio','direccion_negocio','websocket_url','remision_whatsapp_texto'] as $txt) {
        if (in_array($txt,$cols,true)) $updates[$txt] = trim($_POST[$txt] ?? '');
    }
    if (in_array('sonidos_activos',$cols,true)) $updates['sonidos_activos'] = isset($_POST['sonidos_activos']) ? 1 : 0;
    if (in_array('websocket_enabled',$cols,true)) $updates['websocket_enabled'] = isset($_POST['websocket_enabled']) ? 1 : 0;
    if (in_array('transparencia_panel',$cols,true)) $updates['transparencia_panel'] = max(0.10,min(0.95,(float)($_POST['transparencia_panel'] ?? 0.32)));
    if (in_array('transparencia_sidebar',$cols,true)) $updates['transparencia_sidebar'] = max(0.10,min(0.98,(float)($_POST['transparencia_sidebar'] ?? 0.88)));

    if (in_array('modulos_activos',$cols,true)) {
        $mods = $_POST['modulos'] ?? [];
        $updates['modulos_activos'] = json_encode(array_values(array_unique(array_map('strval', (array)$mods))), JSON_UNESCAPED_UNICODE);
    }

    $mapUploads = [
        'logo' => ['uploads/logos', ['png','jpg','jpeg','webp','svg']],
        'logo_tiktok_shop' => ['uploads/logos', ['png','jpg','jpeg','webp','svg']],
        'imagen_publicitaria' => ['uploads/fondos', ['png','jpg','jpeg','webp']],
        'fondo_login' => ['uploads/fondos', ['png','jpg','jpeg','webp']],
        'fondo_sidebar' => ['uploads/fondos', ['png','jpg','jpeg','webp']],
        'fondo_contenido' => ['uploads/fondos', ['png','jpg','jpeg','webp']],
        'sonido_nuevo_pedido' => ['uploads/sonidos', ['mp3','wav','ogg']],
        'sonido_pedido_vencer' => ['uploads/sonidos', ['mp3','wav','ogg']],
    ];
    foreach ($mapUploads as $field=>$cfg) {
        if (!in_array($field,$cols,true)) continue;
        $nuevo = subirArchivoConfig($field, $cfg[0], $cfg[1]);
        if ($nuevo !== null) $updates[$field] = $nuevo;
    }

    if (!empty($updates)) {
        $sets=[]; $types=''; $vals=[];
        foreach($updates as $k=>$v){ $sets[]="$k = ?"; $types .= is_int($v)||is_float($v)?'d':'s'; $vals[]=$v; }
        $sql = 'UPDATE configuracion SET ' . implode(', ', $sets) . ' WHERE id = 1';
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$vals);
        if ($st->execute()) $mensaje='Configuración actualizada correctamente.'; else $error='No se pudo guardar la configuración.';
        $st->close();
    }
}

$res = $conn->query('SELECT * FROM configuracion WHERE id=1 LIMIT 1');
$cfg = $res && $res->num_rows ? $res->fetch_assoc() : ['id'=>1];
$modsActivos = [];
if (!empty($cfg['modulos_activos'])) {
    $tmp = json_decode((string)$cfg['modulos_activos'], true);
    if (is_array($tmp)) $modsActivos = $tmp;
}
$cred = cargarCredencialesGreenApi();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuración</title>
<link rel="stylesheet" href="assets/css/dashboard_effect.css"><style>
body{margin:0;background:#050505;color:#fff;font-family:Segoe UI,sans-serif}main{max-width:1200px;margin:16px auto;padding:14px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}.card{background:rgba(15,15,15,.6);padding:16px;border-radius:12px}
input[type=text],input[type=number],input[type=file],textarea{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:#111;color:#fff}
label{display:block;font-size:13px;color:#ddd;margin:6px 0}button{padding:10px 15px;border:none;border-radius:10px;background:#c89b3c;color:#111;font-weight:700}
.preview{max-height:120px;border-radius:10px;margin-top:6px;max-width:100%}.alert{padding:10px;border-radius:9px;margin-bottom:10px}.ok{background:#1f5f34}.err{background:#702626}
.actions a{display:inline-block;margin-right:8px;margin-top:8px;color:#c89b3c}
</style></head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>">
<main>
<h1>Configuración completa</h1>
<?php if($mensaje):?><div class="alert ok"><?php echo htmlspecialchars($mensaje,ENT_QUOTES,'UTF-8');?></div><?php endif; ?>
<?php if($error):?><div class="alert err"><?php echo htmlspecialchars($error,ENT_QUOTES,'UTF-8');?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
<div class="grid">
<section class="card"><h3>Datos del negocio</h3>
<label>Nombre negocio</label><input type="text" name="nombre_negocio" value="<?php echo htmlspecialchars((string)($cfg['nombre_negocio']??''),ENT_QUOTES,'UTF-8');?>">
<label>Teléfono negocio</label><input type="text" name="telefono_negocio" value="<?php echo htmlspecialchars((string)($cfg['telefono_negocio']??''),ENT_QUOTES,'UTF-8');?>">
<label>Dirección negocio</label><input type="text" name="direccion_negocio" value="<?php echo htmlspecialchars((string)($cfg['direccion_negocio']??''),ENT_QUOTES,'UTF-8');?>">
</section>

<section class="card"><h3>Identidad visual</h3>
<label>Logo principal</label><input type="file" name="logo" accept="image/*"><?php if(!empty($cfg['logo'])):?><img class="preview" src="<?php echo htmlspecialchars($cfg['logo'],ENT_QUOTES,'UTF-8');?>"><?php endif; ?>
<label>Logo TikTok shop</label><input type="file" name="logo_tiktok_shop" accept="image/*"><?php if(!empty($cfg['logo_tiktok_shop'])):?><img class="preview" src="<?php echo htmlspecialchars($cfg['logo_tiktok_shop'],ENT_QUOTES,'UTF-8');?>"><?php endif; ?>
<label>Imagen publicitaria</label><input type="file" name="imagen_publicitaria" accept="image/*"><?php if(!empty($cfg['imagen_publicitaria'])):?><img class="preview" src="<?php echo htmlspecialchars($cfg['imagen_publicitaria'],ENT_QUOTES,'UTF-8');?>"><?php endif; ?>
</section>

<section class="card"><h3>Fondos del sistema</h3>
<label>Fondo login</label><input type="file" name="fondo_login" accept="image/*"><?php if(!empty($cfg['fondo_login'])):?><img class="preview" src="<?php echo htmlspecialchars($cfg['fondo_login'],ENT_QUOTES,'UTF-8');?>"><?php endif; ?>
<label>Fondo sidebar</label><input type="file" name="fondo_sidebar" accept="image/*"><?php if(!empty($cfg['fondo_sidebar'])):?><img class="preview" src="<?php echo htmlspecialchars($cfg['fondo_sidebar'],ENT_QUOTES,'UTF-8');?>"><?php endif; ?>
<label>Fondo contenido</label><input type="file" name="fondo_contenido" accept="image/*"><?php if(!empty($cfg['fondo_contenido'])):?><img class="preview" src="<?php echo htmlspecialchars($cfg['fondo_contenido'],ENT_QUOTES,'UTF-8');?>"><?php endif; ?>
</section>

<section class="card"><h3>Transparencias</h3>
<label>Panel</label><input type="number" step="0.01" min="0.10" max="0.95" name="transparencia_panel" value="<?php echo htmlspecialchars((string)($cfg['transparencia_panel']??'0.32'),ENT_QUOTES,'UTF-8');?>">
<label>Sidebar</label><input type="number" step="0.01" min="0.10" max="0.98" name="transparencia_sidebar" value="<?php echo htmlspecialchars((string)($cfg['transparencia_sidebar']??'0.88'),ENT_QUOTES,'UTF-8');?>">
</section>

<section class="card"><h3>Green API + remisión WhatsApp</h3>
<div style="font-size:13px;color:#bbb">Estado credenciales: <?php echo !empty($cred['ok']) ? 'OK' : 'No disponible'; ?></div>
<div style="font-size:13px;color:#bbb">Instance: <?php echo htmlspecialchars(!empty($cred['instance']) ? greenApiMascara((string)$cred['instance']) : '-',ENT_QUOTES,'UTF-8'); ?></div>
<label>Texto remisión WhatsApp</label><textarea name="remision_whatsapp_texto" rows="4"><?php echo htmlspecialchars((string)($cfg['remision_whatsapp_texto']??''),ENT_QUOTES,'UTF-8');?></textarea>
</section>

<section class="card"><h3>Sonidos y WebSocket</h3>
<label><input type="checkbox" name="sonidos_activos" value="1" <?php echo !empty($cfg['sonidos_activos'])?'checked':''; ?>> Sonidos activos</label>
<label>Sonido nuevo pedido</label><input type="file" name="sonido_nuevo_pedido" accept="audio/*"><?php if(!empty($cfg['sonido_nuevo_pedido'])):?><audio controls src="<?php echo htmlspecialchars($cfg['sonido_nuevo_pedido'],ENT_QUOTES,'UTF-8');?>"></audio><?php endif; ?>
<label>Sonido pedido por vencer</label><input type="file" name="sonido_pedido_vencer" accept="audio/*"><?php if(!empty($cfg['sonido_pedido_vencer'])):?><audio controls src="<?php echo htmlspecialchars($cfg['sonido_pedido_vencer'],ENT_QUOTES,'UTF-8');?>"></audio><?php endif; ?>
<label><input type="checkbox" name="websocket_enabled" value="1" <?php echo !empty($cfg['websocket_enabled'])?'checked':''; ?>> WebSocket activo</label>
<label>URL WebSocket</label><input type="text" name="websocket_url" value="<?php echo htmlspecialchars((string)($cfg['websocket_url']??''),ENT_QUOTES,'UTF-8');?>" placeholder="wss://tu-dominio.com:8080">
</section>

<section class="card"><h3>Módulos del sistema</h3>
<?php $mods=['dashboard','clientes','pedidos','diseno','produccion','pedidos_entregados','usuarios','productos','proveedores','reportes','promociones_whatsapp']; foreach($mods as $m): ?>
<label><input type="checkbox" name="modulos[]" value="<?php echo $m; ?>" <?php echo in_array($m,$modsActivos,true)?'checked':''; ?>> <?php echo ucfirst(str_replace('_',' ',$m)); ?></label>
<?php endforeach; ?>
<div class="actions"><a href="usuarios.php">Administrar usuarios</a></div>
</section>

<section class="card"><h3>Respaldo / optimizar / reiniciar</h3>
<div class="actions"><a href="respaldos.php">Ir a respaldos</a><a href="?accion=optimizar">Optimizar base de datos</a><a href="?accion=reiniciar_cache">Reiniciar cache/temp</a></div>
</section>
</div>
<div style="margin-top:14px"><button type="submit">Guardar configuración completa</button> <a href="dashboard.php" style="color:#c89b3c">Volver al dashboard</a></div>
</form>
</main>
<script src="assets/js/app_realtime.js"></script>
</body></html>
