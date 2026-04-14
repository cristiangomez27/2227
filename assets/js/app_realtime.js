(function () {
  if (window.__appRealtimeBooted) return;
  window.__appRealtimeBooted = true;

  var body = document.body || {};
  var module = (body.dataset && body.dataset.module) ? body.dataset.module : 'general';
  if (module === 'ventas') return;

  var wsUrl = (body.dataset && body.dataset.wsUrl) ? body.dataset.wsUrl : '';
  if (!wsUrl) {
    var proto = location.protocol === 'https:' ? 'wss://' : 'ws://';
    wsUrl = proto + location.hostname + ':8080';
  }

  var soundEnabled = (body.dataset && body.dataset.soundEnabled) !== '0';
  var soundFile = (body.dataset && body.dataset.soundFile) ? body.dataset.soundFile : '';
  var audio = null;
  if (soundEnabled && soundFile) {
    audio = new Audio(soundFile);
    audio.preload = 'auto';
  }

  function wsStatusBadge(texto, online) {
    var id = "wsStatusBadge";
    var el = document.getElementById(id);
    if (!el) {
      el = document.createElement("div");
      el.id = id;
      el.style.cssText = "position:fixed;left:12px;bottom:12px;z-index:9999;padding:7px 10px;border-radius:8px;font-size:12px;background:rgba(0,0,0,.72);color:#fff;border:1px solid rgba(255,255,255,.2)";
      document.body.appendChild(el);
    }
    el.textContent = texto;
    el.style.borderColor = online ? "rgba(34,197,94,.6)" : "rgba(239,68,68,.6)";
  }

  function toast(msg) {
    var el = document.getElementById('realtimeToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'realtimeToast';
      el.className = 'realtime-toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(function () { el.classList.remove('show'); }, 2500);
  }

  var retries = 0;
  function connect() {
    var socket;
    try { socket = new WebSocket(wsUrl); } catch (e) {
      setTimeout(connect, 2500); return;
    }

    socket.onopen = function () {
      retries = 0;
      document.body.classList.add('ws-online');
      document.body.classList.remove('ws-offline');
      wsStatusBadge('Tiempo real conectado', true);
    };

    socket.onmessage = function (event) {
      var data;
      try { data = JSON.parse(event.data); } catch (_e) { return; }
      if (!data || !data.tipo) return;

      var mapa = {
        pedido_nuevo: 'Pedido actualizado',
        estatus_actualizado: 'Producción actualizada',
        diseno_subido: 'Diseño recibido',
        cliente_nuevo: 'Cliente guardado',
        usuario_actualizado: 'Usuarios actualizados',
        refresh: 'Actualización disponible'
      };
      if (mapa[data.tipo]) {
        toast(mapa[data.tipo]);
        if (audio) {
          audio.currentTime = 0;
          audio.play().catch(function () {});
        }
      }

      if (data.tipo === 'refresh' || data.reload === true) {
        setTimeout(function () { location.reload(); }, 650);
      }
    };

    socket.onclose = function () {
      document.body.classList.remove('ws-online');
      document.body.classList.add('ws-offline');
      wsStatusBadge('Tiempo real reconectando...', false);
      retries += 1;
      setTimeout(connect, Math.min(2500 * retries, 12000));
    };

    socket.onerror = function () {};
  }

  connect();
})();
