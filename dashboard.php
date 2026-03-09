<?php
$attivita = $_GET['attivita'] ?? '';
$base_path = '/root/push/' . $attivita;
/**
 * PANNELLO MASTER - DIAGNOSTICA E CONTROLLO
 * Applicazione: $attivita
 * Dominio: puschpromozioni.it
 * Creato: 13 Febbraio 2026
 */

// Configurazione percorsi
$prenotazioni_path = $base_path . '/PRENOTAZIONI';
$config_path = $base_path . '/config';

// ========================================
// API ENDPOINTS (AJAX)
// ========================================

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_free_code':
            $codici_path = $prenotazioni_path . '/codici_attivazione.json';
            if (file_exists($codici_path)) {
                $codici_raw = json_decode(file_get_contents($codici_path), true);
                if (!is_array($codici_raw)) {
                    $codici_raw = [];
                }
                $available = [];
                foreach ($codici_raw as $cod => $info) {
                    if (!empty($info['attivo'])) {
                        $available[] = $cod;
                    }
                }
                if (!empty($available)) {
                    echo json_encode(['success' => true, 'codes' => $available, 'count' => count($available)]);
                    exit;
                }
                echo json_encode(['success' => false, 'error' => 'Tutti i codici usati']);
            } else {
                echo json_encode(['success' => false, 'error' => 'File non trovato']);
            }
            exit;
            
        case 'get_codici':
            $codici_path = $prenotazioni_path . '/codici_attivazione.json';
            if (file_exists($codici_path)) {
                $codici_raw = json_decode(file_get_contents($codici_path), true);
                if (!is_array($codici_raw)) {
                    $codici_raw = [];
                }

                $registered_codes = [];

                $codici = [];
                foreach ($codici_raw as $cod => $info) {
                    $is_attivo = ($info["attivo"] ?? false) ? true : false;
                    $telefono = isset($info["telefono"]) ? (string)$info["telefono"] : "";
                    $data_registrazione = isset($info["dataRegistrazione"]) ? (string)$info["dataRegistrazione"] : "";
                    $codici[] = [
                        "codice" => $cod,
                        "usato" => !$is_attivo,
                        "data_uso" => null,
                        "telefono" => $telefono,
                        "data_registrazione" => $data_registrazione,
                        "attivo" => $is_attivo
                    ];
                }
                echo json_encode(['success' => true, 'codici' => $codici]);
            } else {
                echo json_encode(['success' => false, 'error' => 'File non trovato']);
            }
            exit;
            
        case 'view_file':
            $filename = $_GET['file'] ?? '';
            $filepath = $prenotazioni_path . '/' . basename($filename);
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
            }
            exit;

        case 'add_code':
            $new_code = $_GET['code'] ?? '';
            $codici_path = $prenotazioni_path . '/codici_attivazione.json';
            if (file_exists($codici_path) && !empty($new_code)) {
                $codici = json_decode(file_get_contents($codici_path), true);
                $codici[$new_code] = ['attivo' => true];
                file_put_contents($codici_path, json_encode($codici, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Errore salvataggio']);
            }
            exit;

        case 'clear_log':
            $logname = basename($_GET['file'] ?? '');
            $logpath = $prenotazioni_path . '/' . $logname;
            if (file_exists($logpath)) {
                file_put_contents($logpath, '');
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'File non trovato']);
            }
            exit;

        case 'download_log':
            $logname = basename($_GET['file'] ?? '');
            $logpath = $prenotazioni_path . '/' . $logname;
            if (file_exists($logpath)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="' . $logname . '"');
                readfile($logpath);
            }
            exit;
    }
}
// Header HTML
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pannello Master - Ristorante da Mimmo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .header h1 {
            color: #333;
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 16px;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .card h2 {
            font-size: 20px;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .status-ok {
            background: #d4edda;
            color: #155724;
        }
        
        .status-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .file-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #667eea;
        }
        
        .file-item h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 8px;
        }
        
        .file-info {
            font-size: 13px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .file-actions {
            margin-top: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #333;
        }
        
        .info-value {
            color: #666;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .test-result {
            margin-top: 10px;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            display: none;
        }
        
        .test-result.show {
            display: block;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        td {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .icon {
            font-size: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <h1>🍝 Pannello Master - Ristorante da Mimmo</h1>
            <p>Diagnostica e controllo completo dell'applicazione</p>
        </div>

        <?php
        // ========================================
        // SEZIONE 1: STATUS SISTEMA
        // ========================================
        
        // Check Apache
        $apache_running = shell_exec('systemctl is-active apache2') === "active\n";
        
        // Check PHP version
        $php_version = phpversion();
        
        // Check file critici
        $files_to_check = [
            'agenda-cliente-001.html' => $prenotazioni_path . '/agenda-cliente-001.html',
            'calendar-ristorante-001.html' => $prenotazioni_path . '/calendar-ristorante-001.html',
            'admin-promo-ristorante-001.html' => $prenotazioni_path . '/admin-promo-ristorante-001.html',
            'codici_attivazione.json' => $prenotazioni_path . '/codici_attivazione.json'
        ];
        
        $files_ok = 0;
        $files_missing = [];
        foreach ($files_to_check as $name => $path) {
            if (file_exists($path)) {
                $files_ok++;
            } else {
                $files_missing[] = $name;
            }
        }
        
        // Determina status generale
        $all_ok = $apache_running && $files_ok === count($files_to_check);
        ?>

        <!-- STATUS GENERALE -->
        <div class="card">
            <h2><span class="icon">🔍</span> Status Sistema</h2>
            
            <?php if ($all_ok): ?>
                <div class="alert alert-success">
                    ✅ Tutti i controlli sono passati! Sistema operativo.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    ⚠️ Alcuni controlli hanno rilevato problemi. Verifica sotto.
                </div>
            <?php endif; ?>
            
            <div class="info-row">
                <span class="info-label">Apache:</span>
                <span class="status-badge <?php echo $apache_running ? 'status-ok' : 'status-error'; ?>">
                    <?php echo $apache_running ? '✓ Running' : '✗ Stopped'; ?>
                </span>
            </div>
            
            <div class="info-row">
                <span class="info-label">PHP Version:</span>
                <span class="info-value"><?php echo $php_version; ?></span>
            </div>
            
            <div class="info-row">
                <span class="info-label">File Critici:</span>
                <span class="status-badge <?php echo $files_ok === count($files_to_check) ? 'status-ok' : 'status-warning'; ?>">
                    <?php echo $files_ok . '/' . count($files_to_check); ?>
                </span>
            </div>
            
            <?php if (!empty($files_missing)): ?>
                <div class="alert alert-warning" style="margin-top: 10px;">
                    <strong>File mancanti:</strong><br>
                    <?php echo implode(', ', $files_missing); ?>
                </div>
            <?php endif; ?>
            
        </div>

        <?php
        // ========================================
        // SEZIONE 2: I 3 FILE PRINCIPALI
        // ========================================
        
        $main_files = [
            [
                'name' => 'Agenda Cliente (PWA)',
                'icon' => '📱',
                'file' => 'agenda-cliente-001.html',
                'path' => $prenotazioni_path . '/agenda-cliente-001.html',
                'url' => 'https://puschpromozioni.it/' . $attivita . '/PRENOTAZIONI/agenda-cliente-001.html',
                'description' => 'Applicazione PWA per i clienti del ristorante'
            ],
            [
                'name' => 'Calendario Ristorante',
                'icon' => '📅',
                'file' => 'calendar-ristorante-001.html',
                'path' => $prenotazioni_path . '/calendar-ristorante-001.html',
                'url' => 'https://puschpromozioni.it/' . $attivita . '/PRENOTAZIONI/calendar-ristorante-001.html',
                'description' => 'Calendario prenotazioni per il ristorante'
            ],
            [
                'name' => 'Admin Promozioni',
                'icon' => '⚙️',
                'file' => 'admin-promo-ristorante-001.html',
                'path' => $prenotazioni_path . '/admin-promo-ristorante-001.html',
                'url' => 'https://puschpromozioni.it/' . $attivita . '/PRENOTAZIONI/admin-promo-ristorante-001.html',
                'description' => 'Pannello per inviare notifiche promozionali (si apre dal calendario)'
            ]
        ];
        ?>

        <div class="card">
            <h2><span class="icon">📂</span> File Principali dell'Applicazione</h2>
            
            <?php foreach ($main_files as $file): ?>
                <div class="file-item">
                    <h3><?php echo $file['icon']; ?> <?php echo $file['name']; ?></h3>
                    <div class="file-info">📄 File: <code><?php echo $file['file']; ?></code></div>
                    
                    <?php if (file_exists($file['path'])): ?>
                        <?php
                        $size = filesize($file['path']);
                        $size_kb = round($size / 1024, 2);
                        $modified = date('d/m/Y H:i:s', filemtime($file['path']));
                        $perms = substr(sprintf('%o', fileperms($file['path'])), -4);
                        ?>
                        <div class="file-info">📊 Dimensione: <?php echo $size_kb; ?> KB</div>
                        <div class="file-info">🕒 Modificato: <?php echo $modified; ?></div>
                        <div class="file-info">🔒 Permessi: <?php echo $perms; ?></div>
                        <div class="file-info" style="color: #666; font-style: italic;"><?php echo $file['description']; ?></div>
                        
                        <div class="file-actions">
                            <a href="<?php echo $file['url']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                🌐 Apri in Browser
                            </a>
                            <button onclick="viewFileContent('<?php echo $file['file']; ?>')" class="btn btn-warning btn-sm">
                                👁️ Vedi Primi 20 Righe
                            </button>
                        </div>
                        
                        <div id="content-<?php echo $file['file']; ?>" class="test-result" style="background: #f8f9fa;"></div>
                    <?php else: ?>
                        <span class="status-badge status-error">✗ File non trovato</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
        // ========================================
        // SEZIONE 3: TEST RAPIDI
        // ========================================
        ?>

        <div class="grid">
            <!-- CODICI ATTIVAZIONE -->
            <div class="card">
                <h2><span class="icon">🎟️</span> Promozioni</h2>
                
                <?php
$promo_db = $base_path . '/promozioni-toilet-001.db';
$totali = 0;
$attive = 0;
$libere = 0;
if (file_exists($promo_db)):
    try {
        $db = new SQLite3($promo_db);
        $totali = (int)$db->querySingle('SELECT COUNT(*) FROM promozioni');
        $attive = (int)$db->querySingle('SELECT COUNT(*) FROM promozioni WHERE attivo=1');
        $libere = $totali - $attive;
        $db->close();
    } catch (Exception $e) {
        echo '<div class="alert alert-warning">Errore lettura database promozioni: ' . $e->getMessage() . '</div>';
    }
?>
    <div class="info-row">
        <span class="info-label">Promozioni Totali:</span>
        <span class="info-value"><?php echo $totali; ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Promozioni Attive:</span>
        <span class="status-badge status-warning"><?php echo $attive; ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Promozioni Libere:</span>
        <span class="status-badge status-ok"><?php echo $libere; ?></span>
    </div>
    <button onclick="loadPromozioniDashboard()" class="btn btn-warning" style="margin-top: 15px;">
        ?? Aggiorna Promozioni
    </button>
    <div id="promozioni-list" class="test-result"></div>
<?php else: ?>
    <div class="alert alert-warning">Database promozioni non trovato</div>
<?php endif; ?>
            </div>
        </div>

        <?php
        // ========================================
        // SEZIONE 4: AZIONI RAPIDE
        // ========================================
        ?>

        <div class="card">
            <h2><span class="icon">⚡</span> Azioni Rapide</h2>
            
            <div class="grid">
                <div>
                    <h3 style="font-size: 16px; margin-bottom: 10px;">🔔 Notifiche Push</h3>
                                        <div style="margin-bottom: 10px;">
                        <input id="push-title" type="text" placeholder="Titolo push" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 8px;">
                        <textarea id="push-body" rows="3" placeholder="Messaggio push" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px;"></textarea>
                    </div>
                    <button onclick="sendPushTest()" class="btn btn-success">
                        ?? Invia Push Test
                    </button>
                    <p style="font-size: 13px; color: #666; margin-top: 10px;">
                        Invia una notifica di test a tutti i dispositivi registrati
                    </p>                    <div id="push-test-result" class="test-result"></div>

                </div>
                
                <div>
                    <h3 style="font-size: 16px; margin-bottom: 10px;">🎟️ Nuovo Codice</h3>
                    <button onclick="generateNewCode()" class="btn btn-primary">
                        ➕ Genera Codice Attivazione
                    </button>
                    <button onclick="viewCodici()" class="btn btn-success" style="margin-top: 10px;">
                        📋 Vedi Codici
                    </button>
                    <p style="font-size: 13px; color: #666; margin-top: 10px;">
                        Genera codice random e lo aggiunge al file codici_attivazione.json
                    </p>
                    <div id="codici-list" class="test-result"></div>
                </div>
                
                <div>
                    <h3 style="font-size: 16px; margin-bottom: 10px;">💾 Backup</h3>
                    <button onclick="window.location.href='backup.php'" class="btn btn-warning">
                        📦 Backup Completo Applicazione
                    </button>
                    <button onclick="window.location.href='backup-singolo.php'" class="btn btn-info" style="margin-top: 8px; display: block;">
                        Backup Singolo File
                    </button>
                    <p style="font-size: 13px; color: #666; margin-top: 10px;">
                        Crea backup di subscriptions e codici
                    </p>
                </div>

                <div>
                    <h3 style="font-size: 16px; margin-bottom: 10px;">⬆️ Uploader VPS</h3>
                    <button onclick="window.location.href='uploader.php'" class="btn btn-primary">
                        Uploader VPS
                    </button>
                    <div style="margin-top: 8px;">
                        <a href="https://puschpromozioni.it/<?php echo $attivita; ?>/admin/uploader.php" target="_blank" class="btn btn-secondary">
                            Apri link diretto
                        </a>
                    </div>
                    <p style="font-size: 13px; color: #666; margin-top: 10px;">
                        Carica file e gestisci contenuti sul server VPS
                    </p>
                </div>
                <div>
                    <h3 style="font-size: 16px; margin-bottom: 10px;"> Gestione Log</h3>
                    <?php
                    $logs = ['api_debug.log','fcm_debug.log','notifiche_log.txt','register_log.txt'];
                    foreach ($logs as $logname):
                        $logpath = $prenotazioni_path . '/' . $logname;
                        $size = file_exists($logpath) ? round(filesize($logpath)/1024, 1) . ' KB' : '0 KB';
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;padding:8px;background:#f8f9fa;border-radius:6px;">
                        <span style="font-size:13px;"><?php echo $logname; ?> <strong>(<?php echo $size; ?>)</strong></span>
                        <div style="display:flex;gap:5px;">
                            <a href="?action=download_log&file=<?php echo $logname; ?>" class="btn btn-primary btn-sm">⬇️ Scarica</a>
                            <button onclick="clearLog('<?php echo $logname; ?>')" class="btn btn-danger btn-sm">️ Svuota</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div id="log-result" class="test-result"></div>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="card" style="text-align: center; color: #666;">
            <p>🍝 Ristorante da Mimmo - Pannello Master</p>
            <p style="font-size: 13px; margin-top: 5px;">
                VPS: 185.58.193.190 | Dominio: puschpromozioni.it
            </p>
        </div>
    </div>

    <script>
        // View codici
        function viewCodici() {
            const resultDiv = document.getElementById('codici-list');
            resultDiv.innerHTML = '⏳ Caricamento...';
            resultDiv.className = 'test-result show';
            
            fetch('?action=get_codici')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<table><tr><th>Codice</th><th>Stato</th><th>Registrato</th><th>Telefono</th><th>Data Registrazione</th><th>Data Uso</th></tr>';
                        data.codici.forEach(cod => {
                            const isRegistrato = !!cod.registrato;
                            const stato = isRegistrato
                                ? '<span style="color: #dc3545;">Registrato</span>'
                                : (cod.usato ? '<span style="color: #dc3545;">Usato</span>' : '<span style="color: #28a745;">Libero</span>');
                            const isLibero = !!cod.attivo;
                            const showDetails = !!(cod.telefono || cod.data_registrazione);
                            const registrato = isLibero ? '' : (showDetails ? '<span style="color: #28a745;">Si</span>' : '<span style="color: #dc3545;">No</span>');
                            const telefono = showDetails && cod.telefono ? cod.telefono : '-';
                            const dataRegistrazione = showDetails && cod.data_registrazione ? cod.data_registrazione : '-';
                            html += `<tr><td><strong>${cod.codice}</strong></td><td>${stato}</td><td>${registrato}</td><td>${telefono}</td><td>${dataRegistrazione}</td><td>${cod.data_uso || '-'}</td></tr>`;
                        });
                        html += '</table>';
                        resultDiv.innerHTML = html;
                        resultDiv.style.background = '#f8f9fa';
                    } else {
                        resultDiv.innerHTML = 'Errore nel caricamento dei dati';
                        resultDiv.style.background = '#f8d7da';
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = 'Errore: ' + error.message;
                    resultDiv.style.background = '#f8d7da';
                });
        }

        // View file content (primi 20 righe)
        function viewFileContent(filename) {
            const resultDiv = document.getElementById('content-' + filename);
            
            if (resultDiv.classList.contains('show')) {
                resultDiv.classList.remove('show');
                return;
            }
            
            resultDiv.innerHTML = '⏳ Caricamento...';
            resultDiv.className = 'test-result show';
            
            fetch('?action=view_file&file=' + encodeURIComponent(filename))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = '<pre style="white-space: pre-wrap; word-wrap: break-word;">' + 
                                            data.content.substring(0, 2000) + 
                                            (data.content.length > 2000 ? '\n\n... (truncato)' : '') + 
                                            '</pre>';
                    } else {
                        resultDiv.innerHTML = 'Errore: ' + data.error;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = 'Errore: ' + error.message;
                });
        }

        // Generate new code
        function generateNewCode() {
            fetch('?action=get_free_code')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ Codici disponibili (' + data.count + '): ' + data.codes.join(', '));
                    } else {
                        alert('❌ ' + data.error);
                    }
                });
        }


        // Carica promozioni (dashboard)
        function loadPromozioniDashboard() {
            const resultDiv = document.getElementById('promozioni-list');
            resultDiv.innerHTML = 'Caricamento...';
            resultDiv.className = 'test-result show';
            resultDiv.style.background = '#fff3cd';

            fetch('https://puschpromozioni.it/<?php echo $attivita; ?>/PRENOTAZIONI/promo_api-toilet-001.php?admin=1&t=' + Date.now(), {
                cache: 'no-store'
            })
                .then(response => response.json())
                .then(data => {
                    if (data && Array.isArray(data.promozioni)) {
                        let html = '<table><tr><th>ID</th><th>Titolo</th><th>Inizio</th><th>Fine</th><th>Push</th></tr>';
                        data.promozioni.forEach(promo => {
                            html += `<tr><td>${promo.id}</td><td>${promo.titolo}</td><td>${promo.data_inizio}</td><td>${promo.data_fine}</td><td>${promo.push_attivo}</td></tr>`;
                        });
                        html += '</table>';
                        resultDiv.innerHTML = html;
                        resultDiv.style.background = '#f8f9fa';
                    } else {
                        resultDiv.innerHTML = 'Nessuna promozione trovata';
                        resultDiv.style.background = '#f8d7da';
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = 'Errore: ' + error.message;
                    resultDiv.style.background = '#f8d7da';
                });
        }
        // Send push test
        function sendPushTest() {
            const resultDiv = document.getElementById('push-test-result');
            const title = (document.getElementById('push-title').value || '').trim();
            const body = (document.getElementById('push-body').value || '').trim();

            if (!title || !body) {
                alert('Inserisci titolo e messaggio');
                return;
            }

            resultDiv.innerHTML = 'Invio in corso...';
            resultDiv.className = 'test-result show';
            resultDiv.style.background = '#fff3cd';

            fetch('https://puschpromozioni.it/<?php echo $attivita; ?>/PRENOTAZIONI/send_push_vps.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: title,
                    body: body,
                    data: {},
                    source: 'promozionale'
                })
            })
                .then(response => response.json())
                .then(data => {
                    resultDiv.innerHTML = '<pre style="white-space: pre-wrap; word-wrap: break-word;">' +
                        JSON.stringify({
                            success: data.success,
                            sent: data.sent,
                            targets: data.targets,
                            results: data.results
                        }, null, 2) + '</pre>';
                    resultDiv.style.background = data.success ? '#d4edda' : '#f8d7da';
                    alert(data.success ? 'Push inviata' : ('Errore invio push: ' + (data.error || '')));
                })
                .catch(error => {
                    resultDiv.innerHTML = 'Errore: ' + error.message;
                    resultDiv.style.background = '#f8d7da';
                    alert('Errore invio push: ' + error.message);
                });
        }

        function clearLog(filename) {
            if (!confirm('Svuotare ' + filename + '?')) return;
            const resultDiv = document.getElementById('log-result');
            resultDiv.innerHTML = 'Svuotamento...';
            resultDiv.className = 'test-result show';
            fetch('?action=clear_log&file=' + encodeURIComponent(filename))
                .then(r => r.json())
                .then(data => {
                    resultDiv.innerHTML = data.success ? '✅ ' + filename + ' svuotato' : '❌ Errore';
                    resultDiv.style.background = data.success ? '#d4edda' : '#f8d7da';
                    setTimeout(() => location.reload(), 1500);
                });
        }
    </script>
</body>
</html>







