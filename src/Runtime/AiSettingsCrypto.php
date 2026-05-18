<?php

namespace App\Runtime;

final class AiSettingsCrypto
{
    private readonly string $key;
    private readonly bool $available;

    public function __construct(string $configuredKey, string $appSecret)
    {
        $this->available = function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES');
        $material = trim($configuredKey) !== '' ? $configuredKey : $appSecret;
        $this->key = $this->available
            ? hash_hkdf('sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'throttle-ai-settings', '')
            : '';
    }

    public function encrypt(string $plaintext): string
    {
        $this->assertAvailable();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $this->assertAvailable();
        $decoded = base64_decode($payload, true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored AI credential payload is invalid.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Stored AI credential payload could not be decrypted.');
        }

        return $plaintext;
    }

    private function assertAvailable(): void
    {
        if (!$this->available) {
            throw new \RuntimeException('The sodium extension is required for encrypted AI settings.');
        }
    }
}
