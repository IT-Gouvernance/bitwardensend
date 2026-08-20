<?php

/**
 * -------------------------------------------------------------------------
 * Bitwarden Send plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Bitwarden Send.
 *
 * Bitwarden Send is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Bitwarden Send is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Bitwarden Send. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 *
 * @copyright Copyright (C) 2026 by IT Gouvernance.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/IT-Gouvernance/bitwardensend/
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Bitwardensend;

use RuntimeException;

/**
 * Bitwarden's "EncString" — an encrypted field value serialized as a single
 * string. Only type 2 (AES-256-CBC + HMAC-SHA256, "Aes256Cbc_HmacSha256_B64"
 * in Bitwarden's own enum) is implemented: the only type actually used by
 * the V1 Send format this driver targets. Type 0 (AES-256-CBC with no MAC)
 * is unauthenticated and, per Bitwarden's own source, "deprecated and MUST
 * NOT be used" — parsing it is refused outright rather than silently
 * decrypting unauthenticated ciphertext. The newer COSE-based type
 * ("Cose_Encrypt0_B64") belongs to Send V2/Item sends, out of scope here.
 *
 * Format (confirmed against bitwarden_crypto's EncString enum):
 *   2.{iv_b64}|{ciphertext_b64}|{mac_b64}
 * where the MAC is HMAC-SHA256(macKey, iv || ciphertext), and iv is 16
 * bytes (AES block size).
 */
final class EncString
{
    private const TYPE = 2;

    private function __construct(
        public readonly string $iv,
        public readonly string $ciphertext,
        public readonly string $mac,
    ) {
    }

    /**
     * Parse a serialized EncString. Does not decrypt or verify the MAC —
     * that happens in decrypt(), which needs the keys this method doesn't
     * have. Structural validation only (part count, type, base64).
     */
    public static function parse(string $serialized): self
    {
        $dot = strpos($serialized, '.');
        if ($dot === false) {
            throw new RuntimeException('Malformed EncString: missing type prefix.');
        }

        $type = substr($serialized, 0, $dot);
        if ($type !== (string) self::TYPE) {
            throw new RuntimeException(sprintf(
                'Unsupported EncString type "%s": only type 2 (AES-256-CBC + HMAC-SHA256) is implemented.',
                $type
            ));
        }

        $parts = explode('|', substr($serialized, $dot + 1));
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed EncString: expected iv|data|mac.');
        }

        [$ivB64, $ciphertextB64, $macB64] = $parts;

        $iv         = self::decodeBase64Part($ivB64, 'iv');
        $ciphertext = self::decodeBase64Part($ciphertextB64, 'ciphertext');
        $mac        = self::decodeBase64Part($macB64, 'mac');

        if (strlen($iv) !== 16) {
            throw new RuntimeException('Malformed EncString: iv must be 16 bytes.');
        }
        if (strlen($mac) !== 32) {
            throw new RuntimeException('Malformed EncString: mac must be 32 bytes.');
        }

        return new self($iv, $ciphertext, $mac);
    }

    private static function decodeBase64Part(string $value, string $field): string
    {
        if ($value === '') {
            throw new RuntimeException(sprintf('Malformed EncString: empty %s.', $field));
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException(sprintf('Malformed EncString: invalid base64 in %s.', $field));
        }

        return $decoded;
    }

    /**
     * Encrypt plaintext into a new EncString.
     *
     * @param string $encKey 32-byte AES-256 key.
     * @param string $macKey 32-byte HMAC key.
     */
    public static function encrypt(string $plaintext, string $encKey, string $macKey): self
    {
        self::assertKeyLength($encKey, 'encKey');
        self::assertKeyLength($macKey, 'macKey');

        $iv = random_bytes(16);

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-cbc',
            $encKey,
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-CBC encryption failed.');
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);

        return new self($iv, $ciphertext, $mac);
    }

    /**
     * Verify the MAC (constant-time) and decrypt.
     *
     * @param string $encKey 32-byte AES-256 key.
     * @param string $macKey 32-byte HMAC key.
     *
     * @throws RuntimeException if the MAC does not match — never attempts
     *     to decrypt unauthenticated ciphertext.
     */
    public function decrypt(string $encKey, string $macKey): string
    {
        self::assertKeyLength($encKey, 'encKey');
        self::assertKeyLength($macKey, 'macKey');

        $expectedMac = hash_hmac('sha256', $this->iv . $this->ciphertext, $macKey, true);

        // hash_equals(): a plain === here would short-circuit on the first
        // differing byte, leaking timing information about how much of a
        // forged MAC happened to be correct.
        if (!hash_equals($expectedMac, $this->mac)) {
            throw new RuntimeException('EncString MAC verification failed.');
        }

        $plaintext = openssl_decrypt(
            $this->ciphertext,
            'aes-256-cbc',
            $encKey,
            OPENSSL_RAW_DATA,
            $this->iv
        );
        if ($plaintext === false) {
            throw new RuntimeException('AES-256-CBC decryption failed.');
        }

        return $plaintext;
    }

    private static function assertKeyLength(string $key, string $label): void
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException(sprintf('%s must be 32 bytes.', $label));
        }
    }

    public function __toString(): string
    {
        return sprintf(
            '%d.%s|%s|%s',
            self::TYPE,
            base64_encode($this->iv),
            base64_encode($this->ciphertext),
            base64_encode($this->mac)
        );
    }
}
