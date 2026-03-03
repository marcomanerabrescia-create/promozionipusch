<?php
/**
 * Invio conferma appuntamenti via FCM (1-to-1).
 * Input (JSON/POST):
 *  { appointment_id: 123 }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Config centralizzata (DB, log)
$env = dirname(__DIR__) . '/config/env.php';
if (file_exists($env)) {
    require_once $env;
}

function out($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function logConferma($line)
{
    $dir = __DIR__ . '/debug_logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
    }
    $file = $dir . '/conferme_trace.log';
    file_put_contents($file, $line . "\n", FILE_APPEND);
}

// Input
$json = json_decode(file_get_contents('php://input'), true);
$json = is_array($json) ? $json : [];

$appointmentId = isset($json['appointment_id']) ? (int)$json['appointment_id'] : 0;
if ($appointmentId <= 0) {
    out(['success' => false, 'error' => 'APPOINTMENT_REQUIRED'], 400);
}

// Carica appuntamento
$dbAppt = defined('DB_APPOINTMENTS') ? DB_APPOINTMENTS : __DIR__ . '/appointments.db';
$pdoAppt = new PDO('sqlite:' . $dbAppt);
$stmt = $pdoAppt->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->execute([$appointmentId]);
$appt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appt) {
    out(['success' => false, 'error' => 'APPOINTMENT_NOT_FOUND'], 404);
}

// Recupera token FCM da attivazioni.db
$telefono = $appt['telefono'] ?? '';
@file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[SEND_APPT] telefono_appt={$telefono}\n", FILE_APPEND);
@file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[APPOINTMENT_FCM] telefono={$telefono}\n", FILE_APPEND);

$dbPath = __DIR__ . '/attivazioni.db';
$fcmToken = '';
if (file_exists($dbPath)) {
    $db = new PDO('sqlite:' . $dbPath);
    $stmt = $db->prepare('SELECT fcm_token FROM attivazioni WHERE telefono = :tel OR codice_attivazione = :tel LIMIT 1');
    $stmt->execute([':tel' => $telefono]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $fcmToken = $row['fcm_token'];
    }
}

@file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[SEND_APPT] token_trovato=" . (!empty($fcmToken) ? $fcmToken : 'nessuno') . "\n", FILE_APPEND);
@file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[APPOINTMENT_FCM] token=" . (!empty($fcmToken) ? $fcmToken : 'nessuno') . "\n", FILE_APPEND);

if (empty($fcmToken)) {
    logConferma(sprintf(
        "[%s] appointment_id=%s telefono=%s query=FCM_TOKEN_NOT_FOUND",
        date('Y-m-d H:i:s'),
        $appointmentId,
        $telefono
    ));
    out(['success' => false, 'error' => 'FCM_TOKEN_NOT_FOUND'], 404);
}

require_once __DIR__ . '/send_fcm_native.php';
$body = "{$appt['customer_name']}, appuntamento confermato per il {$appt['appointment_date']} alle {$appt['appointment_time']}";
$resFcm = sendFcmNotification(
    $fcmToken,
    'Appuntamento confermato',
    $body,
    [
        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        'type' => 'conferma',
        'data' => (string)($appt['appointment_date'] ?? ''),
        'ora' => (string)($appt['appointment_time'] ?? ''),
        'message' => $body
    ]
);
@file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[APPOINTMENT_FCM] risultato=" . json_encode($resFcm) . "\n", FILE_APPEND);

if (!empty($resFcm['success'])) {
    out(['success' => true, 'channel' => 'fcm']);
}

out(['success' => false, 'error' => 'FCM_SEND_FAILED'], 500);
