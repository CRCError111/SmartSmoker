<?php
/**
 * Генератор VAPID ключей для Web Push
 * Запустить ОДИН РАЗ на сервере: php generate-vapid-keys.php
 * Скопировать ключи в .env
 */

$config = [
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
];

$key = openssl_pkey_new($config);
if (!$key) {
    die("OpenSSL EC не поддерживается: " . openssl_error_string() . "\n");
}

$details = openssl_pkey_get_details($key);
openssl_pkey_export($key, $privPem);

$privPemLines = explode("\n", trim($privPem));
$privDer = base64_decode(implode('', array_slice($privPemLines, 1, -1)));

// Find raw 32-byte private key
$pos = strpos($privDer, chr(0x04) . chr(0x20));
$rawPriv = ($pos !== false) ? substr($privDer, $pos + 2, 32) : substr($privDer, -32);

$x = str_pad($details['ec']['x'], 32, chr(0), STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, chr(0), STR_PAD_LEFT);
$pubKey = chr(0x04) . $x . $y;

function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$pub = base64url($pubKey);
$priv = base64url($rawPriv);

echo "Добавьте в .env:\n\n";
echo "VAPID_PUBLIC_KEY=$pub\n";
echo "VAPID_PRIVATE_KEY=$priv\n";
echo "VAPID_SUBJECT=mailto:info@crcerror.ru\n";
