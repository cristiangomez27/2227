<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok'=>false,'mensaje'=>'Sesión no válida']);
    exit;
}
require_once 'config/database.php';
$uid = (int)$_SESSION['usuario_id'];
@$conn->query("UPDATE usuarios SET last_seen = NOW() WHERE id = {$uid} LIMIT 1");
echo json_encode(['ok'=>true,'mensaje'=>'Estado actualizado']);
