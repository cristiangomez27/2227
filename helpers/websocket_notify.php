<?php
if (!function_exists('websocket_notify')) {
    function websocket_notify(string $tipo, string $modulo, string $mensaje, array $extra = []): bool
    {
        $payload = array_merge([
            'tipo' => $tipo,
            'modulo' => $modulo,
            'mensaje' => $mensaje,
            'ts' => date('c')
        ], $extra);

        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents($logDir . '/websocket_events.log', json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        return true;
    }
}
