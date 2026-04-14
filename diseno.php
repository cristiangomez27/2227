<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';
require_once 'permisos.php';
require_once __DIR__ . '/includes/realtime_config.php';
require_once __DIR__ . '/helpers/websocket_notify.php';

if (!puedeVerModuloDiseno()) {
    http_response_code(403);
    echo 'No tienes permisos para acceder al módulo de diseño.';
    exit;
}

$appRealtime = app_realtime_config(isset($conn) ? $conn : null);
$appRealtime['module'] = 'diseno';

$rolSesion = function_exists('normalizarRolUsuario') ? normalizarRolUsuario($_SESSION['rol'] ?? '') : strtolower((string)($_SESSION['rol'] ?? ''));
$esProduccion = $rolSesion === 'produccion';
$esAdmin = $rolSesion === 'administrador_general';
$mostrarAlertasAdmin = false;

@mkdir(__DIR__ . '/uploads/disenos/originales', 0775, true);
@mkdir(__DIR__ . '/uploads/disenos/preview', 0775, true);
@mkdir(__DIR__ . '/uploads/disenos/descargas', 0775, true);

$conn->query("CREATE TABLE IF NOT EXISTS disenos_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_original VARCHAR(255) NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    extension VARCHAR(15) DEFAULT NULL,
    tamano_bytes BIGINT DEFAULT 0,
    usuario_id INT DEFAULT NULL,
    pedido_id INT DEFAULT NULL,
    remision_id INT DEFAULT NULL,
    es_nuevo TINYINT(1) DEFAULT 1,
    visto_en DATETIME DEFAULT NULL,
    descargado_en DATETIME DEFAULT NULL,
    eliminado_en DATETIME DEFAULT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cols = [];
$rCols = $conn->query("SHOW COLUMNS FROM disenos_archivos");
if ($rCols) while ($c = $rCols->fetch_assoc()) $cols[] = $c['Field'];
$alterMap = [
    "es_nuevo" => "ALTER TABLE disenos_archivos ADD COLUMN es_nuevo TINYINT(1) DEFAULT 1",
    "visto_en" => "ALTER TABLE disenos_archivos ADD COLUMN visto_en DATETIME DEFAULT NULL",
    "descargado_en" => "ALTER TABLE disenos_archivos ADD COLUMN descargado_en DATETIME DEFAULT NULL",
    "eliminado_en" => "ALTER TABLE disenos_archivos ADD COLUMN eliminado_en DATETIME DEFAULT NULL"
];
foreach ($alterMap as $col => $sqlAlter) {
    if (!in_array($col, $cols, true)) {
        @$conn->query($sqlAlter);
    }
}

if ($conn->query("SHOW TABLES LIKE 'configuracion'") && ($cfgCols = $conn->query("SHOW COLUMNS FROM configuracion"))) {
    $colsCfg = [];
    while($c=$cfgCols->fetch_assoc()) $colsCfg[] = $c['Field'];
    if (in_array('alertas_diseno_admin', $colsCfg, true)) {
        $q = $conn->query("SELECT alertas_diseno_admin FROM configuracion WHERE id=1 LIMIT 1");
        if ($q && $q->num_rows) {
            $row = $q->fetch_assoc();
            $mostrarAlertasAdmin = (int)($row['alertas_diseno_admin'] ?? 0) === 1;
        }
    }
}

$puedeVerAlertasNuevos = $esProduccion || ($esAdmin && $mostrarAlertasAdmin);
$mensaje = '';
$tipoMensaje = '';

$allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
];

