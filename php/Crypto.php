<?php
declare(strict_types=1);

namespace App;

class Crypto {
    private string $key;

    public function __construct() {
        $this->key = base64_decode($_ENV['AES_KEY_BASE64']);
        if ($this->key === false || strlen($this->key) !== 32) {
            throw new \RuntimeException('AES_KEY_BASE64 must be 32 bytes (base64).');
        }
    }

    public function encrypt(string $plaintext): array {
        $iv = random_bytes(12); // GCM 96-bit IV
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return [
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'tag' => $tag
        ];
    }

    public function decrypt(string $ciphertext, string $iv, string $tag): string {
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plaintext;
    }
}