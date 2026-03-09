<?php
/**
 * Invio push FCM (1-to-1).
 * Input (JSON/POST):
 *  { user_id: "...", telefono: "...", title: "...", body: "...", data: {...}, appointment_id: 123 }
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

// Config DB centralizzato
$envPath = dirname(__DIR__) . '/config/env.php';
if (file_exists($envPath)) {
    require_once $envPath;
}

function out($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// Input
$json = json_decode(file_get_contents('php://input'), true);
$json = is_array($json) ? $json : [];
$userId = isset($json['user_id']) ? trim((string)$json['user_id']) : '';
$telefonoInput = isset($json['telefono']) ? trim((string)$json['telefono']) : '';
$title = isset($json['title']) ? trim((string)$json['title']) : (isset($_POST['title']) ? trim((string)$_POST['title']) : 'Messaggio');
$body = isset($json['body']) ? trim((string)$json['body']) : (isset($_POST['body']) ? trim((string)$_POST['body']) : (isset($_POST['message']) ? trim((string)$_POST['message']) : (isset($_GET['message']) ? trim((string)$_GET['message']) : '')));
$dataPayload = isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
$appointmentId = isset($json['appointment_id']) ? (int)$json['appointment_id'] : 0;

if ($telefonoInput === '' && isset($json['phone'])) {
    $telefonoInput = trim((string)$json['phone']);
}
if ($userId === '' && isset($_POST['user_id'])) {
    $userId = trim((string)$_POST['user_id']);
}
if ($telefonoInput === '' && isset($_POST['telefono'])) {
    $telefonoInput = trim((string)$_POST['telefono']);
}
if ($telefonoInput === '' && isset($_POST['phone'])) {
    $telefonoInput = trim((string)$_POST['phone']);
}
if ($userId === '' && isset($_GET['user_id'])) {
    $userId = trim((string)$_GET['user_id']);
}
if ($telefonoInput === '' && isset($_GET['telefono'])) {
    $telefonoInput = trim((string)$_GET['telefono']);
}
if ($telefonoInput === '' && isset($_GET['phone'])) {
    $telefonoInput = trim((string)$_GET['phone']);
}

// Gestione conferma appuntamento
if ($appointmentId > 0) {
    $dbAppt = defined('DB_APPOINTMENTS')
        ? DB_APPOINTMENTS
        : dirname(__DIR__) . '/PRENOTAZIONI/appointments.db';
    $pdoAppt = new PDO('sqlite:' . $dbAppt);
    $stmt = $pdoAppt->prepare("SELECT customer_name, appointment_date, appointment_time, activation_code, telefono FROM appointments WHERE id = ?");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appt) {
        out(['success' => false, 'error' => 'APPOINTMENT_NOT_FOUND'], 404);
    }

    $userId = $appt['activation_code'] ?: $userId;
    $telefonoInput = $appt['telefono'] ?: $telefonoInput;
    $title = 'Appuntamento Confermato';
    $body = $appt['customer_name'] . ', appuntamento confermato per il ' . $appt['appointment_date'] . ' alle ' . $appt['appointment_time'];
    $dataPayload = array_merge($dataPayload, [
        'type' => 'conferma',
        'data' => (string)$appt['appointment_date'],
        'ora' => (string)$appt['appointment_time'],
        'message' => $body
    ]);
}

if ($userId === '' && $telefonoInput === '') {
    out(['success' => false, 'error' => 'user_id o telefono mancanti'], 400);
}

$lookup = $telefonoInput !== '' ? $telefonoInput : $userId;
$fcmDbPath = dirname(__DIR__) . '/PRENOTAZIONI/attivazioni.db';
$fcmToken = '';
if (file_exists($fcmDbPath)) {
    $db = new PDO('sqlite:' . $fcmDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare('SELECT fcm_token FROM attivazioni WHERE telefono = :val OR codice_attivazione = :val LIMIT 1');
    $stmt->execute([':val' => $lookup]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['fcm_token'])) {
        $fcmToken = $row['fcm_token'];
    }
}

if ($fcmToken === '') {
    out(['success' => false, 'error' => 'FCM_TOKEN_NOT_FOUND'], 404);
}

require_once dirname(__DIR__) . '/PRENOTAZIONI/send_fcm_native.php';
$res = sendFcmNotification($fcmToken, $title, $body, $dataPayload);
if (!empty($res['success'])) {
    out(['success' => true, 'channel' => 'fcm']);
}
out(['success' => false, 'error' => $res['error'] ?? 'FCM_SEND_FAILED', 'http' => $res['http_code'] ?? 0], 500);
