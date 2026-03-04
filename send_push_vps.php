cat > /root/push/ristorantemimmo1/PRENOTAZIONI/send_push_vps.php << 'EOF'
<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Europe/Rome');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function out($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$title = trim($input['title'] ?? '');
$body  = trim($input['body'] ?? '');

if (!$title || !$body) {
    out(['success' => false, 'error' => 'title e body obbligatori'], 400);
}

require_once __DIR__ . '/send_fcm_native.php';

$dbPath = __DIR__ . '/attivazioni.db';
if (!file_exists($dbPath)) {
    out(['success' => false, 'error' => 'attivazioni.db non trovato'], 500);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->query("SELECT DISTINCT fcm_token, telefono FROM attivazioni WHERE fcm_token IS NOT NULL AND trim(fcm_token) != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    out(['success' => false, 'error' => 'DB error: ' . $e->getMessage()], 500);
}

if (empty($rows)) {
    out(['success' => false, 'error' => 'Nessun dispositivo registrato'], 404);
}

$targets = count($rows);
$sent = 0;
$results = [];

foreach ($rows as $row) {
    $token = trim($row['fcm_token']);
    $tel   = $row['telefono'] ?? 'n/d';
    $res = sendFcmNotification($token, $title, $body, [
        'type' => 'promo',
        'url'  => 'https://puschpromozioni.it/ristorantemimmo1/PRENOTAZIONI/agenda-cliente-001.html'
    ]);
    if ($res['success']) $sent++;
    $results[] = ['telefono' => $tel, 'success' => $res['success'], 'error' => $res['error'] ?? null];
}

out(['success' => $sent > 0, 'sent' => $sent, 'targets' => $targets, 'results' => $results]);
EOF