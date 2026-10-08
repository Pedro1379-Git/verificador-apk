# Verificador de embarques (YAGM)

Sistema para verificar que cada embarque (ruta + dock + hora) se prepare completo y en orden:
carga de MAN / ORDER / KAN de Toyota → Dock Audit con QR → escaneo de manifiestos y etiquetas → PDF auditado.

**Versión 2.2** · diseño azul (tema claro) · PHP + SQLite · app Android con Capacitor.

## Contenido del repositorio

| Carpeta | Qué es |
|---|---|
| `escritorio/verificador/` | **Versión de escritorio / servidor** (XAMPP). Módulo Embarques para la laptop y pantalla de escaneo web. |
| `android/`, `www/`, `capacitor.config.json`, `package.json` | **App Android** (solo escaneo). GitHub Actions la compila. |
| `manual/` | **Manual de usuario** (PDF, con capturas de pantalla). |
| `fuentes/` | Código fuente de `dockpdf.js` (generador del Dock Audit auditado en PDF). |
| `.github/workflows/apk.yml` | Compilación del APK en línea. |

## 1. Versión de escritorio (servidor XAMPP)
1. Copia `escritorio/verificador/` a `C:\xampp\htdocs\verificador\`.
2. Enciende Apache en XAMPP. La base de datos SQLite se crea sola en `datos/` (no se sube al repositorio).
3. Laptop: <http://localhost/verificador/public/embarques.html> (Embarques) y `escaneo.html` (escaneo).
4. Teléfono (misma red Wi‑Fi): `http://IP-DE-LA-LAPTOP/verificador/public/escaneo.html`. La cámara en el navegador exige HTTPS; en la app Android no.
5. Se recomienda IP fija en la laptop y permitir Apache en el Firewall de Windows (red privada).

## 2. App Android
La app solo incluye el escaneo y se conecta al servidor por Wi‑Fi.
- **Descargar el APK:** pestaña **Actions** → ejecución más reciente (verde) → *Artifacts* → `verificador-apk-ejecucion-N` (zip con `app-debug.apk`).
- **Instalar:** permite «instalar apps desconocidas». La firma es fija, así que cada versión se instala sobre la anterior (si vienes de una compilación anterior a la 5, desinstala una vez).
- **Servidor:** al abrir la app escribe `IP/verificador/public` (por ejemplo `192.168.1.50/verificador/public`). El botón ⚙ lo cambia. También puede quedar fijo en `www/config.js`.
- **Compilar:** cada `push` a `main` (que toque la app) genera un APK; también con *Run workflow*.
- Detalles en `LEEME_APK.md`.

## 3. Manual
`manual/Manual_Verificador_Embarques_v2.pdf`

## 4. Mantenimiento
- Las pantallas de la app (`www/escaneo.html`, `www/estilo.css`, `www/dockpdf.js`) son copia de `escritorio/verificador/public/` con los scripts `config.js` y `apk-shim.js` añadidos. Si cambias las pantallas de escritorio, actualiza también `www/`.
- `dockpdf.js` = jsPDF 2.5.2 + qrcode-generator + `fuentes/dockpdf.src.js`.

## Notas
- Repositorio público: no subas MAN/ORDER/KAN reales ni la base `datos/*.sqlite`.
- La clave de firma del APK (`android/app/debug.keystore`) es de depuración y está incluida a propósito para permitir actualizaciones.
