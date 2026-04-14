<?php
require_once __DIR__ . '/../greenapi_helper.php';

if (!function_exists('mensajeria_enviar_whatsapp')) {
    function mensajeria_enviar_whatsapp(string $telefono, string $mensaje): array
    {
        $cred = cargarCredencialesGreenApi();
        if (empty($cred['ok'])) {
            return ['ok' => false, 'mensaje' => $cred['mensaje'] ?? 'Credenciales no disponibles'];
        }

        $telefono = preg_replace('/\D+/', '', $telefono);
        if ($telefono === '') {
            return ['ok' => false, 'mensaje' => 'Teléfono inválido'];
        }

        $payload = [
            'chatId' => $telefono . '@c.us',
            'message' => $mensaje,
        ];

        $url = sprintf(
            'https://api.green-api.com/waInstance%s/sendMessage/%s',
            rawurlencode($cred['instance']),
            rawurlencode($cred['token'])
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['ok' => false, 'mensaje' => 'Error de comunicación con Green API: ' . $err];
        }

        $resp = json_decode((string)$raw, true);
        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'mensaje' => 'Mensaje enviado', 'response' => $resp];
        }

        return [
            'ok' => false,
            'mensaje' => 'Green API respondió con error',
            'http' => $http,
            'response' => $resp,
        ];
    }
}
