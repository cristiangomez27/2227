<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok'=>false,'mensaje'=>'Sesión no válida']);
    exit;
}
require_once 'config/database.php';

$usuarioId = (int)$_SESSION['usuario_id'];
$tipo = $_GET['tipo'] ?? 'grupal';
$dest = isset($_GET['destinatario_id']) ? (int)$_GET['destinatario_id'] : 0;

$mensajes = [];
if ($tipo === 'privado' && $dest > 0) {
    $sql = "SELECT m.*, u.nombre AS remitente_nombre
            FROM mensajes_internos m
            JOIN usuarios u ON u.id = m.remitente_id
            WHERE ((m.remitente_id = ? AND m.destinatario_id = ?) OR (m.remitente_id = ? AND m.destinatario_id = ?))
            ORDER BY m.id DESC LIMIT 80";
    $st = $conn->prepare($sql);
    $st->bind_param('iiii', $usuarioId, $dest, $dest, $usuarioId);
} else {
    $sql = "SELECT m.*, u.nombre AS remitente_nombre
            FROM mensajes_internos m
            JOIN usuarios u ON u.id = m.remitente_id
            WHERE m.tipo = 'grupal'
            ORDER BY m.id DESC LIMIT 80";
    $st = $conn->prepare($sql);
}

if ($st) {
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $mensajes[] = [
            'id' => (int)$row['id'],
            'mensaje' => (string)$row['mensaje'],
            'tipo' => (string)$row['tipo'],
            'remitente_id' => (int)$row['remitente_id'],
            'remitente_nombre' => (string)($row['remitente_nombre'] ?? 'Usuario'),
            'creado_en' => (string)($row['creado_en'] ?? ''),
        ];
    }
    $st->close();
}

$usuarios = [];
$q = @$conn->query("SELECT id, nombre, usuario FROM usuarios WHERE id <> {$usuarioId} ORDER BY nombre ASC");
if ($q) {
    while ($u = $q->fetch_assoc()) {
        $usuarios[] = [
            'id' => (int)$u['id'],
            'nombre' => (string)($u['nombre'] ?: $u['usuario']),
        ];
    }
}

echo json_encode(['ok'=>true,'mensajes'=>array_reverse($mensajes),'usuarios'=>$usuarios], JSON_UNESCAPED_UNICODE);