if (isset($_GET['download'])) {
    $id = (int)$_GET['download'];
    $stmt = $conn->prepare('SELECT * FROM disenos_archivos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && is_file(__DIR__ . '/' . $row['ruta_archivo'])) {
        $full = __DIR__ . '/' . $row['ruta_archivo'];
        $descargaRel = 'uploads/disenos/descargas/' . basename($row['ruta_archivo']);
        $descargaAbs = __DIR__ . '/' . $descargaRel;
        if (!is_file($descargaAbs)) {
            @copy($full, $descargaAbs);
        }

        if ($puedeVerAlertasNuevos) {
            $stMark = $conn->prepare("UPDATE disenos_archivos SET es_nuevo = 0, descargado_en = NOW() WHERE id = ?");
            if ($stMark) { $stMark->bind_param('i', $id); $stMark->execute(); $stMark->close(); }
        }

        header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: attachment; filename="' . basename($row['nombre_original']) . '"');
        readfile($full);
        exit;
    }

    $mensaje = 'Archivo no encontrado.';
    $tipoMensaje = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    $id = (int)$_POST['eliminar_id'];
    $stmt = $conn->prepare('SELECT ruta_archivo FROM disenos_archivos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $ruta = __DIR__ . '/' . $row['ruta_archivo'];
        if (is_file($ruta)) { @unlink($ruta); }
        $stmtDel = $conn->prepare('DELETE FROM disenos_archivos WHERE id = ? LIMIT 1');
        $stmtDel->bind_param('i', $id);
        $stmtDel->execute();
        $stmtDel->close();
        $mensaje = 'Diseño eliminado correctamente.';
        $tipoMensaje = 'ok';
        websocket_notify('diseno_eliminado', 'diseno', 'Diseño eliminado', ['id' => $id]);
    } else {
        $mensaje = 'No se encontró el diseño a eliminar.';
        $tipoMensaje = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['imagen'])) {
    $file = $_FILES['imagen'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $mensaje = 'Error al subir la imagen.';
        $tipoMensaje = 'error';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            $mensaje = 'Formato no permitido. Usa PNG, JPG/JPEG o WEBP.';
            $tipoMensaje = 'error';
        } else {
            $ext = $allowed[$mime];
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$file['name']);
            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
            $destRel = 'uploads/disenos/originales/' . $filename;
            $destAbs = __DIR__ . '/' . $destRel;

            if (move_uploaded_file($file['tmp_name'], $destAbs)) {
                $stmt = $conn->prepare('INSERT INTO disenos_archivos (nombre_original, ruta_archivo, mime_type, extension, tamano_bytes, usuario_id, pedido_id, remision_id, es_nuevo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
                $tam = (int)($file['size'] ?? 0);
                $uid = (int)($_SESSION['usuario_id'] ?? 0);
                $pedidoId = !empty($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : null;
                $remisionId = !empty($_POST['remision_id']) ? (int)$_POST['remision_id'] : null;
                $stmt->bind_param('ssssiiii', $safeName, $destRel, $mime, $ext, $tam, $uid, $pedidoId, $remisionId);
                $stmt->execute();
                $nuevoId = (int)$conn->insert_id;
                $stmt->close();

                $mensaje = 'Carga completa: imagen guardada correctamente.';
                $tipoMensaje = 'ok';
                websocket_notify('diseno_subido', 'diseno', 'Diseño recibido', ['id' => $nuevoId]);
            } else {
                $mensaje = 'No se pudo guardar la imagen en servidor.';
                $tipoMensaje = 'error';
            }
        }
    }
}

