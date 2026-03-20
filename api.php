<?php
clearstatcache();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT');
header('Access-Control-Allow-Headers: Content-Type');

// Config centralizzata
$env = dirname(__DIR__) . '/config/env.php';
if (file_exists($env)) {
    require_once $env;
}

$log_file = (defined('LOG_DIR') ? LOG_DIR : __DIR__) . '/api_debug.log';
$db_path = defined('DB_APPOINTMENTS') ? DB_APPOINTMENTS : __DIR__ . '/appointments.db';

// Connessione SQLite
try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Crea tabella con struttura corretta per i dati dall'applicazione
    $pdo->exec("
     CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_name TEXT NOT NULL,
    appointment_date TEXT NOT NULL,
    appointment_time TEXT NOT NULL,
    telefono TEXT,
    activation_code TEXT,
    note TEXT,
    status TEXT DEFAULT 'pending',
    source TEXT,
    timestamp TEXT,
    agenda_id TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
    ");

    // Tabella messaggi (per inbox calendario)
    $pdo->exec("
     CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT,
    nome_cliente TEXT,
    telefono_cliente TEXT,
    testo_messaggio TEXT,
    stato TEXT DEFAULT 'da_leggere',
    data_invio DATETIME DEFAULT CURRENT_TIMESTAMP,
    cliente_id TEXT,
    user_id TEXT
)
    ");

    try {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN activation_code TEXT");
    } catch(Exception $e) {}
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
    exit;
}

// Parametri
$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? '';
$agenda_id = $_REQUEST['agenda_id'] ?? null;

// PING (test connessione)
if ($action === 'ping' || $action === 'test') {
    echo json_encode([
        'success' => true,
        'message' => 'API operativa',
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s')
    ]);
    exit;
}


