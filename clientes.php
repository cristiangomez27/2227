<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';
require_once 'config/functions.php';

function existeTablaVisual(mysqli $conn, string $tabla): bool
{
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasVisual(mysqli $conn, string $tabla): array
{
    $columnas = [];
    if (!existeTablaVisual($conn, $tabla)) {
        return $columnas;
    }

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }
    return $columnas;
}

function tieneColumnaVisual(array $columnas, string $columna): bool
{
    return in_array($columna, $columnas, true);
}

function usuario_es_admin_clientes(mysqli $conn): bool
{
    if (isset($_SESSION['is_admin']) && (int) $_SESSION['is_admin'] === 1) {
        return true;
    }

    $rolActual = '';
    $camposPosibles = ['rol', 'usuario_rol', 'tipo_usuario', 'perfil', 'puesto', 'cargo', 'usuario'];
    foreach ($camposPosibles as $campo) {
        if (!empty($_SESSION[$campo])) {
            $valor = trim((string) $_SESSION[$campo]);
            $rolActual = function_exists('mb_strtolower') ? mb_strtolower($valor) : strtolower($valor);
            break;
        }
    }

    $usuarioSesionId = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($usuarioSesionId > 0) {
        $stmtRol = $conn->prepare('SELECT rol FROM usuarios WHERE id = ? LIMIT 1');
        if ($stmtRol) {
            $stmtRol->bind_param('i', $usuarioSesionId);
            $stmtRol->execute();

            if (method_exists($stmtRol, 'get_result')) {
                $resRol = $stmtRol->get_result();
                if ($resRol && ($filaRol = $resRol->fetch_assoc()) && !empty($filaRol['rol'])) {
                    $rolActual = trim((string) $filaRol['rol']);
                    $rolActual = function_exists('mb_strtolower') ? mb_strtolower($rolActual) : strtolower($rolActual);
                }
            } else {
                $stmtRol->bind_result($rolDb);
                if ($stmtRol->fetch() && !empty($rolDb)) {
                    $rolActual = trim((string) $rolDb);
                    $rolActual = function_exists('mb_strtolower') ? mb_strtolower($rolActual) : strtolower($rolActual);
                }
            }

            $stmtRol->close();
        }
    }

    $rolActual = str_replace('_', ' ', $rolActual);
    $adminsValidos = [
        'admin',
        'administrador',
        'administrator',
        'root',
        'superadmin',
        'administrador general',
    ];

    return in_array($rolActual, $adminsValidos, true);
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;
$notificacion = '';

$configCols = obtenerColumnasVisual($conn, 'configuracion');
if (!empty($configCols)) {
    $selectConfig = [];
    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar'] as $col) {
        if (tieneColumnaVisual($configCols, $col)) {
            $selectConfig[] = $col;
        }
    }

    if (!empty($selectConfig)) {
        $sqlConfig = 'SELECT ' . implode(', ', $selectConfig) . ' FROM configuracion WHERE id = 1 LIMIT 1';
        $resConfig = $conn->query($sqlConfig);
        if ($resConfig && $resConfig->num_rows > 0) {
            $config = $resConfig->fetch_assoc();
            if (!empty($config['logo'])) {
                $logoActual = $config['logo'];
            }
            if (!empty($config['fondo_sidebar'])) {
                $fondoSidebar = $config['fondo_sidebar'];
            }
            if (!empty($config['fondo_contenido'])) {
                $fondoContenido = $config['fondo_contenido'];
            }
            if (isset($config['transparencia_panel'])) {
                $transparenciaPanel = (float) $config['transparencia_panel'];
            }
            if (isset($config['transparencia_sidebar'])) {
                $transparenciaSidebar = (float) $config['transparencia_sidebar'];
            }
        }
    }
}

$transparenciaPanel = max(0.10, min(0.95, $transparenciaPanel));
$transparenciaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

$clientesColsSistema = obtenerColumnasVisual($conn, 'clientes');
if (!empty($clientesColsSistema) && !tieneColumnaVisual($clientesColsSistema, 'tipo_cliente')) {
    $conn->query("ALTER TABLE clientes ADD COLUMN tipo_cliente VARCHAR(30) NOT NULL DEFAULT 'Personalizado' AFTER email");
    $clientesColsSistema = obtenerColumnasVisual($conn, 'clientes');
}

