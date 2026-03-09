<?php
$base_path = '/root/push/ristorantemimmo1/PRENOTAZIONI';
$backup_dir = '/root/backups/ristorantemimmo1/singoli';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

$files_protetti = [
    'calendar-ristorante-001.html'   => 'Calendario Ristorante',
    'agenda-cliente-001.html'         => 'Agenda Cliente (PWA)',
    'admin-promo-ristorante-001.html' => 'Admin Promozioni',
    'api.php'                         => 'API Principale',
    'send_fcm_native.php'             => 'Invio Push FCM',
    'trace_reply.php'                 => 'Risposta Push',
    'send_push_appointment.php'       => 'Push Appuntamento',
    'activation.php'                  => 'Attivazione Codici',
    'register_push_native.php'        => 'Registrazione Push',
    'check_promo_push.php'            => 'Check Promo Push',
    'app-core.js'                     => 'App Core JS',
    'agenda-cliente-001/app-core.js'      => 'App Core JS',
    'agenda-cliente-001/version-check.js' => 'Version Check',
];

$action = $_GET['action'] ?? '';

if ($action === 'backup' && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    if (!array_key_exists($filename, $files_protetti)) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'File non autorizzato']); exit; }
    $source = $base_path . '/' . $filename;
    if (!file_exists($source)) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'File non trovato']); exit; }
    $fileKey = str_replace('/', '_', pathinfo($filename, PATHINFO_FILENAME));
    $stamp = date('Ymd_His') . '_' . substr((string)microtime(true), -3);
    $backup_name = $stamp . '__' . $fileKey . '.bak';
    if (copy($source, $backup_dir.'/'.$backup_name)) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>'Backup creato: '.$backup_name]); }
    else { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Errore copia']); }
    exit;
}

if ($action === 'list' && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $fileKey = str_replace('/', '_', pathinfo($filename, PATHINFO_FILENAME));
    $suffix = '__' . $fileKey . '.bak';
    $versioni = [];
    if (is_dir($backup_dir)) foreach (scandir($backup_dir) as $f) {
        if (substr($f, -strlen($suffix)) === $suffix) {
            $fp=$backup_dir.'/'.$f;
            $versioni[]=['name'=>$f,'date'=>date('d/m/Y H:i:s',filemtime($fp)),'size_kb'=>round(filesize($fp)/1024,1),'timestamp'=>filemtime($fp)];
        }
    }
    usort($versioni,function($a,$b){return $b['timestamp']-$a['timestamp'];});
    header('Content-Type: application/json'); echo json_encode(['success'=>true,'versioni'=>$versioni]); exit;
}

if ($action === 'ripristina' && isset($_GET['backup'],$_GET['file'])) {
    $filename = basename($_GET['file']);
    $backup_name = basename($_GET['backup']);
    if (!array_key_exists($filename,$files_protetti)) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Non autorizzato']); exit; }
    $source = $backup_dir.'/'.$backup_name;
    $dest = $base_path.'/'.$filename;
    if (!file_exists($source)) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Backup non trovato']); exit; }
    if (copy($source,$dest)) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>'File ripristinato!']); }
    else { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Errore ripristino']); }
    exit;
}

