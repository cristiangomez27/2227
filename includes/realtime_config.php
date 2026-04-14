<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('app_realtime_config')) {
    function app_realtime_config(mysqli $conn = null): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cfg = [
            'enabled' => true,
            'connect_error_message' => 'Sin conexión en tiempo real',
            'ws_url' => '',
            'sound_enabled' => true,
            'sound_file' => '',
            'module' => '',
            'role' => strtolower((string)($_SESSION['rol'] ?? '')),
            'user_id' => (int)($_SESSION['usuario_id'] ?? 0),
        ];

        if ($conn) {
            $res = @$conn->query("SHOW COLUMNS FROM configuracion");
            if ($res) {
                $cols = [];
                while ($r = $res->fetch_assoc()) {
                    $cols[] = $r['Field'];
                }

                $candidatosSonido = ['sonido_nuevo_pedido', 'sonido_alerta', 'sonido_sistema'];
                $select = [];
                foreach (['websocket_url', 'websocket_enabled', 'sonidos_activos'] as $col) {
                    if (in_array($col, $cols, true)) {
                        $select[] = $col;
                    }
                }
                foreach ($candidatosSonido as $col) {
                    if (in_array($col, $cols, true)) {
                        $select[] = $col;
                    }
                }

                if (!empty($select)) {
                    $q = @$conn->query("SELECT " . implode(', ', array_unique($select)) . " FROM configuracion WHERE id = 1 LIMIT 1");
                    if ($q && $q->num_rows > 0) {
                        $row = $q->fetch_assoc();
                        if (!empty($row['websocket_url'])) {
                            $cfg['ws_url'] = trim((string)$row['websocket_url']);
                        }
                        if (isset($row['websocket_enabled'])) {
                            $cfg['enabled'] = (string)$row['websocket_enabled'] !== '0';
                        }
                        if (isset($row['sonidos_activos'])) {
                            $cfg['sound_enabled'] = (string)$row['sonidos_activos'] !== '0';
                        }
                        foreach ($candidatosSonido as $col) {
                            if (!empty($row[$col])) {
                                $cfg['sound_file'] = (string)$row[$col];
                                break;
                            }
                        }
                    }
                }
            }
        }

        $envWs = trim((string)(getenv('WEBSOCKET_URL') ?: ''));
        if ($envWs !== '') {
            $cfg['ws_url'] = $envWs;
        }

        $envEnabled = getenv('WEBSOCKET_ENABLED');
        if ($envEnabled !== false && $envEnabled !== '') {
            $cfg['enabled'] = in_array(strtolower((string)$envEnabled), ['1','true','on','yes'], true);
        }

        if ($cfg['ws_url'] === '') {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = preg_replace('/:\d+$/', '', $host);
            $cfg['ws_url'] = $proto . '://' . $host . ':8080';
        }

        $cache = $cfg;
        return $cache;
    }
}
