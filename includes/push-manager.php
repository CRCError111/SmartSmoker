<?php
/**
 * Push Manager — отправка Web Push уведомлений
 * Реализация RFC 8030 + VAPID (RFC 8292) без внешних библиотек
 * Шифрование: ECDH + AES-128-GCM (RFC 8291)
 *
 * @version 1.0
 */

if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

class PushManager
{
    private $db;
    private $vapidPublicKey;
    private $vapidPrivateKey;
    private $vapidSubject;

    public function __construct($db)
    {
        $this->db = $db;
        $this->vapidPublicKey  = VAPID_PUBLIC_KEY;
        $this->vapidPrivateKey = VAPID_PRIVATE_KEY;
        $this->vapidSubject    = VAPID_SUBJECT;
    }

    // -------------------------------------------------------
    // Сохранение / удаление подписок
    // -------------------------------------------------------

    public function saveSubscription($userId, $subscription)
    {
        $endpoint = $subscription['endpoint'] ?? '';
        $p256dh   = $subscription['keys']['p256dh'] ?? '';
        $auth     = $subscription['keys']['auth'] ?? '';

        if (!$endpoint || !$p256dh || !$auth) {
            return false;
        }

        // Upsert: обновить если endpoint уже есть
        $existing = $this->db->fetchOne(
            'SELECT id FROM push_subscriptions WHERE endpoint = ?',
            [$endpoint]
        );

        if ($existing) {
            return $this->db->update('push_subscriptions', [
                'user_id' => $userId,
                'p256dh'  => $p256dh,
                'auth'    => $auth,
            ], 'id = ?', [$existing['id']]);
        }

        return $this->db->insert('push_subscriptions', [
            'user_id'  => $userId,
            'endpoint' => $endpoint,
            'p256dh'   => $p256dh,
            'auth'     => $auth,
        ]);
    }

    public function deleteSubscription($endpoint)
    {
        return $this->db->query(
            'DELETE FROM push_subscriptions WHERE endpoint = ?',
            [$endpoint]
        );
    }

    public function getSubscriptionsByUser($userId)
    {
        return $this->db->fetchAll(
            'SELECT * FROM push_subscriptions WHERE user_id = ?',
            [$userId]
        );
    }

    // -------------------------------------------------------
    // Отправка уведомлений
    // -------------------------------------------------------

    /**
     * Отправить уведомление пользователю
     */
    public function sendToUser($userId, array $payload)
    {
        $subscriptions = $this->getSubscriptionsByUser($userId);
        $results = [];
        foreach ($subscriptions as $sub) {
            $results[] = $this->sendNotification($sub, $payload);
        }
        return $results;
    }