$archivos = $conn->query('SELECT d.*, u.nombre AS usuario_nombre FROM disenos_archivos d LEFT JOIN usuarios u ON u.id = d.usuario_id ORDER BY d.id DESC LIMIT 300');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diseño</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard_effect.css">
    <style>
        body{margin:0;background:#050505;color:#fff;font-family:Segoe UI,sans-serif}
        .wrap{display:grid;grid-template-columns:90px 1fr;min-height:100vh}
        .sidebar{padding:20px 0;display:flex;flex-direction:column;align-items:center;gap:18px;background:rgba(0,0,0,.85);border-right:1px solid rgba(255,255,255,.12)}
        .sidebar a{color:#666;font-size:21px}.sidebar a.active,.sidebar a:hover{color:#c89b3c}
        .main{padding:24px}
        .card{background:rgba(15,15,15,.6);border-radius:14px;padding:18px;margin-bottom:18px}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
        .item{position:relative}
        .item.new-item{animation:pulseGlow 1.2s ease-in-out infinite alternate; border:1px solid rgba(255,209,102,.6)}
        .badge-new{position:absolute;top:8px;right:8px;background:#f59e0b;color:#111;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}
        img{max-width:100%;border-radius:8px;display:block}
        .actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
        .btn{padding:8px 10px;border-radius:8px;border:none;cursor:pointer;font-weight:600}
        .btn-dl{background:#22c55e;color:#111}.btn-del{background:#ef4444;color:#fff}
        .toast{position:fixed;right:14px;bottom:16px;z-index:9999;background:rgba(10,10,10,.95);border:1px solid rgba(255,255,255,.2);padding:12px 14px;border-radius:10px;opacity:0;transform:translateY(20px);transition:.25s}
        .toast.show{opacity:1;transform:translateY(0)}
        @keyframes pulseGlow{from{box-shadow:0 0 0 rgba(245,158,11,.3)}to{box-shadow:0 0 22px rgba(245,158,11,.75)}}
        @media (max-width:900px){.wrap{grid-template-columns:1fr}.sidebar{position:sticky;top:0;z-index:10;flex-direction:row;justify-content:center}.main{padding:12px}}
    </style>
</head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'], ENT_QUOTES, 'UTF-8'); ?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled']) ? '1' : '0'; ?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<div class="wrap">
    <aside class="sidebar">
        <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
        <a href="produccion.php" title="Producción"><i class="fas fa-industry"></i></a>
        <a href="diseno.php" class="active" title="Diseño"><i class="fas fa-palette"></i></a>
    </aside>
    <main class="main">
        <div class="card">
            <h2 style="margin-top:0">Módulo de Diseño</h2>
            <p style="color:#bbb">Sube imágenes sin recompresión, con vista previa, descarga y control de estado nuevo.</p>
            <form method="POST" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;align-items:center">
                <input type="number" name="pedido_id" placeholder="Pedido ID (opcional)">
                <input type="number" name="remision_id" placeholder="Remisión ID (opcional)">
                <input type="file" name="imagen" accept="image/png,image/jpeg,image/webp" required>
                <button class="btn" style="background:#c89b3c;color:#111" type="submit">Subir imagen</button>
            </form>
        </div>
        <div class="grid">
            <?php if ($archivos): while($a=$archivos->fetch_assoc()): 
                $isNew = ((int)($a['es_nuevo'] ?? 0) === 1) && $puedeVerAlertasNuevos;
            ?>
                <article class="card item <?php echo $isNew ? 'new-item' : ''; ?>">
                    <?php if ($isNew): ?><span class="badge-new">NUEVO</span><?php endif; ?>
                    <img src="<?php echo htmlspecialchars($a['ruta_archivo'], ENT_QUOTES, 'UTF-8'); ?>" alt="diseño">
                    <div style="margin-top:8px;font-size:13px;color:#ddd"><?php echo htmlspecialchars($a['nombre_original'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div style="font-size:12px;color:#aaa"><?php echo number_format(((int)$a['tamano_bytes'])/1024,1); ?> KB · <?php echo htmlspecialchars((string)($a['usuario_nombre'] ?: 'Usuario #'.$a['usuario_id']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="actions">
                        <a class="btn btn-dl" href="diseno.php?download=<?php echo (int)$a['id']; ?>">Descargar</a>
                        <form method="POST" onsubmit="return confirm('¿Eliminar este diseño?');">
                            <input type="hidden" name="eliminar_id" value="<?php echo (int)$a['id']; ?>">
                            <button class="btn btn-del" type="submit">Eliminar</button>
                        </form>
                    </div>
                </article>
            <?php endwhile; endif; ?>
        </div>
    </main>
</div>
<div id="toast" class="toast"></div>
<script src="assets/js/app_realtime.js"></script>
<script>
(function(){
    const msg = <?php echo json_encode($mensaje, JSON_UNESCAPED_UNICODE); ?>;
    const tipo = <?php echo json_encode($tipoMensaje, JSON_UNESCAPED_UNICODE); ?>;
    const esProduccion = <?php echo $puedeVerAlertasNuevos ? 'true' : 'false'; ?>;
    const soundsOn = document.body.dataset.soundEnabled === '1';
    const soundFile = document.body.dataset.soundFile || '';
    const t = document.getElementById('toast');
    if (msg) {
        t.textContent = msg;
        t.style.borderColor = tipo === 'error' ? 'rgba(239,68,68,.7)' : 'rgba(34,197,94,.7)';
        t.classList.add('show');
        setTimeout(()=>t.classList.remove('show'), 2800);
        if (esProduccion && soundsOn && soundFile && tipo === 'ok') {
            const a = new Audio(soundFile); a.play().catch(()=>{});
        }
    }
})();
</script>
</body>
</html>
