<?php

/*
==============================
CONEXIÓN BASE DE DATOS
==============================
*/

$DB_HOST = getenv('DB_HOST') ?: "localhost";
$DB_USER = getenv('DB_USER') ?: "u412805401_suaveurbanst";
$DB_PASS = getenv('DB_PASS') ?: "Adamitas27@";
$DB_NAME = getenv('DB_NAME') ?: "u412805401_suaveurbanst";
$DB_PORT = (int)(getenv('DB_PORT') ?: 3306);

/*
==============================
URL DEL SISTEMA
==============================
*/

$APP_URL = getenv('APP_URL') ?: "https://suaveurbanstudio.com.mx";

/*
==============================
HELPERS ERRORES
==============================
*/

if (!function_exists('app_log_error')) {
    function app_log_error(string $message): void
    {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents($logDir . '/php_errors.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('app_fail_gracefully')) {
    function app_fail_gracefully(string $publicMessage): void
    {
        http_response_code(503);
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sistema temporalmente no disponible</title><style>body{font-family:Segoe UI,sans-serif;background:#050505;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{max-width:720px;background:#121212;border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:20px}h1{margin-top:0;color:#c89b3c}</style></head><body><div class="card"><h1>Servicio temporalmente no disponible</h1><p>' . htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8') . '</p><p>Revisa la configuración de MySQL/Hostinger y vuelve a intentar.</p></div></body></html>';
        exit;
    }
}

/*
==============================
CONEXIÓN MYSQL
==============================
*/

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);

if (!$conn || $conn->connect_errno) {
    $msg = 'MySQL connection failed (' . ($conn ? $conn->connect_errno : 'N/A') . '): ' . ($conn ? $conn->connect_error : 'No mysqli object');
    app_log_error($msg);
    app_fail_gracefully('No se pudo conectar a la base de datos.');
}

$conn->set_charset("utf8mb4");

/*
==============================
CONSTANTES DEL SISTEMA
==============================
*/

define("APP_URL", $APP_URL);

/*
==============================
CREDENCIALES GREEN API SEGURAS
==============================
*/

$securePaths = [
    __DIR__ . "/private/secure_greenapi.php",
    __DIR__ . "/../private/secure_greenapi.php"
];

$secureLoaded = false;
foreach ($securePaths as $securePath) {
    if (is_file($securePath)) {
        require_once $securePath;
        $secureLoaded = true;
        break;
    }
}

if (!$secureLoaded) {
    app_log_error('secure_greenapi.php no encontrado en rutas esperadas');
}

?>
