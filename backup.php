<?php
/**
 * BACKUP COMPLETO - Crea backup di tutta l'applicazione ristorantemimmo1
 */

$base_path = '/root/push/ristorantemimmo1';
$backup_dir = '/root/backups/ristorantemimmo1';

if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$action = $_GET['action'] ?? '';

if ($action === 'create') {
    $timestamp = date('Y-m-d_H-i-s');
    $backup_name = "backup_ristorantemimmo1_{$timestamp}.tar.gz";
    $backup_path = $backup_dir . '/' . $backup_name;
    
    $command = "tar -czf " . escapeshellarg($backup_path) . " -C /root/push ristorantemimmo1 2>&1";
    exec($command, $output, $return_code);
    
    if ($return_code === 0 && file_exists($backup_path)) {
        $size = filesize($backup_path);
        $size_mb = round($size / 1024 / 1024, 2);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "Backup creato con successo!",
            'file' => $backup_name,
            'size' => $size_mb . ' MB'
        ]);
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore creazione backup']);
    }
    exit;
}

if ($action === 'list') {
    $backups = [];
    
    if (is_dir($backup_dir)) {
        $files = scandir($backup_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && strpos($file, 'backup_') === 0) {
                $filepath = $backup_dir . '/' . $file;
                $backups[] = [
                    'name' => $file,
                    'size_mb' => round(filesize($filepath) / 1024 / 1024, 2),
                    'date' => date('d/m/Y H:i:s', filemtime($filepath)),
                    'timestamp' => filemtime($filepath)
                ];
            }
        }
    }
    
    usort($backups, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'backups' => $backups]);
    exit;
}

if ($action === 'download' && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filepath = $backup_dir . '/' . $filename;
    
    if (file_exists($filepath)) {
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

if ($action === 'delete' && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filepath = $backup_dir . '/' . $filename;
    
    if (file_exists($filepath) && unlink($filepath)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Backup eliminato']);
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore eliminazione']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Backup Completo</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;padding:20px}.container{max-width:1000px;margin:0 auto;background:white;border-radius:15px;padding:30px;box-shadow:0 10px 30px rgba(0,0,0,0.2)}h1{color:#333;margin-bottom:10px;font-size:28px}.subtitle{color:#666;margin-bottom:30px}.alert{padding:15px;border-radius:8px;margin-bottom:20px}.alert-info{background:#d1ecf1;color:#0c5460;border-left:4px solid #17a2b8}.alert-success{background:#d4edda;color:#155724;border-left:4px solid #28a745}.alert-warning{background:#fff3cd;color:#856404;border-left:4px solid #ffc107}.alert-danger{background:#f8d7da;color:#721c24;border-left:4px solid #dc3545}.btn{padding:12px 24px;border-radius:8px;border:none;font-size:16px;font-weight:600;cursor:pointer;transition:all 0.3s;text-decoration:none;display:inline-block}.btn-primary{background:#667eea;color:white}.btn-primary:hover{background:#5568d3;transform:translateY(-2px)}.btn-success{background:#28a745;color:white}.btn-warning{background:#ffc107;color:#333}.btn-danger{background:#dc3545;color:white}.btn-secondary{background:#6c757d;color:white}.btn-sm{padding:8px 16px;font-size:14px}.backup-list{margin-top:30px}.backup-item{background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:15px;display:flex;justify-content:space-between;align-items:center}.backup-info h3{font-size:16px;color:#333;margin-bottom:5px}.backup-meta{font-size:14px;color:#666}.backup-actions{display:flex;gap:10px}.loading{text-align:center;padding:20px;color:#666}.back-btn{display:inline-block;margin-top:20px;padding:10px 20px;background:#6c757d;color:white;text-decoration:none;border-radius:6px;font-weight:600}.back-btn:hover{background:#5a6268}</style>
</head><body>
<div class="container">
<h1>📦 Backup Completo Applicazione</h1>
<p class="subtitle">Crea, scarica e ripristina backup completi</p>
<div class="alert alert-warning"><strong>⚠️ Importante:</strong> Il backup include TUTTI i file dell'applicazione</div>
<div id="message" style="display:none;"></div>
<button onclick="createBackup()" class="btn btn-primary">📦 Crea Backup Completo Adesso</button>
<div class="backup-list"><h2>📋 Backup Disponibili</h2><div id="backupsList" class="loading">Caricamento...</div></div>
<a href="dashboard.php" class="back-btn">← Torna al Dashboard</a>
</div>
<script>
function showMessage(message,type='info'){const msgDiv=document.getElementById('message');msgDiv.className='alert alert-'+type;msgDiv.innerHTML=message;msgDiv.style.display='block';setTimeout(()=>{msgDiv.style.display='none'},5000)}
function createBackup(){if(!confirm('Vuoi creare un backup completo adesso?'))return;showMessage('⏳ Creazione backup in corso...','info');fetch('backup.php?action=create').then(response=>response.json()).then(data=>{if(data.success){showMessage('✅ '+data.message+' ('+data.size+')','success');loadBackups()}else{showMessage('❌ '+(data.error||'Errore'),'danger')}}).catch(error=>{showMessage('❌ Errore: '+error.message,'danger')})}
function loadBackups(){const listDiv=document.getElementById('backupsList');listDiv.innerHTML='<div class="loading">Caricamento...</div>';fetch('backup.php?action=list').then(response=>response.json()).then(data=>{if(data.success&&data.backups.length>0){let html='';data.backups.forEach(backup=>{html+=`<div class="backup-item"><div class="backup-info"><h3>📦 ${backup.name}</h3><div class="backup-meta">🕒 ${backup.date} • 💾 ${backup.size_mb} MB</div></div><div class="backup-actions"><a href="backup.php?action=download&file=${encodeURIComponent(backup.name)}" class="btn btn-success btn-sm">⬇️ Scarica</a><button onclick="deleteBackup('${backup.name}')" class="btn btn-danger btn-sm">🗑️ Elimina</button></div></div>`});listDiv.innerHTML=html}else{listDiv.innerHTML='<div class="alert alert-info">Nessun backup disponibile. Crea il primo backup!</div>'}}).catch(error=>{listDiv.innerHTML='<div class="alert alert-danger">Errore caricamento</div>'})}
function deleteBackup(filename){if(!confirm('Vuoi eliminare questo backup?\n\n'+filename))return;fetch('backup.php?action=delete&file='+encodeURIComponent(filename)).then(response=>response.json()).then(data=>{if(data.success){showMessage('✅ Backup eliminato','success');loadBackups()}else{showMessage('❌ Errore','danger')}})}
loadBackups();
</script>
</body></html>