if ($action === 'delete' && isset($_GET['backup'])) {
    $fp = $backup_dir.'/'.basename($_GET['backup']);
    if (file_exists($fp) && unlink($fp)) { header('Content-Type: application/json'); echo json_encode(['success'=>true]); }
    else { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Errore']); }
    exit;
}

if ($action === 'download' && isset($_GET['backup'])) {
    $backup_name = basename($_GET['backup']);
    $fp = $backup_dir . '/' . $backup_name;
    if (file_exists($fp)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup_name . '"');
        header('Content-Length: ' . filesize($fp));
        readfile($fp);
    }
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Backup Singolo File</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;padding:20px}.container{max-width:1100px;margin:0 auto;background:white;border-radius:15px;padding:30px;box-shadow:0 10px 30px rgba(0,0,0,0.2)}h1{color:#333;margin-bottom:8px;font-size:26px}.subtitle{color:#666;margin-bottom:25px}.alert{padding:12px 16px;border-radius:8px;margin-bottom:15px;font-size:14px}.alert-info{background:#d1ecf1;color:#0c5460;border-left:4px solid #17a2b8}.alert-success{background:#d4edda;color:#155724;border-left:4px solid #28a745}.alert-danger{background:#f8d7da;color:#721c24;border-left:4px solid #dc3545}.layout{display:flex;flex-direction:column;gap:20px;margin-top:20px}.file-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}.right-panel{padding:2.65mm;border-left:0.26mm solid #eee;background:#fff;max-height:calc(100vh - 220px);overflow-y:auto}.right-panel-title{font-size:14px;font-weight:700;color:#333;margin-bottom:8px}.right-panel-body{font-size:12px;color:#777}.ver-row{display:flex;flex-direction:column;align-items:flex-start;gap:12px;padding:2.12mm 2.65mm;margin-bottom:2.12mm;border:0.26mm solid #eee;border-radius:2.65mm}.ver-text{width:100%;min-width:0;display:flex;flex-direction:column}.ver-name{font-size:13px;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ver-meta{font-size:11px;line-height:1.1;opacity:.8;margin-top:4px;white-space:nowrap}.ver-actions{width:100%;display:flex;gap:8px;justify-content:flex-end}.btn-mini{height:7.94mm;padding:1.59mm 2.65mm;font-size:3.17mm;border-radius:2.12mm}.file-card{background:#f8f9fa;border-radius:10px;padding:18px;border:2px solid #e9ecef}.file-card:hover{border-color:#667eea}.file-fname{font-size:12px;color:#888;font-family:monospace;margin-bottom:4px}.file-label{font-size:16px;font-weight:700;color:#333;margin-bottom:4px}.file-meta{font-size:12px;color:#aaa;margin-bottom:12px}.btn{padding:8px 14px;border-radius:6px;border:none;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}.btn-primary{background:#667eea;color:white}.btn-warning{background:#ffc107;color:#333}.btn-success{background:#28a745;color:white}.btn-danger{background:#dc3545;color:white}.btn-sm{padding:4px 10px;font-size:12px}.back-btn{display:inline-block;margin-top:25px;padding:10px 20px;background:#6c757d;color:white;text-decoration:none;border-radius:6px;font-weight:600;margin-right:10px}#globalMsg{display:none;margin-bottom:15px}
</style>
</head><body>
<div class="container">
<h1>💾 Backup Singolo File</h1>
<p class="subtitle">Salva una copia prima che Codex modifichi il file. Se rompe tutto → ripristini in 1 click.</p>
<div id="globalMsg"></div>
<div class="layout"><div class="file-grid">
<?php foreach ($files_protetti as $filename => $label):
    $fp = $base_path.'/'.$filename;
    $exists = file_exists($fp);
    if (!$exists) continue;
    $modified = $exists ? date('d/m/Y H:i',filemtime($fp)) : 'non trovato';
    $size = $exists ? round(filesize($fp)/1024,1).' KB' : '-';
    $id = md5($filename);
?>
<div class="file-card">
    <div class="file-fname"><?=htmlspecialchars($filename)?></div>
    <div class="file-label"><?=htmlspecialchars($label)?></div>
    <div class="file-meta">📅 <?=$modified?> &nbsp;•&nbsp; 💾 <?=$size?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if($exists):?><button onclick="creaBackup('<?=htmlspecialchars($filename)?>', this)" class="btn btn-primary">💾 Backup Ora</button><?php endif;?>
        <button onclick="toggleVersioni('<?=htmlspecialchars($filename)?>','<?=$id?>')" class="btn btn-warning">📋 Versioni</button>
    </div>
    
</div>
<?php endforeach;?>
</div><div class="right-panel" id="rightPanel"><div class="right-panel-title">Versioni</div><div class="right-panel-body" id="rightPanelBody">Seleziona un file per vedere le versioni.</div></div></div>
<a href="dashboard.php" class="back-btn">← Dashboard</a>
<a href="backup.php" class="back-btn">📦 Backup Completo</a>
</div>
<script>
let backupInCorso = false;
function showMsg(msg,type){const d=document.getElementById('globalMsg');d.className='alert alert-'+type;d.innerHTML=msg;d.style.display='block'}
function setRightPanel(title,body){const t=document.getElementById('rightPanel');const b=document.getElementById('rightPanelBody');const head=t.querySelector('.right-panel-title');head.textContent=title;b.innerHTML=body}
function renderVersioni(filename){setRightPanel('Versioni · '+filename,'<div style="font-size:12px;color:#999;padding:5px">Caricamento...</div>');fetch('backup-singolo.php?action=list&file='+encodeURIComponent(filename)).then(r=>r.json()).then(data=>{if(!data.versioni||data.versioni.length===0){setRightPanel('Versioni · '+filename,'<div style="font-size:13px;color:#aaa;padding:5px">Nessuna versione salvata.</div>');return}let html='';data.versioni.forEach(v=>{html+=`<div class="ver-row"><div class="ver-text"><div class="ver-name">${v.name}</div><div class="ver-meta">${v.date} · ${v.size_kb} KB</div></div><div class="ver-actions"><a href="backup-singolo.php?action=download&backup=${encodeURIComponent(v.name)}" class="btn btn-primary btn-mini">⬇️ Scarica</a><button onclick="ripristina('${filename}','${v.name}')" class="btn btn-success btn-mini">↩️ Ripristina</button><button onclick="eliminaVer('${v.name}','${filename}', this)" class="btn btn-danger btn-mini">️</button></div></div>`});setRightPanel('Versioni · '+filename,html)})}
function creaBackup(filename,btn){if(btn){btn.disabled=true;btn.innerText='⏳...'}showMsg('⏳ Salvataggio...','info');fetch('backup-singolo.php?action=backup&file='+encodeURIComponent(filename)).then(r=>r.json()).then(d=>{if(btn){btn.disabled=false;btn.innerText=' Backup Ora'}if(d.success){showMsg('✅ '+d.message+' — Backup salvato con successo!','success');renderVersioni(filename)}else showMsg('❌ '+d.error,'danger')}).catch(()=>{backupInCorso=false;showMsg('❌ Errore di rete','danger')})}
function toggleVersioni(filename,id){renderVersioni(filename)}
function ripristina(filename,backup){if(!confirm('Ripristinare questa versione?\n\nIl file attuale verrà sovrascritto!'))return;fetch(`backup-singolo.php?action=ripristina&file=${encodeURIComponent(filename)}&backup=${encodeURIComponent(backup)}`).then(r=>r.json()).then(d=>{if(d.success)showMsg('✅ '+d.message,'success');else showMsg('❌ '+d.error,'danger')})}
function eliminaVer(backup,filename,btn){btn.disabled=true;fetch('backup-singolo.php?action=delete&backup='+encodeURIComponent(backup)).then(r=>r.json()).then(d=>{if(d.success){showMsg('✅ Eliminata','success');renderVersioni(filename)}})}
</script>
</body></html>
