<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';
require_once 'permisos.php';
require_once __DIR__ . '/includes/realtime_config.php';

if (!puedeVerModuloDiseno()) {
    http_response_code(403);
    echo 'No tienes permisos para acceder al módulo de diseño.';
    exit;
}

$appRealtime = app_realtime_config(isset($conn) ? $conn : null);
$appRealtime['module'] = 'diseno';

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
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mensaje = '';
$tipoMensaje = 'ok';
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
        header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: attachment; filename="' . basename($row['nombre_original']) . '"');
        readfile($full);
        exit;
    }

    $mensaje = 'Archivo no encontrado.';
    $tipoMensaje = 'error';
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
                $stmt = $conn->prepare('INSERT INTO disenos_archivos (nombre_original, ruta_archivo, mime_type, extension, tamano_bytes, usuario_id, pedido_id, remision_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $tam = (int)($file['size'] ?? 0);
                $uid = (int)($_SESSION['usuario_id'] ?? 0);
                $pedidoId = !empty($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : null;
                $remisionId = !empty($_POST['remision_id']) ? (int)$_POST['remision_id'] : null;
                $stmt->bind_param('ssssiiii', $safeName, $destRel, $mime, $ext, $tam, $uid, $pedidoId, $remisionId);
                $stmt->execute();
                $stmt->close();

                $mensaje = 'Carga completa: imagen guardada correctamente.';
                $tipoMensaje = 'ok';
            } else {
                $mensaje = 'No se pudo guardar la imagen en servidor.';
                $tipoMensaje = 'error';
            }
        }
    }
}

$archivos = $conn->query('SELECT d.*, u.nombre AS usuario_nombre FROM disenos_archivos d LEFT JOIN usuarios u ON u.id = d.usuario_id ORDER BY d.id DESC LIMIT 200');
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
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
        img{max-width:100%;border-radius:8px;display:block}
        .msg{padding:10px 12px;border-radius:8px;margin-bottom:10px}
        .ok{background:#1f5f34}.error{background:#702626}
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
            <p style="color:#bbb">Sube imágenes sin recompresión, con vista previa y descarga de calidad original.</p>
            <?php if ($mensaje !== ''): ?>
                <div class="msg <?php echo $tipoMensaje === 'ok' ? 'ok' : 'error'; ?>"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="number" name="pedido_id" placeholder="Pedido ID (opcional)">
                <input type="number" name="remision_id" placeholder="Remisión ID (opcional)">
                <input type="file" name="imagen" accept="image/png,image/jpeg,image/webp" required>
                <button type="submit">Subir imagen</button>
            </form>
        </div>
        <div class="grid">
            <?php if ($archivos): while($a=$archivos->fetch_assoc()): ?>
                <article class="card">
                    <img src="<?php echo htmlspecialchars($a['ruta_archivo'], ENT_QUOTES, 'UTF-8'); ?>" alt="diseño">
                    <div style="margin-top:8px;font-size:13px;color:#ddd"><?php echo htmlspecialchars($a['nombre_original'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div style="font-size:12px;color:#aaa"><?php echo number_format(((int)$a['tamano_bytes'])/1024,1); ?> KB · <?php echo htmlspecialchars((string)($a['usuario_nombre'] ?: 'Usuario #'.$a['usuario_id']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <a href="diseno.php?download=<?php echo (int)$a['id']; ?>">Descargar</a>
                </article>
            <?php endwhile; endif; ?>
        </div>
    </main>
</div>
<script src="assets/js/app_realtime.js"></script>
</body>
</html>