// GET messaggi
if ($method === 'GET' && $action === 'messages') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM messages ORDER BY datetime(data_invio) DESC, id DESC");
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($messages);
    } catch(Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'QUERY_FAILED',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// GET - Recupera appuntamenti
if ($method === 'GET') {
    try {
        $sql = "SELECT * FROM appointments";
        $params = [];
        
        if ($agenda_id) {
            $sql .= " WHERE agenda_id = ?";
            $params[] = $agenda_id;
        }
        
        $sql .= " ORDER BY appointment_date, appointment_time";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Converti nel formato che l'applicazione si aspetta (FullCalendar format)
        $formatted = [];
        foreach ($appointments as $appt) {
            $formatted[] = [
                'id' => $appt['id'],
                'title' => $appt['customer_name'],
                'start' => $appt['appointment_date'] . 'T' . $appt['appointment_time'] . ':00',
                'backgroundColor' => ($appt['status'] === 'confirmed' || $appt['status'] === 'confermato') ? '#27ae60' : '#f39c12',
                'borderColor' => ($appt['status'] === 'confirmed' || $appt['status'] === 'confermato') ? '#27ae60' : '#f39c12',
                'extendedProps' => [
                    'customer_name' => $appt['customer_name'],
                    'appointment_date' => $appt['appointment_date'],
                    'appointment_time' => $appt['appointment_time'],
                    'telefono' => $appt['telefono'] ?? '',
                    'note' => $appt['note'] ?? '',
                    'status' => $appt['status'],
                    'source' => $appt['source'] ?? '',
                    'timestamp' => $appt['timestamp'] ?? '',
                    'updated_at' => $appt['updated_at'] ?? '',
                    'activation_code' => $appt['activation_code'] ?? ''
                ]
            ];
        }
        
        echo json_encode($formatted);
        
    } catch(Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'QUERY_FAILED',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}


// POST messaggio
if ($method === 'POST' && $action === 'message') {
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (!$data) {
            throw new Exception('Dati non validi');
        }
        if (empty($data['nome_cliente']) || empty($data['telefono_cliente']) || empty($data['testo_messaggio'])) {
            throw new Exception('Campi obbligatori mancanti');
        }

        // Identificatore cliente per push: se manca cliente_id/user_id uso il telefono normalizzato
        $clienteId = $data['cliente_id'] ?? $data['user_id'] ?? null;
        if (!$clienteId && !empty($data['telefono_cliente'])) {
            $clienteId = preg_replace('/\\D+/', '', $data['telefono_cliente']);
        }
        if (!$clienteId) {
            throw new Exception('Impossibile identificare il cliente (manca cliente_id/user_id/telefono)');
        }

        $telefonoCliente = $data['telefono_cliente'] ?? '';
        $telefonoNorm = preg_replace('/\\D+/', '', $telefonoCliente);
        $stmt = $pdo->prepare("INSERT INTO messages (tipo, nome_cliente, telefono_cliente, testo_messaggio, stato, data_invio, cliente_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['tipo'] ?? 'messaggio',
            $data['nome_cliente'],
            $telefonoCliente,
            $data['testo_messaggio'],
            $data['stato'] ?? 'da_leggere',
            $data['data_invio'] ?? date('Y-m-d H:i:s'),
            $clienteId,
            $data['user_id'] ?? null
        ]);
        echo json_encode([
            'success' => true,
            'status' => 'saved',
            'id' => $pdo->lastInsertId(),
            'message' => 'Messaggio salvato',
            'cliente_id' => $clienteId
        ]);
    } catch(Exception $e) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// POST - Crea appuntamento
if ($method === 'POST') {
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (!$data) {
            throw new Exception('Dati non validi');
        }
        
        // Validazione
        if (empty($data['customer_name']) || empty($data['appointment_date']) || empty($data['appointment_time'])) {
            throw new Exception('Campi obbligatori mancanti');
        }
        
        // Verifica slot non occupato
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ?");
        $checkStmt->execute([$data['appointment_date'], $data['appointment_time']]);
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Slot già occupato'
            ]);
            exit;
        }
        
        // Inserisci
      $stmt = $pdo->prepare("
         INSERT INTO appointments 
        (customer_name, appointment_date, appointment_time, telefono, activation_code, note, status, source, timestamp, agenda_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
    $stmt->execute([
    $data['customer_name'] ?? $data['cliente'] ?? '',
    $data['appointment_date'],
    $data['appointment_time'],
    $data['telefono'] ?? '',
    $data['activation_code'] ?? '',
    $data['note'] ?? '',
    'pending',
    $data['source'] ?? 'website_booking',
    $data['timestamp'] ?? date('Y-m-d H:i:s'),
    $agenda_id
]);       
           
        $newId = $pdo->lastInsertId();
        file_put_contents($log_file, "POST SALVATO ID: $newId STATUS: pending\n", FILE_APPEND);
        file_put_contents($log_file, "POST TELEFONO: " . ($data['telefono'] ?? 'VUOTO') . "\n", FILE_APPEND);

        // === INVIO EMAIL ===
        $to = 'mariominessi65@gmail.com';
        $subject = 'Nuova prenotazione in attesa';
        $nome = $data['customer_name'] ?? ($data['cliente'] ?? '');
        $telefono = $data['telefono'] ?? '';
        $dataAppt = $data['appointment_date'] ?? '';
        $oraAppt = $data['appointment_time'] ?? '';
        $note = $data['note'] ?? '';

        $body = "Nuova prenotazione in attesa\n"
              . "Nome: {$nome}\n"
              . "Telefono: {$telefono}\n"
              . "Data: {$dataAppt}\n"
              . "Ora: {$oraAppt}\n"
              . "Note: {$note}\n";

        $headers = "From: noreply@consulenticaniegatti.com\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($to, $subject, $body, $headers);

        // === INVIO TELEGRAM ===
        $botToken = '8713563530:AAFCw2BUyd8L9DP_alxzcDX8hKMqWmjOucM';
        $chatId = '668117404';

        $telegramText = "📅 Nuova prenotazione in attesa\n"
                      . "Nome: {$nome}\n"
                      . "Telefono: {$telefono}\n"
                      . "Data: {$dataAppt}\n"
                      . "Ora: {$oraAppt}\n"
                      . "Note: {$note}";

        $telegramUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $telegramText
        ];

        $ch = curl_init($telegramUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);

        echo json_encode([
            'status' => 'saved',
            'success' => true,
            'id' => $pdo->lastInsertId(),
            'message' => 'Appuntamento salvato'
        ]);
        
    } catch(Exception $e) {
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}