$esAdmin = usuario_es_admin_clientes($conn);
asegurarTablaPapelera($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_guardar'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tipoCliente = trim($_POST['tipo_cliente'] ?? 'Personalizado');

    if (!in_array($tipoCliente, ['Personalizado', 'DTF'], true)) {
        $tipoCliente = 'Personalizado';
    }

    if ($nombre === '' || $telefono === '') {
        $notificacion = 'Debes capturar nombre y teléfono.';
    } else {
        if (tieneColumnaVisual($clientesColsSistema, 'tipo_cliente')) {
            $stmt = $conn->prepare('INSERT INTO clientes (nombre, telefono, direccion, email, tipo_cliente) VALUES (?, ?, ?, ?, ?)');
            if ($stmt) {
                $stmt->bind_param('sssss', $nombre, $telefono, $direccion, $email, $tipoCliente);
                if ($stmt->execute()) {
                    $clienteIdNuevo = (int) $stmt->insert_id;
                    $stmt->close();
                    header('Location: ventas.php?cliente_id=' . urlencode((string) $clienteIdNuevo)
                        . '&cliente=' . urlencode($nombre)
                        . '&tel=' . urlencode($telefono)
                        . '&direccion=' . urlencode($direccion)
                        . '&email=' . urlencode($email)
                        . '&tipo_cliente=' . urlencode($tipoCliente)
                        . '&cliente_nuevo=1');
                    exit;
                }
                $notificacion = 'Error al registrar cliente: ' . $conn->error;
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare('INSERT INTO clientes (nombre, telefono, direccion, email) VALUES (?, ?, ?, ?)');
            if ($stmt) {
                $stmt->bind_param('ssss', $nombre, $telefono, $direccion, $email);
                if ($stmt->execute()) {
                    $clienteIdNuevo = (int) $stmt->insert_id;
                    $stmt->close();
                    header('Location: ventas.php?cliente_id=' . urlencode((string) $clienteIdNuevo)
                        . '&cliente=' . urlencode($nombre)
                        . '&tel=' . urlencode($telefono)
                        . '&direccion=' . urlencode($direccion)
                        . '&email=' . urlencode($email)
                        . '&tipo_cliente=' . urlencode($tipoCliente)
                        . '&cliente_nuevo=1');
                    exit;
                }
                $notificacion = 'Error al registrar cliente: ' . $conn->error;
                $stmt->close();
            }
        }
    }
}

if (isset($_GET['eliminar'])) {
    if (!$esAdmin) {
        $notificacion = 'No tienes permisos para eliminar clientes.';
    } else {
        $idEliminar = (int) $_GET['eliminar'];
        if ($idEliminar > 0) {
            $clienteEliminar = null;
            $stmtLeer = $conn->prepare('SELECT id, nombre, telefono, direccion, email, tipo_cliente FROM clientes WHERE id = ? LIMIT 1');
            if ($stmtLeer) {
                $stmtLeer->bind_param('i', $idEliminar);
                $stmtLeer->execute();
                if (method_exists($stmtLeer, 'get_result')) {
                    $resCliente = $stmtLeer->get_result();
                    if ($resCliente) {
                        $clienteEliminar = $resCliente->fetch_assoc();
                    }
                } else {
                    $stmtLeer->bind_result($cliId, $cliNombre, $cliTelefono, $cliDireccion, $cliEmail, $cliTipo);
                    if ($stmtLeer->fetch()) {
                        $clienteEliminar = [
                            'id' => $cliId,
                            'nombre' => $cliNombre,
                            'telefono' => $cliTelefono,
                            'direccion' => $cliDireccion,
                            'email' => $cliEmail,
                            'tipo_cliente' => $cliTipo,
                        ];
                    }
                }
                $stmtLeer->close();
            }

            if ($clienteEliminar) {
                if (enviarRegistroAPapelera($conn, 'clientes', $idEliminar, $clienteEliminar, $_SESSION['usuario_id'] ?? null)) {
                    $stmtDelete = $conn->prepare('DELETE FROM clientes WHERE id = ?');
                    if ($stmtDelete) {
                        $stmtDelete->bind_param('i', $idEliminar);
                        if ($stmtDelete->execute() && $stmtDelete->affected_rows > 0) {
                            $notificacion = 'Cliente enviado a papelera correctamente.';
                        } else {
                            $notificacion = 'No se encontró el cliente para eliminar.';
                        }
                        $stmtDelete->close();
                    }
                } else {
                    $notificacion = 'No se pudo enviar el cliente a papelera.';
                }
            } else {
                $notificacion = 'No se encontró el cliente para eliminar.';
            }
        }
    }
}

$clientes = $conn->query('SELECT * FROM clientes ORDER BY id DESC');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes PRO - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --gold:#c89b3c; --bg:#0b0b0f; --glass: rgba(200,155,60,.16); --shadow:0 10px 30px rgba(0,0,0,.28); }
        * { box-sizing:border-box; }
        html, body { margin:0; padding:0; min-height:100%; }
        body {
            color:#fff; font-family:'Segoe UI',sans-serif; min-height:100vh; display:flex; overflow-x:hidden; position:relative;
            background: <?php echo !empty($fondoContenido)
                ? "linear-gradient(rgba(8,8,12,0.35), rgba(8,8,12,0.55)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                : "linear-gradient(135deg, #0b0b0f, #14151a)"; ?>;
        }
        body::after{
            content:""; position:fixed; inset:0; pointer-events:none; z-index:0;
            background:
                radial-gradient(circle at 20% 30%,rgba(200,155,60,.08) 0%,transparent 40%),
                radial-gradient(circle at 80% 70%,rgba(200,155,60,.06) 0%,transparent 40%);
            animation: particlesMove 12s linear infinite alternate;
        }
        @keyframes particlesMove{ 0%{transform:translateY(0);} 100%{transform:translateY(-40px);} }
        @keyframes logoPulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.05);} }
        @keyframes glow { from{filter:drop-shadow(0 0 8px rgba(200,155,60,.4));} to{filter:drop-shadow(0 0 18px rgba(200,155,60,.8));} }
        @keyframes slideUp { from{opacity:0;transform:translateY(20px);} to{opacity:1;transform:translateY(0);} }
        @keyframes cardshine { 0%{left:-60%;} 15%,100%{left:120%;} }

        .sidebar {
            width: 250px; height:100vh; position:fixed; top:0; left:0; z-index:1000; padding:20px;
            border-right:1px solid rgba(200,155,60,.2); box-shadow:var(--shadow);
            display:flex; flex-direction:column; align-items:stretch; gap:10px; overflow-y:auto;
            background: <?php echo !empty($fondoSidebar)
                ? "linear-gradient(rgba(10,10,10," . $transparenciaSidebar . "), rgba(10,10,10," . $transparenciaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                : "rgba(20,21,26," . $transparenciaSidebar . ")"; ?>;
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        }
        .logo-pos {
            width: 120px; margin: 4px auto 12px; display:block; object-fit:contain;
            animation: logoPulse 4s ease-in-out infinite, glow 3s infinite alternate;
        }
        .sidebar a {
            color:#d0d0d0; font-size:15px; text-decoration:none; transition:.25s; border-radius:12px;
            display:flex; align-items:center; justify-content:center; min-height:44px;
            background:rgba(255,255,255,.03);
        }
        .sidebar a:hover, .sidebar a.active {
            background: rgba(200, 155, 60, 0.14); color:var(--gold); transform:translateX(3px);
        }

        .main-content {
            margin-left:250px; width:calc(100% - 250px); padding:34px; position:relative; z-index:2;
            animation: slideUp .45s ease-out;
        }
        .glass {
            background: rgba(10, 10, 14, <?php echo $transparenciaPanel; ?>); border:1px solid rgba(255,255,255,.06);
            border-radius:22px; padding:24px; margin-bottom:22px; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            box-shadow:var(--shadow); position:relative; overflow:hidden;
        }
        .glass::before{
            content:""; position:absolute; top:-50%; left:-60%; width:20%; height:200%;
            background:rgba(255,255,255,.08); transform:rotate(30deg); animation: cardshine 6s infinite;
            pointer-events:none;
        }
        h1 { font-weight:200; margin:0 0 24px; font-size:34px; line-height:1.2; }
        h1 span, .title { color:var(--gold); font-weight:700; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px; align-items:end; }
        label { display:block; margin-bottom:6px; color:var(--gold); text-transform:uppercase; font-size:11px; letter-spacing:1px; }
        input, select {
            width:100%; padding:12px; border-radius:10px; border:1px solid rgba(255,255,255,.12);
            background:rgba(255,255,255,.05); color:#fff; outline:none; transition:.25s;
        }
        input:focus, select:focus { border-color:var(--gold); box-shadow:0 0 0 2px rgba(200,155,60,.2); }
        .btn {
            background: linear-gradient(45deg, #c89b3c, #eec064); color:#111; border:none; border-radius:10px;
            padding:12px 16px; font-weight:800; cursor:pointer; text-transform:uppercase; transition:.2s;
        }
        .btn:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(200,155,60,.25); }
        .btn-danger { background:linear-gradient(45deg,#a61d24,#dc3545); color:#fff; padding:8px 12px; border-radius:10px; text-decoration:none; font-size:12px; }
        .btn-danger:hover { box-shadow:0 8px 20px rgba(220,53,69,.28); }
        table { width:100%; border-collapse:collapse; min-width:980px; }
        .table-wrap { overflow-x:auto; margin-top:14px; }
        th, td { padding:14px; border-bottom:1px solid rgba(255,255,255,.06); text-align:left; }
        th { color:var(--gold); font-size:11px; text-transform:uppercase; letter-spacing:1px; }
        tr:hover td { background: rgba(200,155,60,.04); }
        .pill { padding:6px 10px; border-radius:99px; font-size:11px; font-weight:700; }
        .pill-pers { background:rgba(200,155,60,.18); color:#f7d58b; }
        .pill-dtf { background:rgba(59,130,246,.2); color:#bfdbfe; }
        .actions { display:flex; gap:8px; justify-content:flex-end; }
        .toast {
            position:fixed; top:18px; right:18px; background:#28a745; border-radius:12px; padding:12px 16px;
            transform:translateX(120%); transition:.35s ease; z-index:1200; box-shadow: var(--shadow);
        }
        .toast.show { transform:translateX(0); }
        @media (max-width: 900px) {
            .sidebar { position:static; width:100%; height:auto; flex-direction:row; justify-content:center; padding:10px; }
            .logo-pos { width:70px; margin:0 10px 0 0; }
            .main-content { margin-left:0; width:100%; padding:14px; }
            h1 { font-size:28px; }
        }
    </style>
</head>
<body>
    <div id="toast" class="toast"><?php echo htmlspecialchars($notificacion, ENT_QUOTES, 'UTF-8'); ?></div>

    <aside class="sidebar">
        <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-pos">
        <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
        <a href="ventas.php" title="Caja"><i class="fas fa-cash-register"></i></a>
        <a href="clientes.php" class="active" title="Clientes"><i class="fas fa-users"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
        <a href="logout.php" title="Salir"><i class="fas fa-power-off"></i></a>
    </aside>

    <main class="main-content">
        <h1>Gestión de <span>CLIENTES PRO</span></h1>

        <section class="glass">
            <h3 class="title"><i class="fas fa-user-plus"></i> Registro rápido</h3>
            <form method="POST" class="grid">
                <div>
                    <label>Nombre</label>
                    <input type="text" name="nombre" required>
                </div>
                <div>
                    <label>WhatsApp</label>
                    <input type="text" name="telefono" required>
                </div>
                <div>
                    <label>Tipo cliente</label>
                    <select name="tipo_cliente" required>
                        <option value="Personalizado">Personalizado</option>
                        <option value="DTF">DTF</option>
                    </select>
                </div>
                <div>
                    <label>Dirección</label>
                    <input type="text" name="direccion">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div>
                    <button class="btn" type="submit" name="btn_guardar"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </section>

        <section class="glass">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <h3 class="title" style="margin:0;"><i class="fas fa-address-book"></i> Cartera de clientes</h3>
                <input type="text" id="buscador" placeholder="Buscar por nombre o teléfono..." onkeyup="buscarCliente()" style="max-width:340px;">
            </div>

            <div class="table-wrap">
                <table id="tablaClientes">
                    <thead>
                    <tr>
                        <th>Nombre / detalles</th>
                        <th>WhatsApp</th>
                        <th>Tipo</th>
                        <th>Dirección</th>
                        <th style="text-align:right;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($clientes && $clientes->num_rows > 0): ?>
                        <?php while ($c = $clientes->fetch_assoc()): ?>
                            <?php
                            $tipoClienteFila = trim((string) ($c['tipo_cliente'] ?? 'Personalizado'));
                            if (!in_array($tipoClienteFila, ['Personalizado', 'DTF'], true)) {
                                $tipoClienteFila = 'Personalizado';
                            }
                            $pillClass = $tipoClienteFila === 'DTF' ? 'pill-dtf' : 'pill-pers';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                    <small style="color:#aaa;"><?php echo htmlspecialchars($c['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                                </td>
                                <td>
                                    <a href="https://wa.me/52<?php echo htmlspecialchars($c['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color:#25d366;text-decoration:none;">
                                        <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($c['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><span class="pill <?php echo $pillClass; ?>"><?php echo htmlspecialchars($tipoClienteFila, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($c['direccion'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align:right;">
                                    <div class="actions">
                                        <button class="btn" type="button" onclick="iniciarVenta('<?php echo htmlspecialchars($c['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>','<?php echo htmlspecialchars($c['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>','<?php echo (int) ($c['id'] ?? 0); ?>','<?php echo htmlspecialchars($c['direccion'] ?? '', ENT_QUOTES, 'UTF-8'); ?>','<?php echo htmlspecialchars($c['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>','<?php echo htmlspecialchars($tipoClienteFila, ENT_QUOTES, 'UTF-8'); ?>')">Nueva venta</button>
                                        <?php if ($esAdmin): ?>
                                            <a href="clientes.php?eliminar=<?php echo (int) ($c['id'] ?? 0); ?>" class="btn-danger" onclick="return confirm('¿Seguro que quieres eliminar este cliente?');">Eliminar</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center;color:#aaa;">No hay clientes registrados todavía.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        function buscarCliente() {
            const input = document.getElementById('buscador').value.toLowerCase();
            const rows = document.querySelectorAll('#tablaClientes tbody tr');
            rows.forEach((row) => {
                row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
            });
        }

        function iniciarVenta(nombre, tel, clienteId, direccion, email, tipoCliente) {
            window.location.href =
                `ventas.php?cliente_id=${encodeURIComponent(clienteId)}` +
                `&cliente=${encodeURIComponent(nombre)}` +
                `&tel=${encodeURIComponent(tel)}` +
                `&direccion=${encodeURIComponent(direccion || '')}` +
                `&email=${encodeURIComponent(email || '')}` +
                `&tipo_cliente=${encodeURIComponent(tipoCliente || 'Personalizado')}`;
        }

        (function () {
            const msg = <?php echo json_encode($notificacion, JSON_UNESCAPED_UNICODE); ?>;
            if (!msg) return;
            const toast = document.getElementById('toast');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2600);
        })();

        (function conectarWebSocketClientes() {
            let reconnectTimer = null;

            function iniciar() {
                try {
                    const socket = new WebSocket(
                        (location.protocol === 'https:' ? 'wss://' : 'ws://') +
                        location.hostname +
                        ':8080'
                    );

                    socket.onopen = function () {
                        console.log('[clientes] WebSocket conectado');
                    };

                    socket.onmessage = function (event) {
                        try {
                            const data = JSON.parse(event.data);
                            console.log('[clientes] mensaje ws:', data);

                            if (
                                data.tipo === 'pedido_nuevo' ||
                                data.tipo === 'estatus_actualizado' ||
                                data.tipo === 'refresh' ||
                                data.tipo === 'cliente_nuevo' ||
                                data.tipo === 'cliente_actualizado' ||
                                data.tipo === 'cliente_eliminado'
                            ) {
                                window.location.reload();
                            }
                        } catch (e) {
                            console.warn('[clientes] mensaje ws no parseable:', event.data);
                        }
                    };

                    socket.onerror = function (err) {
                        console.error('[clientes] error ws:', err);
                    };

                    socket.onclose = function () {
                        console.warn('[clientes] ws cerrado, reintentando...');
                        clearTimeout(reconnectTimer);
                        reconnectTimer = setTimeout(iniciar, 2500);
                    };
                } catch (e) {
                    console.error('[clientes] no se pudo iniciar ws:', e);
                    clearTimeout(reconnectTimer);
                    reconnectTimer = setTimeout(iniciar, 3000);
                }
            }

            iniciar();
        })();
    </script>
</body>
</html>