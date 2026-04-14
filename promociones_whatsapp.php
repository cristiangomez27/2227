<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: index.php'); exit; }
require_once 'config/database.php';
require_once __DIR__ . '/includes/realtime_config.php';
$appRealtime = app_realtime_config(isset($conn)?$conn:null);
$appRealtime['module'] = 'promociones_whatsapp';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Promociones WhatsApp</title>
  <link rel="stylesheet" href="assets/css/dashboard_effect.css">
  <style>
    body{margin:0;background:#050505;color:#fff;font-family:Segoe UI,sans-serif}
    main{max-width:920px;margin:24px auto;padding:14px}
    .card{background:rgba(15,15,15,.6);border-radius:12px;padding:18px}
    textarea{width:100%;min-height:130px;border-radius:10px;padding:12px;background:#111;color:#fff;border:1px solid rgba(255,255,255,.18)}
    button{padding:11px 16px;border-radius:10px;border:none;background:#c89b3c;color:#121212;font-weight:700}
    .status{margin-top:10px;padding:10px;border-radius:10px;display:none}
    .ok{background:#1f5f34}.err{background:#702626}
  </style>
</head>
<body data-module="<?php echo htmlspecialchars($appRealtime['module'],ENT_QUOTES,'UTF-8');?>" data-ws-url="<?php echo htmlspecialchars($appRealtime['ws_url'] ?? '',ENT_QUOTES,'UTF-8');?>" data-sound-enabled="<?php echo !empty($appRealtime['sound_enabled'])?'1':'0';?>" data-sound-file="<?php echo htmlspecialchars($appRealtime['sound_file'] ?? '',ENT_QUOTES,'UTF-8');?>">
  <main>
    <section class="card glass-card">
      <h2>Promociones WhatsApp</h2>
      <p>Envío masivo con Green API (credenciales existentes).</p>
      <form id="promoForm">
        <textarea name="mensaje" required placeholder="Escribe la promoción..."></textarea>
        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit">Enviar promoción</button>
          <a href="dashboard.php" style="color:#c89b3c">Volver</a>
        </div>
      </form>
      <div id="promoStatus" class="status"></div>
    </section>
  </main>
  <script src="assets/js/app_realtime.js"></script>
  <script>
    document.getElementById('promoForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const status = document.getElementById('promoStatus');
      status.style.display='none';
      const fd = new FormData(this);
      try {
        const r = await fetch('enviar_promocion.php', { method:'POST', body: fd });
        const data = await r.json();
        if (data.ok) {
          status.className = 'status ok';
          status.textContent = `Envío completado. Enviados: ${data.enviados || 0}. Errores: ${(data.errores||[]).length}.`;
          this.reset();
        } else {
          status.className = 'status err';
          status.textContent = data.mensaje || 'No se pudo enviar la promoción.';
        }
      } catch (err) {
        status.className = 'status err';
        status.textContent = 'Error de red enviando promoción.';
      }
      status.style.display='block';
    });
  </script>
</body>
</html>
