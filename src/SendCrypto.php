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
 * Key derivation primitives for the native (pure PHP) Bitwarden driver.
 * Checked against bitwarden/sdk-internal's Rust implementation
 * (bitwarden_crypto) rather than the public docs — see each method for
 * the matching source file. No GLPI dependency, so plain phpunit can
 * exercise these directly (tests/SendCryptoTest.php).
 */
final class SendCrypto
{
    private function __construct()
    {
        // Static-only.
    }

    /**
     * The user's master key (32 bytes) from their master password.
     * Matches MasterKey::derive() in bitwarden_crypto/src/keys/kdf.rs:
     * PBKDF2-HMAC-SHA256, salted with the trimmed, lowercased email.
     *
     * Argon2id isn't supported: Bitwarden salts it with SHA-256(email) — 32
     * bytes — but PHP's sodium_crypto_pwhash() only accepts a 16-byte salt.
     * Use a PBKDF2 service account, or the CLI driver instead.
     */
    public static function deriveMasterKey(
        string $password,
        string $email,
        string $kdfType,
        int $iterations
    ): string {
        if ($kdfType !== 'pbkdf2') {
            // Not translated — no __() here, NativeSendDriver catches this
            // and re-throws a translated message.
            throw new RuntimeException(
                'This account uses the Argon2id KDF, which the native driver cannot '
                . 'reproduce in PHP. Use a service account configured with PBKDF2, or '
                . 'switch this Send driver to "cli".'
            );
        }

        $salt = mb_strtolower(trim($email));

        return hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    }

    /**
     * 32-byte master key → 64-byte enc+MAC pair (bytes 0..32 = AES key,
     * 32..64 = HMAC key), for decrypting the server's EncString of the user
     * key. Matches stretch_key() in bitwarden_crypto/src/keys/utils.rs: two
     * HKDF-Expand-SHA256 calls ("enc"/"mac"), no Extract step — the master
     * key itself is used as the PRK.
     */
    public static function stretchKey(string $masterKey): string
    {
        if (strlen($masterKey) !== 32) {
            throw new RuntimeException('Master key must be 32 bytes.');
        }

        return self::hkdfExpand($masterKey, 'enc', 32)
             . self::hkdfExpand($masterKey, 'mac', 32);
    }

    /**
     * A Send's own 64-byte shareable key, from its random key material.
     * Matches derive_shareable_key() in
     * bitwarden_crypto/src/keys/shareable_key.rs:
     *   prk = HMAC-SHA256(key = "bitwarden-{name}", message = secret)
     *   output = HKDF-Expand-SHA256(prk, info, 64)
     * Test vectors in SendCryptoTest::testDeriveShareableKeyMatchesUpstreamTestVectors().
     */
    public static function deriveShareableKey(string $secret, string $name, ?string $info): string
    {
        $prk = hash_hmac('sha256', $secret, 'bitwarden-' . $name, true);

        return self::hkdfExpand($prk, $info ?? '', 64);
    }

    /** deriveShareableKey() with the fixed name/info pair Bitwarden uses for Sends. */
    public static function deriveSendKey(string $keyMaterial): string
    {
        if (strlen($keyMaterial) !== 16) {
            throw new RuntimeException('Send key material must be 16 bytes.');
        }

        return self::deriveShareableKey($keyMaterial, 'send', 'send');
    }

    /**
     * The Send's "password" API field — a hash, never the plaintext.
     * Matches SendAccessKey::hash_password_b64() in bitwarden_send/src/access.rs:
     * PBKDF2-HMAC-SHA256(password, salt = the Send's key material, 100000
     * iterations), standard base64 (not base64url — this never goes in a URL).
     */
    public static function hashSendPassword(string $password, string $keyMaterial): string
    {
        if (strlen($keyMaterial) !== 16) {
            throw new RuntimeException('Send key material must be 16 bytes.');
        }

        return base64_encode(hash_pbkdf2('sha256', $password, $keyMaterial, 100000, 32, true));
    }

    /** 16 random bytes for a new Send — this ends up as the only secret in its access URL. */
    public static function randomKeyMaterial(): string
    {
        return random_bytes(16);
    }

    /**
     * RFC 5869 HKDF-Expand (SHA-256) given an already-computed PRK. Not
     * PHP's hash_hkdf() — it always does Extract first, with no way to
     * pass in a pre-extracted PRK, which is what both callers above need.
     */
    private static function hkdfExpand(string $prk, string $info, int $length): string
    {
        $hashLength = 32; // SHA-256
        $blocks = (int) ceil($length / $hashLength);
        if ($blocks > 255) {
            throw new RuntimeException('Requested HKDF output is too long.');
        }

        $previousBlock = '';
        $output = '';
        for ($i = 1; $i <= $blocks; $i++) {
            $previousBlock = hash_hmac('sha256', $previousBlock . $info . chr($i), $prk, true);
            $output .= $previousBlock;
        }

        return substr($output, 0, $length);
    }

    /**
     * Base64url, WITH padding (unlike e.g. JWTs) — matches bitwarden_encoding's
     * B64Url. Used for the key material in a Send's access URL fragment.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return strtr(base64_encode($bytes), '+/', '-_');
    }

    /**
     * Best-effort: only wipes this one variable, not any earlier copy PHP
     * made along the way. Reduces exposure, doesn't guarantee anything.
     */
    public static function zero(string &$secret): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($secret);
            return;
        }

        // No sodium: overwrite in place rather than leaving the original
        // plaintext as the only value ever assigned to this variable.
        $secret = str_repeat("\0", strlen($secret));
    }
}
