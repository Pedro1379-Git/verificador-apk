# Verificador – App Android (APK)

La app es un contenedor que incluye las pantallas de escaneo y se conecta por Wi‑Fi al servidor
(tu XAMPP). La base de datos sigue en la laptop.

## 1. Preparar el servidor
- Reemplaza `api.php` en `htdocs/verificador/public/` con el del ZIP `verificador_pedido_pallet.zip`
  (ahora permite que la app se conecte).
- Apache encendido; laptop y teléfono en la misma red Wi‑Fi.
- Permite Apache en el Firewall de Windows (red privada) y usa IP fija para la laptop.
- Dirección que usará la app: `IP-laptop/verificador/public` (la carpeta donde está api.php).

## 2. Generar el APK (una sola vez, en la laptop)
1. Instala **Android Studio** (https://developer.android.com/studio).
2. Abre Android Studio → *Open* → selecciona la carpeta `android` de este proyecto.
   Espera a que termine "Gradle sync" (descarga SDK, pide aceptar).
3. Menú *Build → Build Bundle(s) / APK(s) → Build APK(s)*.
4. Al terminar, clic en *locate*: `android/app/build/outputs/apk/debug/app-debug.apk`.
5. Pasa el APK al teléfono, ábrelo y permite "instalar apps desconocidas".

Por línea de comandos (con ANDROID_HOME configurado): `cd android` y `gradlew assembleDebug`.

## 3. Dirección del servidor
- **Al abrir la app:** la primera vez pide la dirección; "Probar conexión" valida y "Guardar y abrir"
  la guarda. Después abre directo; el botón ⚙ (arriba a la derecha) la cambia.
- **Fija en el código:** edita `www/config.js` → `SERVIDOR_POR_DEFECTO: 'http://192.168.1.50/verificador/public'`,
  y luego copia `www` a `android/app/src/main/assets/public` (o `npx cap sync android`) y reconstruye el APK.
  Una dirección guardada desde la app tiene prioridad sobre la fija.

## Notas
- La cámara usa el permiso nativo de Android (no necesita HTTPS ni certificado).
- La pantalla permanece encendida mientras la app está abierta.
- "Descargar Dock Audit" abre el menú de compartir/guardar de Android.
- Los botones de exportar CSV de la pantalla Embarques se usan desde la laptop (navegador).
- Si cambias los HTML de `public/`, vuelve a copiarlos a `www/` (con los scripts config.js/apk-shim.js ya incluidos).

## Compilar en línea (GitHub Actions)
Al subir cambios a `main` (o con *Actions → Compilar APK → Run workflow*) GitHub genera el APK.
Descárgalo en la ejecución, sección *Artifacts* → `verificador-apk` (contiene `app-debug.apk`).
La app solo incluye la pantalla de escaneo (sin módulo Embarques; ese se usa en la laptop). Las pantallas están en `www/` (escaneo.html, estilo.css); el flujo ejecuta `npx cap sync android` antes de compilar.
