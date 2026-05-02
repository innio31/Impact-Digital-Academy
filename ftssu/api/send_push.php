<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connect.php';

// VAPID Keys
define('VAPID_PUBLIC_KEY',  'BOi3CgueVPx_-CM45Wfrd6up3AYLvX2uGhoWSDKQJOifPpnxJTUsShQMZljZIyVBFEUehuTiQdR6ul6vLB-0xOI');
define('VAPID_PRIVATE_KEY', '9Bl3v-7X1tsV0e9dZTSCCvKZI6j6-W-n2q1tmmOwX8c');
define('VAPID_SUBJECT',     'mailto:samadeimmanuel@yahoo.com');

/**
 * Main function: send push + store notification in DB
 *
 * @param array  $target_roles  e.g. ['all'] or ['Acct Admin', 'IT Admin']
 * @param string $title
 * @param string $body
 * @param string $type          announcement | order | birthday | general
 * @param string $url           deep link on click
 */
function sendPushToRoles($conn, $target_roles, $title, $body, $type = 'general', $url = '/dashboard')
{
    // Build role filter
    if (in_array('all', $target_roles)) {
        $query = "SELECT m.id, ps.endpoint, ps.p256dh, ps.auth 
                  FROM members m 
                  JOIN push_subscriptions ps ON m.id = ps.member_id 
                  WHERE m.is_active = 1";
        $stmt = $conn->prepare($query);
    } else {
        $placeholders = implode(',', array_fill(0, count($target_roles), '?'));
        $query = "SELECT m.id, ps.endpoint, ps.p256dh, ps.auth 
                  FROM members m 
                  JOIN push_subscriptions ps ON m.id = ps.member_id 
                  WHERE m.role IN ($placeholders) AND m.is_active = 1";
        $stmt = $conn->prepare($query);
        $types = str_repeat('s', count($target_roles));
        $stmt->bind_param($types, ...$target_roles);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $subscribers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sent = 0;
    $failed = 0;

    foreach ($subscribers as $sub) {
        // Store notification record in DB
        $nStmt = $conn->prepare("INSERT INTO notifications (member_id, title, body, type) VALUES (?, ?, ?, ?)");
        $nStmt->bind_param("isss", $sub['id'], $title, $body, $type);
        $nStmt->execute();
        $nStmt->close();

        // Send push notification
        $result = sendWebPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $title, $body, $url);
        if ($result) $sent++;
        else $failed++;
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Low-level Web Push sender using VAPID (no Composer needed)
 */
function sendWebPush($endpoint, $p256dh, $auth, $title, $body, $url)
{
    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'icon'  => '/icons/manifest-icon-192.png',
        'badge' => '/icons/manifest-icon-192.png',
        'url'   => $url,
        'timestamp' => time() * 1000
    ]);

    // Build VAPID JWT
    $urlParts = parse_url($endpoint);
    $audience = $urlParts['scheme'] . '://' . $urlParts['host'];

    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = base64UrlEncode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => VAPID_SUBJECT
    ]));

    $unsignedToken = $header . '.' . $claims;
    $signature = '';
    $privateKey = openssl_pkey_get_private(vapidPrivateToPEM(VAPID_PRIVATE_KEY));
    openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwt = $unsignedToken . '.' . base64UrlEncode(derToRaw($signature));

    // Encrypt payload using Web Push encryption
    $encrypted = encryptPayload($payload, $p256dh, $auth);
    if (!$encrypted) return false;

    $headers = [
        'Authorization: vapid t=' . $jwt . ',k=' . VAPID_PUBLIC_KEY,
        'Content-Type: application/octet-stream',
        'Content-Encoding: aesgcm',
        'Encryption: salt=' . base64UrlEncode($encrypted['salt']),
        'Crypto-Key: dh=' . base64UrlEncode($encrypted['serverPublicKey']) . ';p256ecdsa=' . VAPID_PUBLIC_KEY,
        'TTL: 86400',
        'Content-Length: ' . strlen($encrypted['ciphertext'])
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted['ciphertext']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 201 = success, 410/404 = subscription expired (should delete)
    if ($httpCode === 410 || $httpCode === 404) {
        // Clean up expired subscription
        global $conn;
        $delStmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $delStmt->bind_param("s", $endpoint);
        $delStmt->execute();
        $delStmt->close();
    }

    return ($httpCode === 201 || $httpCode === 200);
}

