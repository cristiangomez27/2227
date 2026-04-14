<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_OFF);

session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| BOOTSTRAP / COMPATIBILIDAD DE RUTAS
|--------------------------------------------------------------------------
| En algunos despliegues (hosting compartido) no existe /app/config/bootstrap.php
| en la misma estructura del repositorio. Se intenta cargar bootstrap y, si no
| existe, se hace fallback a database + websocket helper.
*/
$bootstrapCargado = false;
$rutasBootstrap = [
    __DIR__ . '/app/config/bootstrap.php',
    __DIR__ . '/config/bootstrap.php',
];

foreach ($rutasBootstrap as $rutaBootstrap) {
    if (is_file($rutaBootstrap)) {
        require_once $rutaBootstrap;
        $bootstrapCargado = true;
        break;
    }
}

if (!$bootstrapCargado) {
    $rutasDb = [
        __DIR__ . '/app/config/database.php',
        __DIR__ . '/config/database.php',
    ];

    foreach ($rutasDb as $rutaDb) {
        if (is_file($rutaDb)) {
            require_once $rutaDb;
            break;
        }
    }

    $rutasWebsocket = [
        __DIR__ . '/app/helpers/websocket_notify.php',
        __DIR__ . '/helpers/websocket_notify.php',
        __DIR__ . '/websocket_notify.php',
    ];

    foreach ($rutasWebsocket as $rutaWs) {
        if (is_file($rutaWs)) {
            require_once $rutaWs;
            break;
        }
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('No se pudo inicializar la conexión a la base de datos.');
}

$mensaje = '';
$tipo_mensaje = 'ok';

/*
|--------------------------------------------------------------------------
| CARGAR CONFIGURACIÓN VISUAL
|--------------------------------------------------------------------------
*/
$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.38;
$transparenciaSidebar = 0.88;
$resConfigVisual = $conn->query("SELECT * FROM configuracion WHERE id = 1 LIMIT 1");
if ($resConfigVisual && $resConfigVisual->num_rows > 0) {
    $configVisual = $resConfigVisual->fetch_assoc();
    if (!empty($configVisual['fondo_sidebar'])) $fondoSidebar = $configVisual['fondo_sidebar'];
    if (!empty($configVisual['fondo_contenido'])) $fondoContenido = $configVisual['fondo_contenido'];
    if (!empty($configVisual['logo'])) $logoActual = $configVisual['logo'];
    if (isset($configVisual['transparencia_panel'])) $transparenciaPanel = (float)$configVisual['transparencia_panel'];
    if (isset($configVisual['transparencia_sidebar'])) $transparenciaSidebar = (float)$configVisual['transparencia_sidebar'];
}

$transparenciaPanel = max(0.10, min(0.95, $transparenciaPanel));
$transparenciaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

/*
|--------------------------------------------------------------------------
| FUNCIONES INTERNAS
|--------------------------------------------------------------------------
*/
function existeTablaUsuarios($conn, $tabla) {
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function existeColumnaUsuarios($conn, $tabla, $columna) {
    $tabla = $conn->real_escape_string($tabla);
    $columna = $conn->real_escape_string($columna);
    $res = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    return ($res && $res->num_rows > 0);
}

function asegurarTablaPapeleraUsuarios($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS papelera (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modulo VARCHAR(100) NOT NULL,
        registro_id INT DEFAULT NULL,
        datos_json LONGTEXT NOT NULL,
        eliminado_por INT DEFAULT NULL,
        fecha_eliminacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!existeColumnaUsuarios($conn, 'papelera', 'registro_id')) {
        $conn->query("ALTER TABLE papelera ADD COLUMN registro_id INT DEFAULT NULL AFTER modulo");
    }

    if (!existeColumnaUsuarios($conn, 'papelera', 'eliminado_por')) {
        $conn->query("ALTER TABLE papelera ADD COLUMN eliminado_por INT DEFAULT NULL AFTER datos_json");
    }

    if (!existeColumnaUsuarios($conn, 'papelera', 'fecha_eliminacion')) {
        $conn->query("ALTER TABLE papelera ADD COLUMN fecha_eliminacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
}

function enviarUsuarioAPapelera($conn, $modulo, $registroId, $datos, $eliminadoPor = null) {
    asegurarTablaPapeleraUsuarios($conn);

    $modulo = $conn->real_escape_string((string)$modulo);
    $registroId = (int)$registroId;
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        return false;
    }

    $json = $conn->real_escape_string($json);
    $eliminadoPorSql = ($eliminadoPor === null || $eliminadoPor === '') ? "NULL" : (int)$eliminadoPor;

    $sql = "INSERT INTO papelera (modulo, registro_id, datos_json, eliminado_por)
            VALUES ('$modulo', $registroId, '$json', $eliminadoPorSql)";

    return $conn->query($sql);
}

function obtenerRolSesionUsuarios() {
    $campos = ['rol', 'usuario_rol', 'tipo_usuario', 'cargo', 'perfil', 'puesto'];
    foreach ($campos as $campo) {
        if (!empty($_SESSION[$campo])) {
            return strtolower(trim((string)$_SESSION[$campo]));
        }
    }
    return '';
}

function passwordFuerteUsuarios(string $password): bool {
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[\W_]/', $password)) return false;
    return true;
}

function responderJsonUsuarios(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| CREAR TABLAS SI NO EXISTEN
|--------------------------------------------------------------------------
*/
$conn->query("CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    correo VARCHAR(120) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(50) NOT NULL DEFAULT 'mostrador',
    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS configuracion_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permitir_registro TINYINT(1) NOT NULL DEFAULT 1,
    solo_admin_registra TINYINT(1) NOT NULL DEFAULT 1,
    roles_permitidos VARCHAR(255) NOT NULL DEFAULT 'admin,mostrador,produccion',
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!existeColumnaUsuarios($conn, 'usuarios', 'correo')) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN correo VARCHAR(120) DEFAULT NULL");
}
if (!existeColumnaUsuarios($conn, 'usuarios', 'rol')) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN rol VARCHAR(50) NOT NULL DEFAULT 'mostrador'");
}
if (!existeColumnaUsuarios($conn, 'usuarios', 'estado')) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'activo'");
}
if (!existeColumnaUsuarios($conn, 'usuarios', 'creado_en')) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}
if (!existeColumnaUsuarios($conn, 'usuarios', 'actualizado_en')) {
    $conn->query("ALTER TABLE usuarios ADD COLUMN actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

$resConfigInicial = $conn->query("SELECT id FROM configuracion_usuarios WHERE id = 1 LIMIT 1");
if ($resConfigInicial && $resConfigInicial->num_rows === 0) {
    $conn->query("INSERT INTO configuracion_usuarios (id, permitir_registro, solo_admin_registra, roles_permitidos)
                  VALUES (1, 1, 1, 'admin,mostrador,produccion')");
}

/*
|--------------------------------------------------------------------------
| LEER CONFIGURACIÓN
|--------------------------------------------------------------------------
*/
$permitir_registro = 1;
$solo_admin_registra = 1;
$roles_permitidos = ['admin', 'mostrador', 'produccion'];

$resConfig = $conn->query("SELECT * FROM configuracion_usuarios WHERE id = 1 LIMIT 1");
if ($resConfig && $filaConfig = $resConfig->fetch_assoc()) {
    $permitir_registro = isset($filaConfig['permitir_registro']) ? (int)$filaConfig['permitir_registro'] : 1;
    $solo_admin_registra = isset($filaConfig['solo_admin_registra']) ? (int)$filaConfig['solo_admin_registra'] : 1;

    if (!empty($filaConfig['roles_permitidos'])) {
        $roles_permitidos = array_values(array_filter(array_map('trim', explode(',', $filaConfig['roles_permitidos']))));
    }
    if (empty($roles_permitidos)) {
        $roles_permitidos = ['admin', 'mostrador', 'produccion'];
    }
}

/*
|--------------------------------------------------------------------------
| DETECTAR ADMIN
|--------------------------------------------------------------------------
*/
$rolActual = obtenerRolSesionUsuarios();

$usuarioSesionId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioSesionId > 0) {
    $resRolUsuario = $conn->query("SELECT rol FROM usuarios WHERE id = $usuarioSesionId LIMIT 1");
    if ($resRolUsuario && $filaRolUsuario = $resRolUsuario->fetch_assoc()) {
        if (!empty($filaRolUsuario['rol'])) {
            $rolActual = strtolower(trim((string)$filaRolUsuario['rol']));
        }
    }
}

$esAdmin = ($rolActual === 'admin');

/*
|--------------------------------------------------------------------------
| REGISTRAR USUARIO
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'registrar_usuario') {
    $esAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    if ($permitir_registro != 1) {
        if ($esAjax) responderJsonUsuarios(['ok' => false, 'mensaje' => '❌ El registro está desactivado.']);
        $mensaje = "❌ El registro está desactivado.";
        $tipo_mensaje = 'error';
    } elseif ($solo_admin_registra == 1 && !$esAdmin) {
        if ($esAjax) responderJsonUsuarios(['ok' => false, 'mensaje' => '❌ Solo el administrador puede registrar usuarios.']);
        $mensaje = "❌ Solo el administrador puede registrar usuarios.";
        $tipo_mensaje = 'error';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmar_password = $_POST['confirmar_password'] ?? '';
        $rol = trim($_POST['rol'] ?? '');

        if ($nombre === '' || $usuario === '' || $correo === '' || $password === '' || $confirmar_password === '' || $rol === '') {
            $mensaje = "❌ Llena todos los campos obligatorios.";
            $tipo_mensaje = 'error';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "❌ Ingresa un correo válido.";
            $tipo_mensaje = 'error';
        } elseif ($password !== $confirmar_password) {
            $mensaje = "❌ Las contraseñas no coinciden.";
            $tipo_mensaje = 'error';
        } elseif (!passwordFuerteUsuarios($password)) {
            $mensaje = "❌ La contraseña debe tener mínimo 8 caracteres, mayúscula, minúscula, número y símbolo.";
            $tipo_mensaje = 'error';
        } elseif (!in_array($rol, ['admin', 'mostrador', 'produccion'], true)) {
            $mensaje = "❌ Ese rol no está permitido.";
            $tipo_mensaje = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE usuario = ? LIMIT 1");
            if (!$stmt) {
                $mensaje = "❌ Error al preparar validación de usuario: " . $conn->error;
                $tipo_mensaje = 'error';
            } else {
                $stmt->bind_param("s", $usuario);

                if (!$stmt->execute()) {
                    $mensaje = "❌ Error al validar usuario: " . $stmt->error;
                    $tipo_mensaje = 'error';
                } else {
                    $stmt->store_result();

                    if ($stmt->num_rows > 0) {
                        $mensaje = "❌ Ese usuario ya existe.";
                        $tipo_mensaje = 'error';
                    }
                }
                $stmt->close();
            }

            if ($mensaje === '') {
                $stmtCorreo = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? LIMIT 1");
                if (!$stmtCorreo) {
                    $mensaje = "❌ Error al preparar validación de correo: " . $conn->error;
                    $tipo_mensaje = 'error';
                } else {
                    $stmtCorreo->bind_param("s", $correo);

                    if (!$stmtCorreo->execute()) {
                        $mensaje = "❌ Error al validar correo: " . $stmtCorreo->error;
                        $tipo_mensaje = 'error';
                    } else {
                        $stmtCorreo->store_result();

                        if ($stmtCorreo->num_rows > 0) {
                            $mensaje = "❌ Ese correo ya existe.";
                            $tipo_mensaje = 'error';
                        }
                    }
                    $stmtCorreo->close();
                }
            }

            if ($mensaje === '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $estado = 'activo';

                $stmtInsert = $conn->prepare("INSERT INTO usuarios (nombre, usuario, correo, password, rol, estado) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmtInsert) {
                    $mensaje = "❌ Error al preparar registro: " . $conn->error;
                    $tipo_mensaje = 'error';
                } else {
                    $stmtInsert->bind_param("ssssss", $nombre, $usuario, $correo, $passwordHash, $rol, $estado);

                    if ($stmtInsert->execute()) {
                        if (function_exists('websocket_notify')) {
                            websocket_notify('usuarios_actualizados', 'usuarios', 'Usuario registrado', [
                                'accion' => 'registrar',
                                'usuario' => $usuario
                            ]);
                        }
                        if ($esAjax) responderJsonUsuarios(['ok' => true, 'mensaje' => '✅ Usuario registrado correctamente.']);
                        $mensaje = "✅ Usuario registrado correctamente.";
                        $tipo_mensaje = 'ok';
                    } else {
                        if ($esAjax) responderJsonUsuarios(['ok' => false, 'mensaje' => '❌ Error al registrar usuario: ' . $stmtInsert->error]);
                        $mensaje = "❌ Error al registrar usuario: " . $stmtInsert->error;
                        $tipo_mensaje = 'error';
                    }
                    $stmtInsert->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ACTUALIZAR USUARIO
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_usuario') {
    if (!$esAdmin) {
        $mensaje = "❌ Solo el administrador puede editar usuarios.";
        $tipo_mensaje = 'error';
    } else {
        $usuario_id = (int)($_POST['usuario_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $rol = trim($_POST['rol'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmar_password = $_POST['confirmar_password'] ?? '';

        if ($usuario_id <= 0 || $nombre === '' || $correo === '' || $rol === '') {
            $mensaje = "❌ Completa los campos obligatorios.";
            $tipo_mensaje = 'error';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "❌ Ingresa un correo válido.";
            $tipo_mensaje = 'error';
        } elseif (!in_array($rol, ['admin', 'mostrador', 'produccion'], true)) {
            $mensaje = "❌ Rol no permitido.";
            $tipo_mensaje = 'error';
        } elseif ($password !== '' && $password !== $confirmar_password) {
            $mensaje = "❌ Las contraseñas no coinciden.";
            $tipo_mensaje = 'error';
        } elseif ($password !== '' && !passwordFuerteUsuarios($password)) {
            $mensaje = "❌ La contraseña debe tener mínimo 8 caracteres, mayúscula, minúscula, número y símbolo.";
            $tipo_mensaje = 'error';
        } else {
            $stmtCorreo = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? AND id <> ? LIMIT 1");
            if (!$stmtCorreo) {
                $mensaje = "❌ Error al preparar validación de correo: " . $conn->error;
                $tipo_mensaje = 'error';
            } else {
                $stmtCorreo->bind_param("si", $correo, $usuario_id);

                if (!$stmtCorreo->execute()) {
                    $mensaje = "❌ Error al validar correo: " . $stmtCorreo->error;
                    $tipo_mensaje = 'error';
                } else {
                    $stmtCorreo->store_result();

                    if ($stmtCorreo->num_rows > 0) {
                        $mensaje = "❌ Ese correo ya está en uso.";
                        $tipo_mensaje = 'error';
                    }
                }
                $stmtCorreo->close();
            }

            if ($mensaje === '') {
                if ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtUpdate = $conn->prepare("UPDATE usuarios SET nombre = ?, correo = ?, rol = ?, password = ? WHERE id = ? LIMIT 1");
                    if ($stmtUpdate) {
                        $stmtUpdate->bind_param("ssssi", $nombre, $correo, $rol, $passwordHash, $usuario_id);
                    }
                } else {
                    $stmtUpdate = $conn->prepare("UPDATE usuarios SET nombre = ?, correo = ?, rol = ? WHERE id = ? LIMIT 1");
                    if ($stmtUpdate) {
                        $stmtUpdate->bind_param("sssi", $nombre, $correo, $rol, $usuario_id);
                    }
                }

                if (!$stmtUpdate) {
                    $mensaje = "❌ Error al preparar actualización: " . $conn->error;
                    $tipo_mensaje = 'error';
                } else {
                    if ($stmtUpdate->execute()) {
                        if (function_exists('websocket_notify')) {
                            websocket_notify('usuarios_actualizados', 'usuarios', 'Usuario actualizado', [
                                'accion' => 'actualizar',
                                'usuario_id' => $usuario_id
                            ]);
                        }
                        $mensaje = "✅ Usuario actualizado correctamente.";
                        $tipo_mensaje = 'ok';
                    } else {
                        $mensaje = "❌ No se pudo actualizar el usuario: " . $stmtUpdate->error;
                        $tipo_mensaje = 'error';
                    }
                    $stmtUpdate->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ELIMINAR USUARIO A PAPELERA
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar_usuario') {
    if (!$esAdmin) {
        $mensaje = "❌ Solo el administrador puede eliminar usuarios.";
        $tipo_mensaje = 'error';
    } else {
        $usuario_id = (int)($_POST['usuario_id'] ?? 0);
        $usuario_sesion = (int)($_SESSION['usuario_id'] ?? 0);

        if ($usuario_id <= 0) {
            $mensaje = "❌ Usuario inválido.";
            $tipo_mensaje = 'error';
        } elseif ($usuario_id === $usuario_sesion) {
            $mensaje = "❌ No puedes eliminar tu propio usuario.";
            $tipo_mensaje = 'error';
        } else {
            $resEliminar = $conn->query("SELECT * FROM usuarios WHERE id = $usuario_id LIMIT 1");
            $usuarioEliminar = ($resEliminar && $resEliminar->num_rows > 0) ? $resEliminar->fetch_assoc() : null;

            if (!$usuarioEliminar) {
                $mensaje = "❌ No se encontró el usuario.";
                $tipo_mensaje = 'error';
            } else {
                if (enviarUsuarioAPapelera($conn, 'usuarios', $usuario_id, $usuarioEliminar, $usuario_sesion)) {
                    if ($conn->query("DELETE FROM usuarios WHERE id = $usuario_id")) {
                        if (function_exists('websocket_notify')) {
                            websocket_notify('usuarios_actualizados', 'usuarios', 'Usuario eliminado', [
                                'accion' => 'eliminar',
                                'usuario_id' => $usuario_id
                            ]);
                        }
                        $mensaje = "🗑️ Usuario enviado a papelera.";
                        $tipo_mensaje = 'ok';
                    } else {
                        $mensaje = "❌ No se pudo eliminar el usuario.";
                        $tipo_mensaje = 'error';
                    }
                } else {
                    $mensaje = "❌ No se pudo enviar a papelera.";
                    $tipo_mensaje = 'error';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CONSULTAR USUARIOS
|--------------------------------------------------------------------------
*/
$usuarios = [];
$resLista = $conn->query("SELECT id, nombre, usuario, correo, rol, estado, creado_en FROM usuarios ORDER BY id DESC");
if ($resLista) {
    while ($fila = $resLista->fetch_assoc()) {
        $usuarios[] = $fila;
    }
}

$usuarioEditar = null;
if ($esAdmin && isset($_GET['editar'])) {
    $editarId = (int)($_GET['editar'] ?? 0);
    if ($editarId > 0) {
        $resEditar = $conn->query("SELECT id, nombre, usuario, correo, rol, estado FROM usuarios WHERE id = $editarId LIMIT 1");
        if ($resEditar && $resEditar->num_rows > 0) {
            $usuarioEditar = $resEditar->fetch_assoc();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Usuarios - Suave Urban</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --gold:#c89b3c;
            --gold-glow:rgba(200,155,60,0.4);
            --bg:#050505;
            --glass:rgba(15,15,15,0.78);
            --glass-border:rgba(200,155,60,0.15);
            --line:#333;
            --text-muted:#888;
            --shadow-gold:0 0 20px rgba(200,155,60,0.16);
        }

        *{ box-sizing:border-box; }
        html, body{ margin:0; padding:0; min-height:100%; }

        @keyframes fadeIn{
            from{opacity:0;transform:translateY(15px);}
            to{opacity:1;transform:translateY(0);}
        }

        @keyframes logoPulse{
            0%,100%{transform:scale(1);}
            50%{transform:scale(1.05);}
        }

        @keyframes glow{
            from{filter:drop-shadow(0 0 5px rgba(200,155,60,0.4));}
            to{filter:drop-shadow(0 0 15px rgba(200,155,60,0.7));}
        }

        body{
            background:
                <?php if (!empty($fondoContenido)): ?>
                linear-gradient(rgba(8,8,12,0.35), rgba(8,8,12,0.55)),
                url('<?php echo htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8'); ?>') center/cover fixed no-repeat;
                <?php else: ?>
                linear-gradient(135deg, #0b0b0f, #14151a);
                <?php endif; ?>;
            color:white;
            font-family:'Segoe UI',sans-serif;
            display:flex;
            min-height:100vh;
            overflow-x:hidden;
            position:relative;
        }

        body::before{
            content:"";
            position:fixed;
            inset:0;
            background:
                radial-gradient(circle at 20% 30%,rgba(200,155,60,.08) 0%,transparent 40%),
                radial-gradient(circle at 80% 70%,rgba(200,155,60,.06) 0%,transparent 40%);
            z-index:-1;
            pointer-events:none;
        }

        .mobile-topbar{ display:none; }
        .mobile-menu-toggle{ display:none; }
        .sidebar-overlay{ display:none; }

        .sidebar{
            width:85px;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $transparenciaSidebar . "), rgba(0,0,0," . $transparenciaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                    : "rgba(0,0,0," . $transparenciaSidebar . ")"; ?>;
            backdrop-filter:blur(15px);
            -webkit-backdrop-filter:blur(15px);
            border-right:1px solid var(--glass-border);
            display:flex;
            flex-direction:column;
            align-items:center;
            padding:15px 0;
            z-index:1000;
            position:fixed;
            top:0;
            left:0;
            height:100vh;
            overflow-y:auto;
            box-shadow:0 10px 40px rgba(0,0,0,0.35);
        }

        .logo-pos{
            width:55px;
            height:auto;
            margin-bottom:20px;
            animation:logoPulse 4s infinite, glow 3s infinite alternate;
        }

        .nav-controls{
            display:flex;
            flex-direction:column;
            gap:20px;
            margin-bottom:30px;
            border-bottom:1px solid var(--glass-border);
            padding-bottom:20px;
            width:100%;
            align-items:center;
        }

        .sidebar a{
            color:#555;
            font-size:22px;
            transition:0.3s;
            text-decoration:none;
            margin-bottom:18px;
        }

        .sidebar a:hover,
        .sidebar a.active{
            color:var(--gold);
            filter:drop-shadow(0 0 8px var(--gold));
        }

        .main{
            flex:1;
            margin-left:85px;
            padding:40px;
            width:calc(100% - 85px);
            min-width:0;
            animation:fadeIn 0.6s ease-out;
            backdrop-filter:blur(2px);
            -webkit-backdrop-filter:blur(2px);
        }

        .card{
            background:rgba(15,15,15,<?php echo $transparenciaPanel; ?>);
            backdrop-filter:blur(12px);
            -webkit-backdrop-filter:blur(12px);
            border:1px solid var(--glass-border);
            border-radius:20px;
            padding:25px;
            margin-bottom:30px;
            box-shadow:0 10px 40px rgba(0,0,0,0.5), 0 0 20px rgba(200,155,60,0.08);
            overflow:hidden;
            position:relative;
        }

        .card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(200,155,60,0.05), transparent 36%, transparent 64%, rgba(200,155,60,0.03));
            pointer-events:none;
        }

        .titulo{
            margin:0 0 25px 0;
            font-size:30px;
            font-weight:200;
            letter-spacing:1px;
        }

        .titulo span{
            color:var(--gold);
            font-weight:800;
        }

        .head-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            flex-wrap:wrap;
        }

        .socket-indicator{
            display:inline-flex;
            align-items:center;
            gap:8px;
            border-radius:999px;
            padding:8px 12px;
            font-size:12px;
            letter-spacing:0.5px;
            border:1px solid rgba(255,255,255,0.14);
            background:rgba(255,255,255,0.04);
        }

        .socket-indicator .dot-status{
            width:9px;
            height:9px;
            border-radius:50%;
            background:#ef4444;
            box-shadow:0 0 0 0 rgba(239,68,68,.4);
        }

        .socket-indicator.online .dot-status{
            background:#22c55e;
            box-shadow:0 0 8px rgba(34,197,94,.6);
        }

        .subtitulo{
            color:var(--gold);
            margin:0 0 18px 0;
            font-size:20px;
            font-weight:500;
        }

        .mensaje{
            padding:15px;
            border-radius:14px;
            margin-bottom:25px;
            border:1px solid #444;
            transition:opacity .4s ease;
            box-shadow:0 10px 30px rgba(0,0,0,0.25);
        }

        .mensaje.ok{
            background:rgba(90,180,90,0.10);
            border-color:rgba(90,180,90,0.35);
        }

        .mensaje.error{
            background:rgba(255,80,80,0.10);
            border-color:rgba(255,80,80,0.35);
        }

        .grid-form{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
            gap:18px;
            align-items:end;
        }

        .campo label{
            color:var(--gold);
            font-weight:bold;
            display:block;
            margin-bottom:10px;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1px;
        }

        .campo input, .campo select{
            width:100%;
            background:rgba(255,255,255,0.04);
            border:1px solid rgba(255,255,255,0.1);
            color:white;
            padding:14px;
            border-radius:12px;
            outline:none;
            transition:0.3s;
        }

        .campo select{ appearance:auto; }

        .campo select option{
            background:#111;
            color:#fff;
        }

        .campo input:focus, .campo select:focus{
            border-color:var(--gold);
            background:rgba(255,255,255,0.08);
            box-shadow:0 0 15px rgba(200,155,60,0.2);
        }

        .password-wrap{ position:relative; }
        .password-wrap input{ padding-right:46px; }

        .toggle-password{
            position:absolute;
            right:14px;
            top:50%;
            transform:translateY(-50%);
            color:#cfcfcf;
            cursor:pointer;
            font-size:16px;
            transition:0.3s;
            user-select:none;
        }

        .toggle-password:hover{ color:var(--gold); }

        .checklist{
            margin-top:8px;
            font-size:13px;
            color:#bbb;
            display:grid;
            gap:6px;
        }

        .check-item.ok{ color:#72e39a; }
        .check-item.err{ color:#ff9d9d; }

        .btn{
            background:linear-gradient(45deg, #c89b3c, #eec064);
            color:black;
            padding:14px 24px;
            border:none;
            border-radius:12px;
            font-weight:800;
            cursor:pointer;
            transition:0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            letter-spacing:0.8px;
        }

        .btn:hover{
            transform:scale(1.03) translateY(-3px);
            box-shadow:0 8px 20px var(--gold-glow);
        }

        .btn-sec{
            background:rgba(255,255,255,0.05);
            color:white;
            padding:10px 16px;
            border:1px solid rgba(255,255,255,0.12);
            border-radius:10px;
            font-weight:700;
            cursor:pointer;
            transition:0.3s;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-sec:hover{
            background:rgba(255,255,255,0.08);
            transform:translateY(-2px);
        }

        .btn-delete{
            background:linear-gradient(45deg, #a61d24, #dc3545);
            color:#fff;
            border:none;
        }

        .btn-delete:hover{
            box-shadow:0 8px 20px rgba(220,53,69,0.35);
        }

        .estado{
            display:inline-block;
            padding:7px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
        }

        .estado.activo{
            background:rgba(60,180,100,0.12);
            color:#72e39a;
            border:1px solid rgba(60,180,100,0.28);
        }

        .estado.inactivo{
            background:rgba(255,120,120,0.12);
            color:#ff9d9d;
            border:1px solid rgba(255,120,120,0.28);
        }

        .tabla-wrap{
            width:100%;
            overflow-x:auto;
            -webkit-overflow-scrolling:touch;
            margin-top:10px;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:920px;
        }

        th, td{
            padding:14px 12px;
            border-bottom:1px solid rgba(255,255,255,0.06);
            text-align:left;
            vertical-align:middle;
        }

        th{
            color:var(--gold);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1px;
            white-space:nowrap;
        }

        tr:hover td{
            background:rgba(200,155,60,0.04);
        }

        .bloqueo{
            padding:14px;
            border-radius:12px;
            background:rgba(255,180,0,0.08);
            border:1px solid rgba(255,180,0,0.25);
            color:#f4cf73;
            margin-bottom:20px;
        }

        .acciones-flex{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            justify-content:flex-end;
        }

        .acciones-flex form{ margin:0; }

        .nota{
            color:#cfcfcf;
            font-size:12px;
            margin-top:6px;
        }

        @media (max-width: 980px){
            body{ display:block; }

            .mobile-topbar{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                position:sticky;
                top:0;
                z-index:1100;
                padding:14px 16px;
                background:rgba(0,0,0,0.9);
                border-bottom:1px solid var(--glass-border);
                backdrop-filter:blur(10px);
                -webkit-backdrop-filter:blur(10px);
            }

            .mobile-topbar-left{
                display:flex;
                align-items:center;
                gap:10px;
                min-width:0;
            }

            .mobile-topbar-logo{
                width:38px;
                height:38px;
                object-fit:contain;
            }

            .mobile-topbar-title{
                font-size:14px;
                font-weight:700;
                color:#fff;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }

            .mobile-menu-toggle{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:42px;
                height:42px;
                border:1px solid rgba(255,255,255,0.08);
                background:rgba(255,255,255,0.06);
                color:var(--gold);
                border-radius:12px;
                font-size:18px;
                cursor:pointer;
            }

            .sidebar-overlay{
                display:block;
                position:fixed;
                inset:0;
                background:rgba(0,0,0,0.45);
                opacity:0;
                visibility:hidden;
                transition:0.3s ease;
                z-index:999;
            }

            .sidebar{
                width:280px;
                max-width:82vw;
                transform:translateX(-100%);
                transition:transform 0.3s ease;
            }

            body.menu-open .sidebar{ transform:translateX(0); }
            body.menu-open .sidebar-overlay{ opacity:1; visibility:visible; }

            .main{
                margin-left:0;
                width:100%;
                padding:20px 16px;
            }

            .card{
                padding:18px;
                border-radius:18px;
            }

            .titulo{
                font-size:28px;
                margin-bottom:18px;
            }

            table{ min-width:840px; }
        }

        @media (max-width: 700px){
            .main{ padding:16px 12px; }
            .card{ padding:14px; border-radius:16px; }
            .titulo{ font-size:24px; }
            .grid-form{ grid-template-columns:1fr; gap:14px; }
            .btn{ width:100%; }
            table{ min-width:760px; }
        }

        @media (max-width: 520px){
            .main{ padding:14px 10px; }
            .card{ padding:12px; }
            .mobile-topbar-title{ font-size:13px; }
            table{ min-width:700px; }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Usuarios Studio</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Volver al Dashboard"><i class="fas fa-home"></i></a>
            <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
            <a href="usuarios.php" class="active" title="Usuarios"><i class="fas fa-users"></i></a>
            <a href="papelera.php" title="Papelera"><i class="fas fa-trash-alt"></i></a>
        </div>
    </div>

    <div class="main">
        <div class="head-row">
            <h1 class="titulo">Gestión de <span>USUARIOS</span></h1>
            <div id="socketIndicator" class="socket-indicator">
                <span class="dot-status"></span>
                <span id="socketText">WS desconectado</span>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div id="mensajeFlash" class="mensaje <?php echo $tipo_mensaje === 'error' ? 'error' : 'ok'; ?>">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 class="subtitulo"><?php echo $usuarioEditar ? 'Editar Usuario' : 'Registrar Usuario'; ?></h2>

            <?php if ($permitir_registro != 1 && !$usuarioEditar): ?>
                <div class="bloqueo">El registro de usuarios está desactivado desde configuración.</div>
            <?php elseif (($solo_admin_registra == 1 && !$esAdmin) || !$esAdmin): ?>
                <div class="bloqueo">Solo el administrador puede hacer movimientos de usuarios.</div>
            <?php endif; ?>

            <form method="POST" id="formUsuario">
                <input type="hidden" name="accion" value="<?php echo $usuarioEditar ? 'actualizar_usuario' : 'registrar_usuario'; ?>">

                <?php if ($usuarioEditar): ?>
                    <input type="hidden" name="usuario_id" value="<?php echo (int)$usuarioEditar['id']; ?>">
                <?php endif; ?>

                <div class="grid-form">
                    <div class="campo">
                        <label>Nombre completo</label>
                        <input type="text" name="nombre" value="<?php echo htmlspecialchars($usuarioEditar['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$esAdmin ? 'readonly' : ''; ?>>
                    </div>

                    <div class="campo">
                        <label>Nombre de usuario</label>
                        <input type="text" name="usuario" value="<?php echo htmlspecialchars($usuarioEditar['usuario'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $usuarioEditar ? 'readonly' : (!$esAdmin ? 'readonly' : ''); ?>>
                    </div>

                    <div class="campo">
                        <label>Correo</label>
                        <input type="email" name="correo" value="<?php echo htmlspecialchars($usuarioEditar['correo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$esAdmin ? 'readonly' : ''; ?>>
                    </div>

                    <div class="campo">
                        <label>Rol</label>
                        <select name="rol" id="rol" <?php echo !$esAdmin ? 'disabled' : ''; ?>>
                            <option value="">Selecciona un rol</option>
                            <option value="admin" <?php echo (($usuarioEditar['rol'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="mostrador" <?php echo (($usuarioEditar['rol'] ?? '') === 'mostrador') ? 'selected' : ''; ?>>Mostrador</option>
                            <option value="produccion" <?php echo (($usuarioEditar['rol'] ?? '') === 'produccion') ? 'selected' : ''; ?>>Producción</option>
                        </select>
                        <?php if (!$esAdmin && $usuarioEditar): ?>
                            <input type="hidden" name="rol" value="<?php echo htmlspecialchars($usuarioEditar['rol'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="campo">
                        <label><?php echo $usuarioEditar ? 'Nueva contraseña' : 'Contraseña'; ?></label>
                        <div class="password-wrap">
                            <input type="password" name="password" id="password" placeholder="<?php echo $usuarioEditar ? 'Déjala vacía si no cambiará' : ''; ?>" <?php echo (!$usuarioEditar && $esAdmin) ? 'required' : ''; ?>>
                            <i class="fa-solid fa-eye toggle-password" data-target="password"></i>
                        </div>

                        <div id="password-checklist" class="checklist">
                            <div id="chk-length" class="check-item err">❌ mínimo 8 caracteres</div>
                            <div id="chk-upper" class="check-item err">❌ una mayúscula</div>
                            <div id="chk-lower" class="check-item err">❌ una minúscula</div>
                            <div id="chk-number" class="check-item err">❌ un número</div>
                            <div id="chk-symbol" class="check-item err">❌ un símbolo</div>
                        </div>
                    </div>

                    <div class="campo">
                        <label>Confirmar contraseña</label>
                        <div class="password-wrap">
                            <input type="password" name="confirmar_password" id="confirmar_password" placeholder="<?php echo $usuarioEditar ? 'Déjala vacía si no cambiará' : ''; ?>" <?php echo (!$usuarioEditar && $esAdmin) ? 'required' : ''; ?>>
                            <i class="fa-solid fa-eye toggle-password" data-target="confirmar_password"></i>
                        </div>
                    </div>
                </div>

                <div class="nota">La contraseña debe llevar mínimo 8 caracteres, mayúscula, minúscula, número y símbolo.</div>

                <div style="margin-top:22px; display:flex; gap:10px; flex-wrap:wrap;">
                    <?php if ($esAdmin): ?>
                        <button type="submit" class="btn"><?php echo $usuarioEditar ? 'GUARDAR CAMBIOS' : 'REGISTRAR USUARIO'; ?></button>
                        <?php if ($usuarioEditar): ?>
                            <a href="usuarios.php" class="btn-sec">Cancelar edición</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 class="subtitulo">Lista de Usuarios</h2>
            <div class="tabla-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Correo</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($usuarios)): ?>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td><?php echo (int)($u['id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars($u['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($u['usuario'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($u['correo'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($u['rol'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="estado <?php echo htmlspecialchars($u['estado'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($u['estado'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['creado_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($esAdmin): ?>
                                            <div class="acciones-flex">
                                                <a href="usuarios.php?editar=<?php echo (int)($u['id'] ?? 0); ?>" class="btn-sec">Editar</a>

                                                <?php if ((int)($u['id'] ?? 0) !== (int)($_SESSION['usuario_id'] ?? 0)): ?>
                                                    <form method="POST" onsubmit="return confirm('¿Enviar este usuario a papelera?');">
                                                        <input type="hidden" name="accion" value="eliminar_usuario">
                                                        <input type="hidden" name="usuario_id" value="<?php echo (int)($u['id'] ?? 0); ?>">
                                                        <button type="submit" class="btn-sec btn-delete">Eliminar</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="color:#666;">Sesión actual</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:#666;">Sin permisos</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; color:#777; padding:30px;">No hay usuarios registrados todavía.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const msg = document.getElementById("mensajeFlash");
        if (msg) {
            setTimeout(function () {
                msg.style.opacity = "0";
                setTimeout(function () {
                    msg.style.display = "none";
                }, 400);
            }, 1800);
        }

        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function abrirMenu() {
            body.classList.add('menu-open');
        }

        function cerrarMenu() {
            body.classList.remove('menu-open');
        }

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function () {
                if (body.classList.contains('menu-open')) {
                    cerrarMenu();
                } else {
                    abrirMenu();
                }
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', cerrarMenu);
        }

        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 980) {
                    cerrarMenu();
                }
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 980) {
                cerrarMenu();
            }
        });

        document.querySelectorAll('.toggle-password').forEach(function(btn) {
            btn.addEventListener('click', function () {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (!input) return;
                input.type = input.type === 'password' ? 'text' : 'password';
                this.classList.toggle('fa-eye-slash');
            });
        });

        const passwordInput = document.getElementById("password");
        const formUsuario = document.getElementById("formUsuario");
        const socketIndicator = document.getElementById('socketIndicator');
        const socketText = document.getElementById('socketText');
        let socket = null;
        let reconnectTimer = null;

        function setSocketStatus(conectado) {
            if (!socketIndicator || !socketText) return;
            socketIndicator.classList.toggle('online', !!conectado);
            socketText.textContent = conectado ? 'WS conectado' : 'WS desconectado';
        }

        function iniciarWebSocket() {
            try {
                const protocolo = window.location.protocol === 'https:' ? 'wss' : 'ws';
                const host = window.location.hostname || '127.0.0.1';
                socket = new WebSocket(`${protocolo}://${host}:8080`);

                socket.onopen = function() {
                    setSocketStatus(true);
                    console.log('WebSocket usuarios conectado');
                };

                socket.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        if (!data || data.modulo !== 'usuarios') return;

                        const tipo = data.tipo || '';
                        if (tipo === 'usuarios_actualizados' || tipo === 'refresh') {
                            window.location.reload();
                        }
                    } catch (e) {
                        console.log('Mensaje WS no válido', e);
                    }
                };

                socket.onerror = function(err) {
                    setSocketStatus(false);
                    console.log('Error WebSocket usuarios', err);
                };

                socket.onclose = function() {
                    setSocketStatus(false);
                    if (!reconnectTimer) {
                        reconnectTimer = setTimeout(function() {
                            reconnectTimer = null;
                            iniciarWebSocket();
                        }, 3500);
                    }
                };
            } catch (e) {
                setSocketStatus(false);
                console.log('No se pudo iniciar WebSocket de usuarios', e);
            }
        }

        function setCheck(id, ok, text) {
            const el = document.getElementById(id);
            if (!el) return;
            el.innerHTML = (ok ? "✔ " : "❌ ") + text;
            el.className = "check-item " + (ok ? "ok" : "err");
        }

        function evaluarPassword(pass) {
            const length = pass.length >= 8;
            const upper = /[A-Z]/.test(pass);
            const lower = /[a-z]/.test(pass);
            const number = /[0-9]/.test(pass);
            const symbol = /[\W_]/.test(pass);

            setCheck("chk-length", length, "mínimo 8 caracteres");
            setCheck("chk-upper", upper, "una mayúscula");
            setCheck("chk-lower", lower, "una minúscula");
            setCheck("chk-number", number, "un número");
            setCheck("chk-symbol", symbol, "un símbolo");

            return length && upper && lower && number && symbol;
        }

        if (passwordInput) {
            passwordInput.addEventListener("keyup", function() {
                evaluarPassword(passwordInput.value);
            });
            evaluarPassword(passwordInput.value);
        }

        if (formUsuario) {
            formUsuario.addEventListener("submit", function(e) {
                const esEdicion = <?php echo $usuarioEditar ? 'true' : 'false'; ?>;
                const pass = passwordInput ? passwordInput.value : '';

                if (!esEdicion || pass !== '') {
                    if (!evaluarPassword(pass)) {
                        e.preventDefault();
                        alert("La contraseña no cumple con la seguridad mínima.");
                    }
                }
            });
        }

        iniciarWebSocket();
    });
    </script>
</body>
</html>
