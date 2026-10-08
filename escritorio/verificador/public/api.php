<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Headers: Content-Type');header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
$dir = __DIR__ . '/../datos';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$db = new PDO('sqlite:' . $dir . '/verificador.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;");
$db->exec("
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS pedido_lineas(
  orden TEXT NOT NULL, parte TEXT NOT NULL, cantidad INTEGER NOT NULL, PRIMARY KEY(orden, parte));
CREATE TABLE IF NOT EXISTS verificaciones(
  id INTEGER PRIMARY KEY AUTOINCREMENT, orden TEXT NOT NULL, manifiesto TEXT, dispositivo TEXT,
  estado TEXT NOT NULL DEFAULT 'abierta',
  inicio TEXT NOT NULL DEFAULT (datetime('now','localtime')), fin TEXT);
CREATE TABLE IF NOT EXISTS escaneos(
  id INTEGER PRIMARY KEY AUTOINCREMENT, verificacion_id INTEGER NOT NULL REFERENCES verificaciones(id),
  qr TEXT NOT NULL, parte TEXT, piezas INTEGER, resultado TEXT NOT NULL,
  hora TEXT NOT NULL DEFAULT (datetime('now','localtime')));
CREATE INDEX IF NOT EXISTS ix_esc_qr ON escaneos(qr);
CREATE INDEX IF NOT EXISTS ix_ver_orden ON verificaciones(orden);
CREATE TABLE IF NOT EXISTS plan_pallets(dock TEXT,ruta TEXT,salida TEXT,orden TEXT,pallet INTEGER,codigos TEXT,serie TEXT,palcode TEXT,mros TEXT,PRIMARY KEY(dock,ruta,salida,orden,pallet,codigos));
CREATE TABLE IF NOT EXISTS plan_orden(orden TEXT,codigo TEXT,parte TEXT,pzas INTEGER,cajas INTEGER,PRIMARY KEY(orden,codigo));
CREATE TABLE IF NOT EXISTS embarques(id TEXT PRIMARY KEY,ruta TEXT,dock TEXT,salida TEXT,estatus TEXT NOT NULL DEFAULT 'creado',
  pallets INTEGER,cajas INTEGER,racks INTEGER,creado TEXT DEFAULT (datetime('now','localtime')),actualizado TEXT DEFAULT (datetime('now','localtime')));
CREATE TABLE IF NOT EXISTS embarque_pallets(embarque_id TEXT,orden TEXT,serie TEXT,sufijo TEXT,palcode TEXT,skids INTEGER,PRIMARY KEY(embarque_id,orden));
CREATE TABLE IF NOT EXISTS embarque_lineas(embarque_id TEXT,orden TEXT,codigo TEXT,parte TEXT,pzas INTEGER,es_rack INTEGER,esperado INTEGER,kan INTEGER,PRIMARY KEY(embarque_id,orden,codigo));
CREATE TABLE IF NOT EXISTS embarque_log(id INTEGER PRIMARY KEY AUTOINCREMENT,embarque_id TEXT,estatus TEXT,nota TEXT,hora TEXT DEFAULT (datetime('now','localtime')));
CREATE TABLE IF NOT EXISTS plan_kanban(dock TEXT,orden TEXT,codigo TEXT,parte TEXT,descripcion TEXT,cajas INTEGER,pzas INTEGER,PRIMARY KEY(dock,orden,codigo));
");

// Migraciones: columnas de escaneo y IDs sin guiones
$cols = array_column($db->query("PRAGMA table_info(embarque_pallets)")->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['estado' => "TEXT NOT NULL DEFAULT 'pendiente'", 'razon' => 'TEXT', 'inicio' => 'TEXT', 'fin' => 'TEXT', 'manifiestos' => 'TEXT', 'mros' => 'TEXT'] as $c => $t)
  if (!in_array($c, $cols)) $db->exec("ALTER TABLE embarque_pallets ADD COLUMN $c $t");
$db->exec("CREATE TABLE IF NOT EXISTS embarque_escaneos(id INTEGER PRIMARY KEY AUTOINCREMENT,embarque_id TEXT,orden TEXT,qr TEXT,parte TEXT,codigo TEXT,piezas INTEGER,resultado TEXT,aviso TEXT,hora TEXT DEFAULT (datetime('now','localtime')));
CREATE INDEX IF NOT EXISTS ix_ee_qr ON embarque_escaneos(qr);
CREATE TABLE IF NOT EXISTS embarque_saltos(id INTEGER PRIMARY KEY AUTOINCREMENT,embarque_id TEXT,orden TEXT,razon TEXT,hora TEXT DEFAULT (datetime('now','localtime')));");
if ($db->query("SELECT COUNT(*) FROM embarques WHERE id LIKE '%-%'")->fetchColumn()) {
  foreach (['embarques', 'embarque_pallets', 'embarque_lineas', 'embarque_log'] as $t) { $k = $t === 'embarques' ? 'id' : 'embarque_id';
    $db->exec("UPDATE $t SET $k=REPLACE($k,'-','') WHERE $k LIKE '%-%'"); }
}

function out($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
// PHP < 8.1 devuelve los enteros de SQLite como texto: se convierten para que el navegador sume y no concatene
function nums($rows, $keys) { foreach ($rows as &$r) foreach ($keys as $k) if (isset($r[$k]) && is_numeric($r[$k])) $r[$k] = (int)$r[$k]; unset($r); return $rows; }
function norm_parte($s) { return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s)); }
function meta($db, $k) { $s = $db->prepare('SELECT v FROM meta WHERE k=?'); $s->execute([$k]); return $s->fetchColumn() ?: ''; }

function snap($db, $id) { // estado actual de una verificación
  $s = $db->prepare("SELECT parte, SUM(piezas) FROM escaneos WHERE verificacion_id=? AND resultado='OK' GROUP BY parte");
  $s->execute([$id]); $esc = $s->fetchAll(PDO::FETCH_KEY_PAIR);
  $s = $db->prepare("SELECT id, parte, piezas, resultado, substr(hora,12,8) AS hora FROM escaneos WHERE verificacion_id=? ORDER BY id DESC LIMIT 8");
  $s->execute([$id]);
  return ['escaneado' => (object)$esc, 'recientes' => $s->fetchAll(PDO::FETCH_ASSOC)];
}

function esc_estado($db, $id) { // estado completo del embarque para la pantalla de escaneo
  $s = $db->prepare('SELECT * FROM embarques WHERE id=?'); $s->execute([$id]); $e = $s->fetch(PDO::FETCH_ASSOC);
  if (!$e) out(404, ['error' => 'Embarque no encontrado.']);
  $s = $db->prepare("SELECT codigo, orden, COUNT(*) FROM embarque_escaneos WHERE embarque_id=? AND resultado='OK' GROUP BY orden, codigo"); $s->execute([$id]);
  $cnt = []; foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) $cnt[$r[1] . '|' . $r[0]] = (int)$r[2];
  $s = $db->prepare('SELECT * FROM embarque_pallets WHERE embarque_id=? ORDER BY orden'); $s->execute([$id]); $pal = $s->fetchAll(PDO::FETCH_ASSOC);
  $s = $db->prepare('SELECT * FROM embarque_lineas WHERE embarque_id=? ORDER BY codigo'); $s->execute([$id]); $L = $s->fetchAll(PDO::FETCH_ASSOC);
  $abierto = null; $sig = null;
  foreach ($pal as &$p) {
    $p['lineas'] = []; $p['skids'] = (int)$p['skids'];
    foreach ($L as $l) if ($l['orden'] === $p['orden']) { $l['escaneado'] = $cnt[$l['orden'] . '|' . $l['codigo']] ?? 0; $l['esperado'] = (int)$l['esperado']; $l['es_rack'] = (int)$l['es_rack']; $l['pzas'] = (int)$l['pzas']; $p['lineas'][] = $l; }
    $p['esperado'] = array_sum(array_column($p['lineas'], 'esperado')); $p['escaneado'] = array_sum(array_column($p['lineas'], 'escaneado'));
    if ($p['estado'] === 'en_proceso') $abierto = $p['orden'];
    if ($sig === null && $p['estado'] === 'pendiente') $sig = $p['orden'];
  } unset($p);
  $s = $db->prepare("SELECT orden,parte,resultado,aviso,substr(hora,12,8) AS hora FROM embarque_escaneos WHERE embarque_id=? ORDER BY id DESC LIMIT 8"); $s->execute([$id]);
  return ['embarque' => $e, 'pallets' => $pal, 'abierto' => $abierto, 'siguiente' => $sig, 'recientes' => $s->fetchAll(PDO::FETCH_ASSOC)];
}

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$a = $_GET['a'] ?? '';

try {
  switch ($a) {
    case 'status':
      out(200, [
        'archivo'   => meta($db, 'archivo'),
        'importado' => meta($db, 'importado'),
        'ordenes'   => (int)$db->query('SELECT COUNT(DISTINCT orden) FROM pedido_lineas')->fetchColumn(),
      ]);

    case 'import': // reemplaza el pedido completo; el historial de verificaciones se conserva
      $rows = $in['rows'] ?? [];
      if (!is_array($rows) || !$rows) out(400, ['error' => 'El pedido viene vacío.']);
      $db->beginTransaction();
      $db->exec('DELETE FROM pedido_lineas');
      $ins = $db->prepare('INSERT INTO pedido_lineas(orden,parte,cantidad) VALUES(?,?,?)
                           ON CONFLICT(orden,parte) DO UPDATE SET cantidad=cantidad+excluded.cantidad');
      foreach ($rows as $r) {
        $o = trim((string)($r['orden'] ?? '')); $p = strtoupper(trim((string)($r['parte'] ?? ''))); $c = (int)($r['cantidad'] ?? 0);
        if (preg_match('/^\d{10}$/', $o) && $p !== '' && $c > 0) $ins->execute([$o, $p, $c]);
      }
      $up = $db->prepare('INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
      $up->execute(['archivo', substr((string)($in['archivo'] ?? ''), 0, 120)]);
      $up->execute(['importado', date('Y-m-d H:i:s')]);
      $db->commit();
      out(200, [
        'ordenes' => (int)$db->query('SELECT COUNT(DISTINCT orden) FROM pedido_lineas')->fetchColumn(),
        'lineas'  => (int)$db->query('SELECT COUNT(*) FROM pedido_lineas')->fetchColumn(),
      ]);

    case 'abrir': // abre la verificación de una orden, o retoma la que sigue abierta
      $o = (string)($in['orden'] ?? '');
      if (!preg_match('/^\d{10}$/', $o)) out(400, ['error' => 'Número de orden inválido.']);
      $s = $db->prepare('SELECT parte, cantidad FROM pedido_lineas WHERE orden=? ORDER BY parte');
      $s->execute([$o]);
      $partes = $s->fetchAll(PDO::FETCH_KEY_PAIR);
      if (!$partes) out(404, ['error' => "La orden $o no está en el pedido cargado."]);
      $s = $db->prepare("SELECT id FROM verificaciones WHERE orden=? AND estado='abierta' ORDER BY id DESC LIMIT 1");
      $s->execute([$o]);
      $id = $s->fetchColumn(); $reanudada = (bool)$id;
      if (!$id) {
        $s = $db->prepare('INSERT INTO verificaciones(orden,manifiesto,dispositivo) VALUES(?,?,?)');
        $s->execute([$o, substr((string)($in['manifiesto'] ?? ''), 0, 300), substr((string)($in['dispositivo'] ?? ''), 0, 120)]);
        $id = $db->lastInsertId();
      }
      out(200, array_merge(['id' => (int)$id, 'reanudada' => $reanudada, 'partes' => (object)$partes], snap($db, (int)$id)));

    case 'escanear': // valida y registra una etiqueta; toda lectura queda en la tabla escaneos
      $id = (int)($in['verificacion_id'] ?? 0);
      $qr = trim(preg_replace('/\s+/', ' ', (string)($in['qr'] ?? '')));
      $p  = strtoupper(trim((string)($in['parte'] ?? '')));
      $pz = (int)($in['piezas'] ?? 0);
      if (!$id || $qr === '' || $pz <= 0) out(400, ['error' => 'Etiqueta incompleta.']);
      $db->beginTransaction();
      $s = $db->prepare('SELECT orden, estado FROM verificaciones WHERE id=?'); $s->execute([$id]);
      $v = $s->fetch(PDO::FETCH_ASSOC);
      if (!$v || $v['estado'] !== 'abierta') { $db->rollBack(); out(409, ['error' => 'La verificación no está abierta.']); }
      $res = 'OK'; $det = ''; $cant = 0; $acum = 0;
      $s = $db->prepare("SELECT v.orden FROM escaneos e JOIN verificaciones v ON v.id=e.verificacion_id WHERE e.qr=? AND e.resultado='OK' LIMIT 1");
      $s->execute([$qr]); $dup = $s->fetchColumn();
      $s = $db->prepare('SELECT cantidad FROM pedido_lineas WHERE orden=? AND parte=?'); $s->execute([$v['orden'], $p]);
      $cant = (int)$s->fetchColumn();
      $s = $db->prepare("SELECT COALESCE(SUM(piezas),0) FROM escaneos WHERE verificacion_id=? AND parte=? AND resultado='OK'");
      $s->execute([$id, $p]); $acum = (int)$s->fetchColumn();
      if ($dup !== false)            { $res = 'DUPLICADA'; $det = ($dup === $v['orden']) ? '' : $dup; }
      elseif ($cant === 0)           { $res = 'NO_PERTENECE'; }
      elseif ($acum + $pz > $cant)   { $res = 'EXCESO'; }
      $s = $db->prepare('INSERT INTO escaneos(verificacion_id,qr,parte,piezas,resultado) VALUES(?,?,?,?,?)');
      $s->execute([$id, $qr, $p, $pz, $res]);
      $db->commit();
      if ($res === 'OK') $acum += $pz;
      out(200, array_merge(['resultado' => $res, 'detalle' => $det, 'parte' => $p, 'piezas' => $pz, 'cantidad' => $cant, 'acumulado' => $acum], snap($db, $id)));

    case 'anular': // deshace la última etiqueta aceptada
      $id = (int)($in['verificacion_id'] ?? 0);
      $s = $db->prepare("SELECT id, parte, piezas FROM escaneos WHERE verificacion_id=? AND resultado='OK' ORDER BY id DESC LIMIT 1");
      $s->execute([$id]); $u = $s->fetch(PDO::FETCH_ASSOC);
      if (!$u) out(404, ['error' => 'No hay escaneos que anular.']);
      $db->prepare("UPDATE escaneos SET resultado='ANULADA' WHERE id=?")->execute([$u['id']]);
      out(200, array_merge(['parte' => $u['parte'], 'piezas' => (int)$u['piezas']], snap($db, $id)));

    case 'plan_importar': // manifiestos (MAN) y etiquetas KANBAN (KAN) ya interpretados en el navegador
      $db->beginTransaction();
      $p = $db->prepare('INSERT OR REPLACE INTO plan_pallets VALUES(?,?,?,?,?,?,?,?,?)');
      foreach (($in['man'] ?? []) as $r) { $c = $r['codigos'] ?? []; sort($c);
        $p->execute([$r['dock'], $r['ruta'], $r['salida'], $r['orden'], (int)$r['pallet'], implode(',', $c), $r['serie'] ?? '', $r['palcode'] ?? '', $r['mros'] ?? '']); }
      $k = $db->prepare('INSERT OR REPLACE INTO plan_kanban VALUES(?,?,?,?,?,?,?)');
      foreach (($in['kan'] ?? []) as $r)
        $k->execute([$r['dock'], $r['orden'], $r['codigo'], $r['parte'], $r['descripcion'] ?? '', (int)$r['cajas'], (int)$r['pzas']]);
      $o = $db->prepare('INSERT OR REPLACE INTO plan_orden VALUES(?,?,?,?,?)');
      foreach (($in['order']['cajas'] ?? []) as $k => $n) { [$ord, $cod] = explode('|', $k);
        $inf = $in['order']['info'][$cod] ?? [];
        $o->execute([$ord, $cod, $inf['parte'] ?? '', (int)($inf['pzas'] ?? 0), (int)$n]); }
      $db->commit();
      out(200, ['pallets' => (int)$db->query('SELECT COUNT(*) FROM plan_pallets')->fetchColumn(), 'kanban' => (int)$db->query('SELECT COUNT(*) FROM plan_kanban')->fetchColumn()]);

    case 'plan_embarques':
      out(200, $db->query('SELECT dock, ruta, salida, COUNT(*) AS pallets, COUNT(DISTINCT orden) AS ordenes FROM plan_pallets GROUP BY dock, ruta, salida ORDER BY salida, ruta')->fetchAll(PDO::FETCH_ASSOC));

    case 'plan_embarque':
      $q = [$in['dock'] ?? '', $in['ruta'] ?? '', $in['salida'] ?? ''];
      $s = $db->prepare('SELECT orden, pallet, codigos, palcode FROM plan_pallets WHERE dock=? AND ruta=? AND salida=? ORDER BY orden, codigos, pallet'); $s->execute($q);
      $pal = $s->fetchAll(PDO::FETCH_ASSOC);
      $s = $db->prepare('SELECT * FROM plan_kanban WHERE dock=? AND orden IN (SELECT orden FROM plan_pallets WHERE dock=? AND ruta=? AND salida=?)');
      $s->execute([$q[0], $q[0], $q[1], $q[2]]);
      out(200, ['pallets' => $pal, 'kanban' => $s->fetchAll(PDO::FETCH_ASSOC)]);

    case 'embarques_generar': // crea embarques con MAN + ORDER (obligatoria); la KAN solo verifica
      $RK = ['71309-AK020-00', '71309-AK030-00'];
      $g = $db->query('SELECT dock, ruta, salida FROM plan_pallets GROUP BY dock, ruta, salida')->fetchAll(PDO::FETCH_ASSOC);
      $creados = []; $actualizados = []; $omitidos = []; $rechazados = [];
      $existe = $db->prepare('SELECT estatus FROM embarques WHERE id=?');
      foreach ($g as $e) {
        $id = $e['ruta'] . $e['dock'] . str_replace('-', '', substr($e['salida'], 0, 10)) . str_replace(':', '', substr($e['salida'], 11, 5));
        if (strlen($e['salida']) < 16) { $rechazados[] = ['id' => $id, 'motivo' => 'El manifiesto no trae fecha/hora de salida.']; continue; }
        $s = $db->prepare('SELECT orden, serie, palcode, pallet, codigos, mros FROM plan_pallets WHERE dock=? AND ruta=? AND salida=? ORDER BY orden, pallet');
        $s->execute([$e['dock'], $e['ruta'], $e['salida']]);
        $por = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $o = &$por[$r['orden']];
          if (!$o) $o = ['serie' => substr($r['orden'], 0, 8), 'sufijo' => substr($r['orden'], 8, 2), 'pc' => [], 'sk' => [], 'cod' => [], 'mr' => []];
          foreach (explode(',', $r['codigos']) as $cc) if ($cc !== '') $o['cod'][$cc] = 1;
          if (trim((string)$r['mros']) !== '') $o['mr'][trim($r['mros'])] = 1; if ($r['palcode'] !== '') $o['pc'][$r['palcode']] = 1; $o['sk'][$r['pallet']] = 1; unset($o);
        }
        $falta = []; $lin = [];
        $q = $db->prepare('SELECT codigo, parte, pzas, cajas FROM plan_orden WHERE orden=?');
        $kq = $db->prepare('SELECT cajas FROM plan_kanban WHERE dock=? AND orden=? AND codigo=?');
        foreach ($por as $ord => $_) {
          $q->execute([$ord]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
          if (!$rows) { $falta[] = $ord; continue; }
          foreach ($rows as $r) { if (!isset($por[$ord]['cod'][$r['codigo']])) continue; /* la ORDER lista códigos que viajan en otro embarque */ $kq->execute([$e['dock'], $ord, $r['codigo']]); $k = $kq->fetchColumn();
            $lin[] = [$ord, $r['codigo'], $r['parte'], (int)$r['pzas'], in_array($r['parte'], $RK) ? 1 : 0, (int)$r['cajas'], $k === false ? null : (int)$k]; }
        }
        if ($falta) { $rechazados[] = ['id' => $id, 'motivo' => 'Falta la ORDER de: ' . implode(', ', $falta)]; continue; }
        $existe->execute([$id]); $est = $existe->fetchColumn();
        if ($est !== false && $est !== 'creado') { // ya en proceso: no se toca, solo se actualiza la comparación con KAN
          $db->prepare('UPDATE embarque_lineas SET kan=(SELECT cajas FROM plan_kanban k WHERE k.dock=? AND k.orden=embarque_lineas.orden AND k.codigo=embarque_lineas.codigo) WHERE embarque_id=?')->execute([$e['dock'], $id]);
          $um = $db->prepare('UPDATE embarque_pallets SET mros=? WHERE embarque_id=? AND orden=?'); foreach ($por as $ord => $o) $um->execute([implode(' / ', array_keys($o['mr'])), $id, $ord]);
          $omitidos[] = $id; continue; }
        $db->beginTransaction();
        $db->prepare('DELETE FROM embarque_pallets WHERE embarque_id=?')->execute([$id]);
        $db->prepare('DELETE FROM embarque_lineas WHERE embarque_id=?')->execute([$id]);
        $nc = 0; $nr = 0; foreach ($lin as $l) { if ($l[4]) $nr += $l[5]; else $nc += $l[5]; }
        $db->prepare("INSERT INTO embarques(id,ruta,dock,salida,estatus,pallets,cajas,racks) VALUES(?,?,?,?, 'creado',?,?,?)
          ON CONFLICT(id) DO UPDATE SET pallets=excluded.pallets,cajas=excluded.cajas,racks=excluded.racks,actualizado=datetime('now','localtime')")
          ->execute([$id, $e['ruta'], $e['dock'], $e['salida'], count($por), $nc, $nr]);
        $ip = $db->prepare('INSERT INTO embarque_pallets(embarque_id,orden,serie,sufijo,palcode,skids) VALUES(?,?,?,?,?,?)');
        foreach ($por as $ord => $o) $ip->execute([$id, $ord, $o['serie'], $o['sufijo'], implode('/', array_keys($o['pc'])), count($o['sk'])]);
        $um = $db->prepare('UPDATE embarque_pallets SET mros=? WHERE embarque_id=? AND orden=?'); foreach ($por as $ord => $o) $um->execute([implode(' / ', array_keys($o['mr'])), $id, $ord]);
        $il = $db->prepare('INSERT INTO embarque_lineas VALUES(?,?,?,?,?,?,?,?)');
        foreach ($lin as $l) $il->execute([$id, $l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $l[6]]);
        if ($est === false) $db->prepare("INSERT INTO embarque_log(embarque_id,estatus,nota) VALUES(?, 'creado', 'Creado desde MAN + ORDER')")->execute([$id]);
        $db->commit();
        if ($est === false) $creados[] = $id; else $actualizados[] = $id;
      }
      out(200, compact('creados', 'actualizados', 'omitidos', 'rechazados'));

    case 'embarques': // lista, opcionalmente filtrada por fecha de salida (YYYY-MM-DD)
      $f = $in['fecha'] ?? '';
      $s = $db->prepare("SELECT e.*, (SELECT COUNT(*) FROM embarque_lineas l WHERE l.embarque_id=e.id AND l.kan IS NOT NULL AND l.kan<>l.esperado) AS dif_kan,
        (SELECT COUNT(*) FROM embarque_lineas l WHERE l.embarque_id=e.id AND l.kan IS NOT NULL) AS con_kan,
        (SELECT COUNT(*) FROM embarque_lineas l WHERE l.embarque_id=e.id) AS total_lin,
        (SELECT COUNT(*) FROM embarque_pallets p WHERE p.embarque_id=e.id AND p.estado='completo') AS pal_ok FROM embarques e WHERE (?='' OR substr(e.salida,1,10)=?) ORDER BY e.salida, e.ruta");
      $s->execute([$f, $f]);
      out(200, ['embarques' => nums($s->fetchAll(PDO::FETCH_ASSOC), ['pallets', 'cajas', 'racks', 'dif_kan', 'con_kan', 'total_lin', 'pal_ok']), 'fechas' => $db->query('SELECT DISTINCT substr(salida,1,10) FROM embarques ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN)]);

    case 'embarque':
      $id = $in['id'] ?? '';
      $s = $db->prepare('SELECT * FROM embarques WHERE id=?'); $s->execute([$id]); $e = $s->fetch(PDO::FETCH_ASSOC);
      if (!$e) out(404, ['error' => 'Embarque no encontrado.']);
      $s = $db->prepare('SELECT * FROM embarque_pallets WHERE embarque_id=? ORDER BY orden'); $s->execute([$id]); $e['pallets_det'] = nums($s->fetchAll(PDO::FETCH_ASSOC), ['skids']);
      $s = $db->prepare('SELECT * FROM embarque_lineas WHERE embarque_id=? ORDER BY codigo, orden'); $s->execute([$id]); $e['lineas'] = nums($s->fetchAll(PDO::FETCH_ASSOC), ['pzas', 'es_rack', 'esperado', 'kan']);
      $s = $db->prepare('SELECT estatus, nota, hora FROM embarque_log WHERE embarque_id=? ORDER BY id'); $s->execute([$id]); $e['log'] = $s->fetchAll(PDO::FETCH_ASSOC);
      $e = nums([$e], ['pallets', 'cajas', 'racks'])[0];
      out(200, $e);

    case 'embarque_estatus':
      $id = $in['id'] ?? ''; $est = $in['estatus'] ?? '';
      $ord = ['creado' => 0, 'en_verificacion' => 1, 'preparado' => 2];
      if (!isset($ord[$est])) out(400, ['error' => 'Estatus inválido.']);
      $s = $db->prepare('SELECT estatus FROM embarques WHERE id=?'); $s->execute([$id]); $act = $s->fetchColumn();
      if ($act === false) out(404, ['error' => 'Embarque no encontrado.']);
      if ($act === $est) out(200, ['estatus' => $est]);
      $db->prepare("UPDATE embarques SET estatus=?, actualizado=datetime('now','localtime') WHERE id=?")->execute([$est, $id]);
      $db->prepare('INSERT INTO embarque_log(embarque_id,estatus,nota) VALUES(?,?,?)')->execute([$id, $est, substr($in['nota'] ?? 'Cambio manual', 0, 200)]);
      out(200, ['estatus' => $est]);

    // ====== Escaneo (Módulo C) ======
    case 'esc_abrir': // QR del Dock Audit: "DA" + ID del embarque
      $t = strtoupper(trim((string)($in['qr'] ?? '')));
      if (!preg_match('/^DA([A-Z]{4}\d{2}[A-Z]{3,5}\d{12})$/', $t, $m)) out(400, ['error' => 'Ese código no es el QR de un Dock Audit.']);
      $s = $db->prepare('SELECT estatus FROM embarques WHERE id=?'); $s->execute([$m[1]]); $est = $s->fetchColumn();
      if ($est === false) out(404, ['error' => 'El embarque ' . $m[1] . ' no existe en la base. Crea los embarques primero.']);
      if ($est === 'creado') { $db->prepare("UPDATE embarques SET estatus='en_verificacion',actualizado=datetime('now','localtime') WHERE id=?")->execute([$m[1]]);
        $db->prepare("INSERT INTO embarque_log(embarque_id,estatus,nota) VALUES(?, 'en_verificacion', 'Dock Audit escaneado')")->execute([$m[1]]); }
      out(200, esc_estado($db, $m[1]));

    case 'esc_estado':
      out(200, esc_estado($db, (string)($in['id'] ?? '')));

    case 'esc_manifiesto':
      $id = (string)($in['id'] ?? ''); $o = (string)($in['orden'] ?? ''); $razon = trim((string)($in['razon'] ?? ''));
      $st = esc_estado($db, $id); if ($st['embarque']['estatus'] === 'preparado') out(409, ['error' => 'Este embarque ya está preparado.']);
      $P = array_column($st['pallets'], null, 'orden');
      if (!isset($P[$o])) {
        $s = $db->prepare('SELECT embarque_id FROM embarque_pallets WHERE orden=? LIMIT 1'); $s->execute([$o]); $otro = $s->fetchColumn();
        out(409, ['error' => $otro ? "✖ La orden $o pertenece a otro embarque ($otro)." : "✖ La orden $o no está en este embarque."]);
      }
      $p = $P[$o];
      if ($p['estado'] === 'completo') out(409, ['error' => "El pallet {$p['sufijo']} ya está completo."]);
      $abierto = $st['abierto'];
      if ($abierto && $abierto !== $o) out(409, ['error' => "✖ Falta terminar el pallet {$P[$abierto]['sufijo']} (o saltarlo con razón).", 'abierto' => $abierto]);
      $saltar = [];
      if ($p['estado'] === 'pendiente') foreach ($st['pallets'] as $q) { if ($q['orden'] === $o) break; if ($q['estado'] === 'pendiente') $saltar[] = $q['orden']; }
      if ($saltar && $razon === '') out(409, ['error' => 'Fuera de secuencia', 'requiere_razon' => true, 'saltar' => array_map(function ($x) use ($P) { return $P[$x]['sufijo']; }, $saltar), 'esperado' => $P[$saltar[0]]['sufijo']]);
      $db->beginTransaction();
      foreach ($saltar as $x) { $db->prepare("UPDATE embarque_pallets SET estado='saltado',razon=? WHERE embarque_id=? AND orden=?")->execute([$razon, $id, $x]);
        $db->prepare('INSERT INTO embarque_saltos(embarque_id,orden,razon) VALUES(?,?,?)')->execute([$id, $x, $razon]); }
      $man = trim((string)($in['manifiesto'] ?? ''));
      $db->prepare("UPDATE embarque_pallets SET estado='en_proceso',inicio=COALESCE(inicio,datetime('now','localtime')),manifiestos=CASE WHEN manifiestos IS NULL OR manifiestos='' THEN ? WHEN instr(manifiestos,?)>0 THEN manifiestos ELSE manifiestos||char(10)||? END WHERE embarque_id=? AND orden=?")
         ->execute([$man, $man, $man, $id, $o]);
      $db->commit();
      out(200, esc_estado($db, $id));

    case 'esc_saltar':
      $id = (string)($in['id'] ?? ''); $o = (string)($in['orden'] ?? ''); $razon = trim((string)($in['razon'] ?? ''));
      if ($razon === '') out(400, ['error' => 'Indica la razón para saltar el pallet.']);
      $s = $db->prepare('SELECT estado FROM embarque_pallets WHERE embarque_id=? AND orden=?'); $s->execute([$id, $o]); $e = $s->fetchColumn();
      if ($e === false) out(404, ['error' => 'Pallet no encontrado.']);
      if ($e === 'completo') out(409, ['error' => 'Ese pallet ya está completo.']);
      $db->beginTransaction();
      $db->prepare("UPDATE embarque_pallets SET estado='saltado',razon=? WHERE embarque_id=? AND orden=?")->execute([$razon, $id, $o]);
      $db->prepare('INSERT INTO embarque_saltos(embarque_id,orden,razon) VALUES(?,?,?)')->execute([$id, $o, $razon]);
      $db->commit();
      out(200, esc_estado($db, $id));

    case 'esc_etiqueta':
      $id = (string)($in['id'] ?? ''); $qr = trim(preg_replace('/\s+/', ' ', (string)($in['qr'] ?? ''))); $parte = strtoupper(trim((string)($in['parte'] ?? ''))); $pz = (int)($in['piezas'] ?? 0);
      if ($qr === '' || $parte === '') out(400, ['error' => 'Etiqueta incompleta.']);
      $st = esc_estado($db, $id); $o = $st['abierto'];
      if (!$o) out(409, ['error' => 'Primero escanea el manifiesto del pallet.']);
      $pal = null; foreach ($st['pallets'] as $q) if ($q['orden'] === $o) $pal = $q;
      // el N.º de parte puede venir sin guiones y con prefijo (p. ej. 81515690403000 = 81 + 51569-04030-00)
      $pn = norm_parte($parte); $lin = null;
      foreach ($pal['lineas'] as $l) { $ln = norm_parte($l['parte']); if ($ln !== '' && ($pn === $ln || substr($pn, -strlen($ln)) === $ln)) $lin = $l; }
      $res = 'OK'; $det = ''; $aviso = '';
      $s = $db->prepare("SELECT orden FROM embarque_escaneos WHERE qr=? AND resultado='OK' LIMIT 1"); $s->execute([$qr]); $dup = $s->fetchColumn();
      if ($dup !== false) { $res = 'DUPLICADA'; $det = $dup === $o ? '' : $dup; }
      elseif (!$lin) $res = 'NO_PERTENECE';
      elseif ($lin['pzas'] && $pz && $pz !== (int)$lin['pzas']) { $res = 'PIEZAS_DISTINTAS'; $aviso = "La etiqueta dice $pz pzas y la ORDER $lin[pzas]"; }
      elseif ($lin['escaneado'] + 1 > $lin['esperado']) $res = 'EXCESO';
      $db->prepare('INSERT INTO embarque_escaneos(embarque_id,orden,qr,parte,codigo,piezas,resultado,aviso) VALUES(?,?,?,?,?,?,?,?)')
         ->execute([$id, $o, $qr, $lin['parte'] ?? $parte, $lin['codigo'] ?? null, $pz, $res, $aviso]);
      $st = esc_estado($db, $id);
      foreach ($st['pallets'] as $q) if ($q['orden'] === $o) $pal = $q;
      $palCompleto = false; $embCompleto = false;
      if ($res === 'OK' && array_sum(array_column($pal['lineas'], 'escaneado')) >= array_sum(array_column($pal['lineas'], 'esperado'))) {
        $db->prepare("UPDATE embarque_pallets SET estado='completo',fin=datetime('now','localtime') WHERE embarque_id=? AND orden=?")->execute([$id, $o]);
        $palCompleto = true; $st = esc_estado($db, $id);
        if (!array_filter($st['pallets'], function ($x) { return $x['estado'] !== 'completo'; })) {
          $db->prepare("UPDATE embarques SET estatus='preparado',actualizado=datetime('now','localtime') WHERE id=?")->execute([$id]);
          $db->prepare("INSERT INTO embarque_log(embarque_id,estatus,nota) VALUES(?, 'preparado', 'Todos los pallets completos por escaneo')")->execute([$id]);
          $embCompleto = true; $st = esc_estado($db, $id); }
      }
      out(200, array_merge($st, ['resultado' => $res, 'detalle' => $det, 'aviso' => $aviso, 'pzas_orden' => (int)($lin['pzas'] ?? 0), 'pzas_etiqueta' => $pz, 'parte' => $lin['parte'] ?? $parte, 'codigo' => $lin['codigo'] ?? '', 'pallet_completo' => $palCompleto, 'embarque_completo' => $embCompleto, 'pallet' => $pal['sufijo']]));

    case 'esc_anular':
      $id = (string)($in['id'] ?? ''); $st = esc_estado($db, $id); $o = $st['abierto'];
      if (!$o) out(409, ['error' => 'No hay un pallet abierto.']);
      $s = $db->prepare("SELECT id,parte FROM embarque_escaneos WHERE embarque_id=? AND orden=? AND resultado='OK' ORDER BY id DESC LIMIT 1"); $s->execute([$id, $o]); $u = $s->fetch(PDO::FETCH_ASSOC);
      if (!$u) out(404, ['error' => 'No hay escaneos que anular en este pallet.']);
      $db->prepare("UPDATE embarque_escaneos SET resultado='ANULADA' WHERE id=?")->execute([$u['id']]);
      out(200, array_merge(esc_estado($db, $id), ['parte' => $u['parte']]));

    case 'exportar': // CSV: tipo=resumen|lineas|escaneos|saltos; id=embarque o fecha=YYYY-MM-DD
      $tipo = $_GET['tipo'] ?? 'resumen'; $id = $_GET['id'] ?? ''; $f = $_GET['fecha'] ?? '';
      $w = $id !== '' ? 'e.id=?' : "(?='' OR substr(e.salida,1,10)=?)"; $par = [$id !== '' ? $id : $f];
      $q = [
        'resumen' => "SELECT e.id AS embarque, e.ruta, e.dock, e.salida, e.estatus AS estatus_embarque, p.serie, p.sufijo AS pallet, p.orden, p.estado AS estado_pallet, p.inicio, p.fin,
            (SELECT COALESCE(SUM(esperado),0) FROM embarque_lineas l WHERE l.embarque_id=p.embarque_id AND l.orden=p.orden) AS esperado,
            (SELECT COUNT(*) FROM embarque_escaneos x WHERE x.embarque_id=p.embarque_id AND x.orden=p.orden AND x.resultado='OK') AS escaneado,
            p.razon AS razon_salto, p.manifiestos FROM embarques e JOIN embarque_pallets p ON p.embarque_id=e.id WHERE $w ORDER BY e.salida, e.ruta, p.orden",
        'lineas' => "SELECT e.id AS embarque, e.ruta, e.dock, e.salida, substr(l.orden,9,2) AS pallet, l.orden, l.codigo, l.parte, l.pzas AS pzas_por_caja_o_rack, CASE l.es_rack WHEN 1 THEN 'RACK' ELSE 'CAJA' END AS unidad,
            l.esperado, (SELECT COUNT(*) FROM embarque_escaneos x WHERE x.embarque_id=l.embarque_id AND x.orden=l.orden AND x.codigo=l.codigo AND x.resultado='OK') AS escaneado, l.kan AS kan_etiquetas
            FROM embarques e JOIN embarque_lineas l ON l.embarque_id=e.id WHERE $w ORDER BY e.salida, e.ruta, l.orden, l.codigo",
        'escaneos' => "SELECT x.hora, e.id AS embarque, e.ruta, substr(x.orden,9,2) AS pallet, x.orden, x.codigo, x.parte, x.piezas, x.resultado, x.aviso, x.qr
            FROM embarques e JOIN embarque_escaneos x ON x.embarque_id=e.id WHERE $w ORDER BY x.id",
        'saltos' => "SELECT x.hora, e.id AS embarque, e.ruta, substr(x.orden,9,2) AS pallet, x.orden, x.razon FROM embarques e JOIN embarque_saltos x ON x.embarque_id=e.id WHERE $w ORDER BY x.id",
      ];
      if (!isset($q[$tipo])) out(400, ['error' => 'Tipo de exportación inválido.']);
      $s = $db->prepare($q[$tipo]); $s->execute($par); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
      $nom = 'dockaudit_' . $tipo . '_' . ($id !== '' ? $id : ($f !== '' ? $f : 'todo')) . '.csv';
      header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $nom . '"');
      echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
      $cab = $rows ? array_keys($rows[0]) : ['sin_datos']; fputcsv($o, $cab);
      foreach ($rows as $r) fputcsv($o, array_map(function ($v) { return is_string($v) ? str_replace(["\r", "\n"], ' ', $v) : $v; }, array_values($r)));
      fclose($o); exit;

    case 'historial': // bitácora de un embarque: escaneos (con rechazos), saltos y cambios de estatus
      $id = (string)($in['id'] ?? '');
      $s = $db->prepare("SELECT hora, substr(orden,9,2) AS pallet, codigo, parte, resultado, aviso FROM embarque_escaneos WHERE embarque_id=? ORDER BY id DESC LIMIT 300"); $s->execute([$id]); $esc = $s->fetchAll(PDO::FETCH_ASSOC);
      $s = $db->prepare("SELECT hora, substr(orden,9,2) AS pallet, razon FROM embarque_saltos WHERE embarque_id=? ORDER BY id"); $s->execute([$id]); $sal = $s->fetchAll(PDO::FETCH_ASSOC);
      $s = $db->prepare("SELECT resultado, COUNT(*) FROM embarque_escaneos WHERE embarque_id=? GROUP BY resultado"); $s->execute([$id]);
      out(200, ['escaneos' => $esc, 'saltos' => $sal, 'resumen' => $s->fetchAll(PDO::FETCH_KEY_PAIR)]);

    case 'auditoria': // datos recabados durante el escaneo, para el Dock Audit auditado
      $id = (string)($in['id'] ?? '');
      $s = $db->prepare("SELECT orden, razon, hora FROM embarque_saltos WHERE embarque_id=? ORDER BY id"); $s->execute([$id]); $sal = $s->fetchAll(PDO::FETCH_ASSOC);
      $s = $db->prepare("SELECT orden, codigo, parte, resultado, MAX(aviso) AS aviso, COUNT(*) AS n FROM embarque_escaneos WHERE embarque_id=? AND resultado NOT IN ('OK','ANULADA') GROUP BY orden, codigo, parte, resultado ORDER BY MIN(id)"); $s->execute([$id]); $rec = $s->fetchAll(PDO::FETCH_ASSOC);
      $s = $db->prepare("SELECT resultado, COUNT(*) FROM embarque_escaneos WHERE embarque_id=? GROUP BY resultado"); $s->execute([$id]); $res = $s->fetchAll(PDO::FETCH_KEY_PAIR);
      $g = function($est, $ord) use ($db, $id) { $s = $db->prepare("SELECT hora FROM embarque_log WHERE embarque_id=? AND estatus=? ORDER BY id $ord LIMIT 1"); $s->execute([$id, $est]); return $s->fetchColumn() ?: ''; };
      out(200, ['saltos' => $sal, 'rechazos' => $rec, 'resumen' => $res, 'inicio' => $g('en_verificacion', 'ASC'), 'fin' => $g('preparado', 'DESC')]);

    default:
      out(400, ['error' => 'Acción desconocida.']);
  }
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  out(500, ['error' => 'Error de base de datos: ' . $e->getMessage()]);
}