function encryptPayload($payload, $p256dh, $auth)
{
    $userPublicKey = base64UrlDecode($p256dh);
    $userAuth      = base64UrlDecode($auth);
    $salt          = random_bytes(16);

    // Generate server EC key pair
    $serverKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $serverKeyDetails = openssl_pkey_get_details($serverKey);
    $serverPublicKey  = "\x04" . $serverKeyDetails['ec']['x'] . $serverKeyDetails['ec']['y'];

    // ECDH shared secret
    $userKeyResource = openssl_pkey_get_public([
        'curve_name' => 'prime256v1',
        'x' => substr($userPublicKey, 1, 32),
        'y' => substr($userPublicKey, 33, 32),
    ]);
    openssl_pkey_export($serverKey, $serverPrivPEM);
    $serverPrivResource = openssl_pkey_get_private($serverPrivPEM);

    // Derive shared secret via ECDH
    $sharedSecret = '';
    if (!openssl_dh_compute_key($sharedSecret, $userKeyResource, $serverPrivResource)) {
        // Fallback manual ECDH
        return null;
    }

    // HKDF to derive content encryption key and nonce
    $prk = hash_hmac('sha256', $sharedSecret, $userAuth, true);
    $info = "Content-Encoding: auth\x00";
    $ikm  = hash_hmac('sha256', $prk, $info . "\x01", true);

    $keyInfo   = "Content-Encoding: aesgcm\x00" . "\x00A" . $userPublicKey . "\x00A" . $serverPublicKey;
    $nonceInfo = "Content-Encoding: nonce\x00"  . "\x00A" . $userPublicKey . "\x00A" . $serverPublicKey;

    $saltPrk   = hash_hmac('sha256', $salt, $ikm, true);
    $contentKey = substr(hash_hmac('sha256', $saltPrk, $keyInfo   . "\x01", true), 0, 16);
    $nonce      = substr(hash_hmac('sha256', $saltPrk, $nonceInfo . "\x01", true), 0, 12);

    // Pad payload
    $padded = "\x00\x00" . $payload;

    // AES-128-GCM encrypt
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return null;

    return [
        'ciphertext'      => $ciphertext . $tag,
        'salt'            => $salt,
        'serverPublicKey' => $serverPublicKey
    ];
}

function vapidPrivateToPEM($base64UrlKey)
{
    $key = base64UrlDecode($base64UrlKey);
    // ASN.1 DER structure for EC private key (prime256v1)
    $der = "\x30\x77\x02\x01\x01\x04\x20" . $key
        . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
        . "\xa1\x44\x03\x42\x00\x04"
        . str_repeat("\x00", 64); // placeholder public key bytes
    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

function derToRaw($signature)
{
    // Convert DER-encoded signature to raw 64-byte R+S
    $offset = 3;
    $rLen = ord($signature[$offset++]);
    $r = substr($signature, $offset, $rLen);
    $offset += $rLen + 1;
    $sLen = ord($signature[$offset++]);
    $s = substr($signature, $offset, $sLen);
    return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
        . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
}

function base64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data)
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// -------------------------------------------------------
// Handle direct POST calls to this file
// (called internally by other PHP files too)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['title'], $data['body'], $data['roles'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $result = sendPushToRoles(
        $conn,
        $data['roles'],
        $data['title'],
        $data['body'],
        $data['type']  ?? 'general',
        $data['url']   ?? '/dashboard'
    );

    echo json_encode(['success' => true, 'result' => $result]);
}
