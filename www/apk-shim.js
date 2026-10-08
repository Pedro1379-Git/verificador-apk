// Puente de la app Android: redirige api.php al servidor configurado y comparte los PDF.
(function () {
  var CFG = window.APK_CONFIG || {};
  function norm(s) {
    s = String(s || '').trim().replace(/\/+$/, '').replace(/\/api\.php.*$/i, '');
    if (!s) return '';
    if (!/^https?:\/\//i.test(s)) s = 'http://' + s;
    return s;
  }
  function base() {
    var v = ''; try { v = localStorage.getItem('servidor') || ''; } catch (e) {}
    return norm(v || CFG.SERVIDOR_POR_DEFECTO);
  }
  window.APK = { base: base, norm: norm };

  var f0 = window.fetch.bind(window);
  window.fetch = function (u, o) {
    if (typeof u === 'string' && /^api\.php/.test(u)) {
      var b = base();
      if (!b) { location.href = 'index.html?cfg=1'; return Promise.reject(new Error('Sin servidor configurado')); }
      u = b + '/' + u;
    }
    return f0(u, o);
  };

  // Guardar/compartir PDF (en el WebView no funcionan las descargas por blob)
  function nativo() { return window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform(); }
  function parchePDF() {
    var J = window.jspdf && window.jspdf.jsPDF;
    if (!J || !J.API || J.API.__apk) return;
    J.API.__apk = 1;
    var orig = J.API.save;
    J.API.save = function (name) {
      if (!nativo() || !Capacitor.Plugins.Filesystem || !Capacitor.Plugins.Share) return orig.apply(this, arguments);
      var b64 = this.output('datauristring').split(',')[1];
      name = name || 'DockAudit.pdf';
      return Capacitor.Plugins.Filesystem.writeFile({ path: name, data: b64, directory: 'CACHE' })
        .then(function (r) { return Capacitor.Plugins.Share.share({ title: name, url: r.uri, dialogTitle: 'Guardar o compartir PDF' }); })
        .catch(function (e) { if (!/cancel/i.test(String(e && e.message || e))) alert('No se pudo guardar el PDF: ' + (e && e.message || e)); });
    };
  }
  parchePDF();
  document.addEventListener('DOMContentLoaded', function () {
    parchePDF();
    // En la app no hay barra de navegación ni módulo Embarques: el campo de escaneo queda siempre arriba
    var m = document.querySelector('main');
    if (m && CFG.VERSION_APP) { var v = document.createElement('div'); v.style.cssText = 'text-align:center;color:#5B6780;font-size:11px;padding:6px 0'; v.textContent = 'App v' + CFG.VERSION_APP; m.appendChild(v); }
    var n = document.querySelector('header.nav'); if (n) n.style.display = 'none';
    var row = document.querySelector('#vScan .row');
    if (row && !document.getElementById('cfg')) {
      var a = document.createElement('a'); a.id = 'cfg'; a.href = 'index.html?cfg=1'; a.textContent = '⚙'; a.title = 'Servidor'; a.style.display = 'flex';
      row.appendChild(a);
    }
  });
})();
