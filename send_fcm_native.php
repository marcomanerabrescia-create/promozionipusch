<?php
/**
 * Invio FCM nativo via HTTP v1 senza dipendenze esterne.
 *
 * Path Service Account: /root/fcm/agenda-prenotazione-25d7b-fd74169dc60a.json
 * Endpoint: https://fcm.googleapis.com/v1/projects/{project_id}/messages:send
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

function readServiceAccount()
{
    $path = '/root/fcm/agenda-prenotazione-25d7b-fd74169dc60a.json';
    if (!file_exists($path)) {
        throw new RuntimeException("Service account JSON non trovato: {$path}");
    }
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json) || empty($json['private_key']) || empty($json['client_email']) || empty($json['project_id'])) {
        throw new RuntimeException('Service account JSON non valido');
    }
    return $json;
}

function base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generateAccessToken(array $sa)
{
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => $sa['client_email'],
        'sub' => $sa['client_email'],
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging'
    ];

    $segments = [
        base64url_encode(json_encode($header)),
        base64url_encode(json_encode($payload)),
    ];
    $signingInput = implode('.', $segments);

    $key = openssl_pkey_get_private($sa['private_key']);
    if (!$key) {
        throw new RuntimeException('Impossibile caricare private_key dal service account');
    }
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $key, 'sha256')) {
        throw new RuntimeException('Firma JWT fallita');
    }
    $segments[] = base64url_encode($signature);
    $jwt = implode('.', $segments);

    // Exchange JWT for access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ])
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    @file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[SEND_FCM] oauth_http={$http} resp={$resp}\n", FILE_APPEND);

    if ($resp === false) {
        throw new RuntimeException('Errore HTTP token: ' . $err);
    }
    $data = json_decode($resp, true);
    if ($http !== 200 || empty($data['access_token'])) {
        throw new RuntimeException('Access token non ottenuto: HTTP ' . $http . ' resp=' . $resp);
    }
    return $data['access_token'];
}

function sendFcmNotification($fcmToken, $title, $body, $data = [])
{
    $result = ['success' => false, 'http_code' => 0, 'error' => null];
    file_put_contents(__DIR__ . '/fcm_debug.log', "[SEND_FCM_FUNC] token=" . $fcmToken . "\n", FILE_APPEND);
    try {
        @file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[SEND_FCM] token={$fcmToken}\n", FILE_APPEND);
        $sa = readServiceAccount();
        $accessToken = generateAccessToken($sa);

        $url = 'https://fcm.googleapis.com/v1/projects/' . $sa['project_id'] . '/messages:send';

        $notification = [
            'title' => $title,
            'body' => $body,
        ];

        $message = [
            'message' => [
                'token' => $fcmToken,
                'notification' => $notification,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'icon' => 'ic_launcher',
                        'color' => '#FF6600',
                        'sound' => 'default',
                    ],
                ],
                'data' => (object)array_map('strval', array_merge([
                    'url' => 'https://puschpromozioni.it/ristorantemimmo1/PRENOTAZIONI/agenda-cliente-001.html',
                    'title' => (string)$title,
                    'body' => (string)$body
                ], $data ?: [])),
            ],
        ];

        file_put_contents('fcm_debug.log', json_encode($message, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($message),
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        @file_put_contents('/root/push/ristorantemimmo1/PRENOTAZIONI/fcm_debug.log', "[SEND_FCM] fcm_resp_http={$http} resp={$resp}\n", FILE_APPEND);

        $result['http_code'] = $http;
        if ($resp === false) {
            $result['error'] = 'Curl error: ' . $err;
            return $result;
        }
        if ($http >= 200 && $http < 300) {
            $result['success'] = true;
            return $result;
        }
        $result['error'] = 'HTTP ' . $http . ' resp=' . $resp;
        return $result;
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }
}

// Entrypoint CLI/simple POST for testing
// Uso CLI:
// php send_fcm_native.php TOKEN "Messaggio" ["Titolo opzionale"]
if (php_sapi_name() === 'cli') {
    if (isset($argv[1])) {
        $token = $argv[1];
        $body  = $argv[2] ?? 'Test';
        $title = $argv[3] ?? 'Notifica';
        $res = sendFcmNotification($token, $title, $body);
        echo json_encode($res, JSON_PRETTY_PRINT) . PHP_EOL;
        exit;
    }
}

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === 'send_fcm_native.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = $input['token'] ?? ($_POST['token'] ?? '');
    $title = $input['title'] ?? ($_POST['title'] ?? 'Notifica');
    $body = $input['body'] ?? ($_POST['body'] ?? '');
    $data = $input['data'] ?? [];
    $res = sendFcmNotification($token, $title, $body, $data);
    echo json_encode($res);
    exit;
}
