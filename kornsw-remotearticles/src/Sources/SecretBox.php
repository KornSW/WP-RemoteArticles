<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Sources;

/**
 * Encrypts stored credentials (libsodium secretbox; WordPress ships sodium_compat, so the functions always exist).
 * The key is derived from the WordPress salts, so a database dump alone does not reveal the passwords.
 */
final class SecretBox {

  private const KEY_CONTEXT = 'kornsw-remotearticles|credentials';

  public function __construct(
    private readonly string $keyMaterial
  ) {
  }

  /**
   * Creates a secret box keyed with the salts of this WordPress installation.
   */
  public static function fromWordPressSalts(): SecretBox {
    return new SecretBox(wp_salt('auth') . '|' . wp_salt('secure_auth'));
  }

  /**
   * Encrypts a secret; the result is safe to store in an option.
   */
  public function encrypt(string $plainText): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipherText = sodium_crypto_secretbox($plainText, $nonce, $this->deriveKey());
    return base64_encode($nonce . $cipherText);
  }

  /**
   * Decrypts a stored secret; returns null if it cannot be decrypted (e.g. the salts were changed).
   */
  public function decrypt(string $storedValue): ?string {
    $decodedValue = base64_decode($storedValue, true);
    if (!is_string($decodedValue) || strlen($decodedValue) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
      return null;
    }
    $nonce = substr($decodedValue, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipherText = substr($decodedValue, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plainText = sodium_crypto_secretbox_open($cipherText, $nonce, $this->deriveKey());
    if (!is_string($plainText)) {
      return null;
    }
    return $plainText;
  }

  /**
   * Derives the 32-byte key from the key material.
   */
  private function deriveKey(): string {
    return sodium_crypto_generichash(self::KEY_CONTEXT . '|' . $this->keyMaterial, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
  }

}