    /**
     * Отправить уведомление на конкретную подписку
     */
    public function sendNotification(array $subscription, array $payload)
    {
        if (empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            error_log('[PushManager] VAPID keys not configured');
            return false;
        }

        $endpoint = $subscription['endpoint'];
        $p256dh   = $subscription['p256dh'];
        $auth     = $subscription['auth'];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        try {
            $encrypted = $this->encrypt($payloadJson, $p256dh, $auth);
            $vapidHeaders = $this->buildVapidHeaders($endpoint);

            $headers = array_merge($vapidHeaders, [
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL'              => '86400',
            ]);

            $response = $this->curlPost($endpoint, $encrypted, $headers);

            // 410 Gone или 404 — подписка устарела, удаляем
            if (in_array($response['status'], [404, 410])) {
                $this->deleteSubscription($endpoint);
            }

            return $response['status'] >= 200 && $response['status'] < 300;

        } catch (Exception $e) {
            error_log('[PushManager] Error: ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------
    // Шифрование (RFC 8291 — aes128gcm)
    // -------------------------------------------------------

    private function encrypt($payload, $p256dhBase64, $authBase64)
    {
        $recipientPublicKey = $this->base64urlDecode($p256dhBase64);
        $authSecret         = $this->base64urlDecode($authBase64);

        // Генерируем эфемерную пару ключей
        $localKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $localDetails = openssl_pkey_get_details($localKey);

        // Публичный ключ отправителя (65 байт uncompressed)
        $senderPublicKey = chr(0x04)
            . str_pad($localDetails['ec']['x'], 32, chr(0), STR_PAD_LEFT)
            . str_pad($localDetails['ec']['y'], 32, chr(0), STR_PAD_LEFT);

        // ECDH: вычисляем общий секрет
        $recipientKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'ec' => [
                'x' => substr($recipientPublicKey, 1, 32),
                'y' => substr($recipientPublicKey, 33, 32),
            ],
        ]);

        // Используем openssl_dh_compute_key через raw ECDH
        openssl_pkey_export($localKey, $localPrivPem);
        $sharedSecret = $this->ecdhComputeShared($localPrivPem, $recipientPublicKey);

        // Salt (16 байт случайных)
        $salt = random_bytes(16);

        // HKDF для получения ключа и nonce (RFC 8291)
        $prk = $this->hkdf($authSecret, $sharedSecret,
            "WebPush: info\x00" . $recipientPublicKey . $senderPublicKey, 32);

        $cek   = $this->hkdf($salt, $prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdf($salt, $prk, "Content-Encoding: nonce\x00", 12);

        // Шифруем AES-128-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload . chr(0x02), // padding delimiter
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        // Заголовок aes128gcm (RFC 8188)
        $header = $salt                          // 16 bytes salt
            . pack('N', 4096)                    // rs = 4096
            . chr(strlen($senderPublicKey))      // idlen
            . $senderPublicKey;                  // keyid

        return $header . $ciphertext . $tag;
    }

    private function ecdhComputeShared($privPem, $recipientPubKeyRaw)
    {
        // Reconstruct recipient public key as PEM
        $x = substr($recipientPubKeyRaw, 1, 32);
        $y = substr($recipientPubKeyRaw, 33, 32);

        // Build SubjectPublicKeyInfo DER for P-256
        $ecOid    = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // OID 1.2.840.10045.2.1
        $curveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID 1.2.840.10045.3.1.7
        $algId    = "\x30" . chr(strlen($ecOid) + strlen($curveOid)) . $ecOid . $curveOid;
        $pubPoint = chr(0x04) . $x . $y;
        $bitStr   = "\x03" . chr(strlen($pubPoint) + 1) . "\x00" . $pubPoint;
        $spki     = "\x30" . chr(strlen($algId) + strlen($bitStr)) . $algId . $bitStr;

        $pubPem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $privKey = openssl_pkey_get_private($privPem);
        $pubKey  = openssl_pkey_get_public($pubPem);

        // openssl_dh_compute_key works for EC on PHP 7.3+
        $shared = openssl_dh_compute_key($pubKey, $privKey);
        if ($shared === false) {
            throw new Exception('ECDH failed: ' . openssl_error_string());
        }
        return $shared;
    }

    private function hkdf($salt, $ikm, $info, $length)
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $t   = '';
        $okm = '';
        for ($i = 1; strlen($okm) < $length; $i++) {
            $t    = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
        }
        return substr($okm, 0, $length);
    }

    // -------------------------------------------------------
    // VAPID (RFC 8292)
    // -------------------------------------------------------

    private function buildVapidHeaders($endpoint)
    {
        $parsed   = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];
        $expiry   = time() + 43200; // 12 часов

        $header  = $this->base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = $this->base64urlEncode(json_encode([
            'aud' => $audience,
            'exp' => $expiry,
            'sub' => $this->vapidSubject,
        ]));

        $signingInput = $header . '.' . $payload;
        $signature    = $this->ecSign($signingInput);

        $jwt = $signingInput . '.' . $this->base64urlEncode($signature);

        $pubKeyB64 = $this->vapidPublicKey;

        return [
            'Authorization' => "vapid t=$jwt, k=$pubKeyB64",
        ];
    }

    private function ecSign($data)
    {
        $privKeyRaw = $this->base64urlDecode($this->vapidPrivateKey);
        $pubKeyRaw  = $this->base64urlDecode($this->vapidPublicKey);

        // Reconstruct private key PEM (SEC1 / PKCS8)
        $x = substr($pubKeyRaw, 1, 32);
        $y = substr($pubKeyRaw, 33, 32);

        // Build ECPrivateKey DER (RFC 5915)
        $ecOid    = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $curveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $privOctet = "\x04\x20" . $privKeyRaw;
        $pubPoint  = chr(0x04) . $x . $y;
        $pubBitStr = "\x03" . chr(strlen($pubPoint) + 1) . "\x00" . $pubPoint;
        $pubCtx    = "\xa1" . chr(strlen($pubBitStr)) . $pubBitStr;

        $ecPrivKey = "\x30" . chr(2 + strlen($privOctet) + strlen($pubCtx))
            . "\x02\x01\x01"
            . $privOctet
            . $pubCtx;

        // Wrap in PKCS8
        $algId = "\x30" . chr(strlen($ecOid) + strlen($curveOid)) . $ecOid . $curveOid;
        $privKeyDer = "\x04" . chr(strlen($ecPrivKey)) . $ecPrivKey;
        $pkcs8 = "\x30" . chr(2 + strlen($algId) + strlen($privKeyDer))
            . "\x02\x01\x00"
            . $algId
            . $privKeyDer;

        $privPem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($pkcs8), 64, "\n")
            . "-----END PRIVATE KEY-----\n";

        $privKey = openssl_pkey_get_private($privPem);
        if (!$privKey) {
            throw new Exception('Cannot load VAPID private key: ' . openssl_error_string());
        }

        openssl_sign($data, $derSig, $privKey, OPENSSL_ALGO_SHA256);

        // Convert DER signature to raw R||S (64 bytes)
        return $this->derToRawSignature($derSig);
    }

    private function derToRawSignature($der)
    {
        // DER: 30 len 02 rlen r 02 slen s
        $offset = 2; // skip 30 len
        $offset++; // skip 02
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        $offset++; // skip 02
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        // Remove leading zero padding, pad to 32 bytes
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    // -------------------------------------------------------
    // HTTP
    // -------------------------------------------------------

    private function curlPost($url, $body, $headers)
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[PushManager] cURL error: $error");
        }

        return ['status' => $status, 'body' => $response];
    }

    // -------------------------------------------------------
    // Утилиты
    // -------------------------------------------------------

    private function base64urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode($data)
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