// DELETE messaggio
if ($method === 'DELETE' && $action === 'message') {
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (empty($data['id'])) {
            throw new Exception('ID mancante');
        }
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
        $stmt->execute([$data['id']]);
        echo json_encode(['success' => true, 'status' => 'deleted']);
    } catch(Exception $e) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// DELETE - Elimina appuntamento
if ($method === 'DELETE') {
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (empty($data['id'])) {
            throw new Exception('ID mancante');
        }
        
        $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
        $stmt->execute([$data['id']]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'status' => 'deleted',
                'success' => true,
                'message' => 'Appuntamento eliminato'
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Appuntamento non trovato'
            ]);
        }
        
    } catch(Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}


// PUT messaggio (es. segna come letto)
if ($method === 'PUT' && $action === 'message') {
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (empty($data['id'])) {
            throw new Exception('ID mancante');
        }
        $updates = [];
        $params = [];
        if (isset($data['stato'])) {
            $updates[] = "stato = ?";
            $params[] = $data['stato'];
        }
        if (isset($data['testo_messaggio'])) {
            $updates[] = "testo_messaggio = ?";
            $params[] = $data['testo_messaggio'];
        }
        if (isset($data['data_invio'])) {
            $updates[] = "data_invio = ?";
            $params[] = $data['data_invio'];
        }
        if (empty($updates)) {
            throw new Exception('Nessun campo da aggiornare');
        }
        $params[] = $data['id'];
        $sql = "UPDATE messages SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'status' => 'updated']);
    } catch(Exception $e) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// PUT - Aggiorna appuntamento (conferma o cambia data/ora)
if ($method === 'PUT') {
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        file_put_contents($log_file, date('Y-m-d H:i:s') . " PUT RICEVUTO: " . json_encode($data) . "\n", FILE_APPEND);
        
        if (empty($data['id'])) {
            throw new Exception('ID mancante');
        }
        
        $updates = [];
        $params = [];
        
        // Aggiorna status se presente
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
        }
        
        // Aggiorna data/ora se presente (formato start: 2025-11-04T09:30:00)
        if (isset($data['start'])) {
            $datetime = explode('T', $data['start']);
            if (count($datetime) === 2) {
                $updates[] = "appointment_date = ?";
                $params[] = $datetime[0];
                $updates[] = "appointment_time = ?";
                $params[] = substr($datetime[1], 0, 5); // Solo HH:MM
            }
        }
        
        if (empty($updates)) {
            throw new Exception('Nessun campo da aggiornare');
        }
        
        $params[] = $data['id'];
        
        $sql = "UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            file_put_contents($log_file, "PUT SUCCESS - ID: {$data['id']}\n", FILE_APPEND);
if (isset($data['status']) && ($data['status'] === 'confirmed' || $data['status'] === 'confermato')) {
    $chPush = curl_init('http://localhost/send_push_appointment.php');
    curl_setopt($chPush, CURLOPT_POST, true);
    curl_setopt($chPush, CURLOPT_POSTFIELDS, json_encode(['appointment_id' => (int)$data['id']]));
    curl_setopt($chPush, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($chPush, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPush, CURLOPT_TIMEOUT, 5);
    curl_exec($chPush);
    curl_close($chPush);
}

            echo json_encode([
                'status' => 'updated',
                'success' => true,
                'message' => 'Appuntamento aggiornato'
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Nessuna modifica o ID non trovato'
            ]);
        }
        
    } catch(Exception $e) {
        file_put_contents($log_file, "PUT ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
// Metodo non supportato
echo json_encode([
    'success' => false,
    'error' => 'METHOD_NOT_ALLOWED',
    'message' => 'Metodo non supportato'
]);
?>
