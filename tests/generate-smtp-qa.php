<?php

require_once __DIR__ . '/paths.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = musicmadQaRoot();
if (!is_dir($root)) {
    throw new RuntimeException('Prepara el entorno QA.');
}
$config = "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=v3\n[dn]\nCN=127.0.0.1\nO=MusicMad fictional QA\n[v3]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n";
file_put_contents($root . '/smtp-qa.cnf', $config);
$options = ['config' => $root . '/smtp-qa.cnf','private_key_bits' => 2048,'private_key_type' => OPENSSL_KEYTYPE_RSA,'digest_alg' => 'sha256','x509_extensions' => 'v3'];
$key = openssl_pkey_new($options);
$csr = openssl_csr_new(['commonName' => '127.0.0.1','organizationName' => 'MusicMad fictional QA'], $key, $options);
$certificate = openssl_csr_sign($csr, null, $key, 1, $options);
if (!$key || !$csr || !$certificate) {
    throw new RuntimeException('No se pudo preparar TLS local.');
}
openssl_pkey_export($key, $private, null, $options);
openssl_x509_export($certificate, $public);
file_put_contents($root . '/smtp-qa.key', $private);
file_put_contents($root . '/smtp-qa.crt', $public);
echo "TLS ficticio generado únicamente para127.0.0.1.\n";
