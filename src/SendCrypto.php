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
 *
 * Every constant and construction here was verified against the actual
 * Rust implementation in bitwarden/sdk-internal (crate bitwarden_crypto),
 * not reconstructed from memory or from Bitwarden's own (intentionally
 * high-level) public documentation — see the doc comment on each method
 * for exactly which source file it matches. None of this is standard
 * textbook HKDF usage in the way the name suggests: the master-key stretch
 * skips HKDF-Extract entirely (the master key itself is used as the PRK),
 * while the Send key derivation does its own HMAC-based extract with a
 * fixed "bitwarden-" prefix before expanding — these are two genuinely
 * different constructions, not one function reused twice.
 *
 * Stateless by design: every method is a pure function of its arguments,
 * so these can be unit tested without any GLPI bootstrap (no $DB, no
 * Session) — see tests/SendCryptoTest.php.
 */
final class SendCrypto
{
    private function __construct()
    {
        // Static-only.
    }

    /**
     * The user's master key (32 bytes) from their master password.
     *
     * Matches MasterKey::derive() / KdfDerivedKeyMaterial::derive() in
     * bitwarden_crypto/src/keys/kdf.rs: PBKDF2-HMAC-SHA256 over the
     * password, salted with the trimmed, lowercased email — plain UTF-8
     * bytes, not hashed first (that hashing-the-salt step is Argon2id-only,
     * see below).
     *
     * Argon2id accounts are deliberately unsupported: Bitwarden salts
     * Argon2id with SHA-256(email) — 32 bytes — but PHP's sodium extension
     * hard-requires a 16-byte salt for sodium_crypto_pwhash() (the only
     * Argon2id primitive it exposes), so this cannot be reproduced without
     * an extra dependency (e.g. FFI into libsodium's lower-level API) that
     * would defeat the point of a dependency-free driver. Configure the
     * Bitwarden service account with PBKDF2 (the default for API-key-only
     * accounts) to use this driver, or use the CLI driver otherwise.
     */
    public static function deriveMasterKey(
        string $password,
        string $email,
        string $kdfType,
        int $iterations
    ): string {
        if ($kdfType !== 'pbkdf2') {
            // Deliberately not translated via __(): this class has no GLPI
            // dependency at all (see the class doc comment) so it can be
            // unit tested with a plain `composer install && phpunit`, no
            // GLPI bootstrap and no __() available. NativeSendDriver
            // catches this and re-throws/logs a translated, user-facing
            // message instead.
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
     * Stretch a 32-byte master key into its 64-byte encryption+MAC pair
     * (bytes 0..32 = AES key, 32..64 = HMAC key) — used to decrypt the
     * EncString the server returns for the user key.
     *
     * Matches stretch_key() in bitwarden_crypto/src/keys/utils.rs: two
     * HKDF-Expand-SHA256 calls, info "enc" and "mac", 32 bytes each — and,
     * unusually, no HKDF-Extract step at all: the master key bytes
     * themselves are fed directly to Hkdf::from_prk() as the PRK. This is
     * NOT the same construction as deriveShareableKey() below, despite
     * both ultimately calling an "HKDF expand".
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
     * Derive a 64-byte shareable key (encryption+MAC pair) from 16 bytes of
     * secret key material and a name/info pair — used for a Send's own key,
     * derived from its randomly generated key material.
     *
     * Matches derive_shareable_key() in
     * bitwarden_crypto/src/keys/shareable_key.rs:
     *   prk = HMAC-SHA256(key = "bitwarden-{name}", message = secret)
     *   output = HKDF-Expand-SHA256(PRK = prk, info = info, L = 64)
     * i.e. the HKDF-Extract step is done "by hand" with a fixed
     * "bitwarden-" prefix on the name as the HMAC key, rather than via the
     * usual HKDF-Extract(salt, IKM) roles. Confirmed against the two fixed
     * test vectors in that same source file — see
     * SendCryptoTest::testDeriveShareableKeyMatchesUpstreamTestVectors().
     *
     * Bitwarden's own Send code calls this with name="send", info="send"
     * (see bitwarden_send/src/access.rs, SendAccessKey::from_url_b64) —
     * deriveSendKey() below is exactly that specialization.
     */
    public static function deriveShareableKey(string $secret, string $name, ?string $info): string
    {
        $prk = hash_hmac('sha256', $secret, 'bitwarden-' . $name, true);

        return self::hkdfExpand($prk, $info ?? '', 64);
    }

    /**
     * A Send's own encryption+MAC key pair, derived from its 16 bytes of
     * random key material. See deriveShareableKey() above for the
     * construction; this is that function with the fixed name/info pair
     * Bitwarden's own clients use for Sends specifically.
     */
    public static function deriveSendKey(string $keyMaterial): string
    {
        if (strlen($keyMaterial) !== 16) {
            throw new RuntimeException('Send key material must be 16 bytes.');
        }

        return self::deriveShareableKey($keyMaterial, 'send', 'send');
    }

    /**
     * The value sent to the API as a password-protected Send's "password"
     * field — never the plaintext password itself.
     *
     * Matches SendAccessKey::hash_password_b64() in
     * bitwarden_send/src/access.rs: PBKDF2-HMAC-SHA256(password, salt =
     * this Send's own raw 16-byte key material, 100000 iterations),
     * standard (padded) base64 — not base64url; unlike the key material in
     * the access URL, this value never goes into a URL.
     */
    public static function hashSendPassword(string $password, string $keyMaterial): string
    {
        if (strlen($keyMaterial) !== 16) {
            throw new RuntimeException('Send key material must be 16 bytes.');
        }

        return base64_encode(hash_pbkdf2('sha256', $password, $keyMaterial, 100000, 32, true));
    }

    /**
     * 16 bytes of random key material for a new Send.
     *
     * random_bytes(), never rand()/mt_rand()/uniqid(): this becomes the
     * only secret in the Send's access URL, so it needs to be
     * cryptographically unpredictable, not merely varied.
     */
    public static function randomKeyMaterial(): string
    {
        return random_bytes(16);
    }

    /**
     * RFC 5869 HKDF-Expand (SHA-256), given an already-computed PRK.
     *
     * Deliberately not PHP's own hash_hkdf(): that function always does
     * HKDF-Extract itself first (from a $key/$salt pair) before expanding,
     * with no way to feed it a pre-extracted PRK directly — exactly what
     * both callers above need (stretchKey() skips extract entirely;
     * deriveShareableKey() extracts by hand with non-standard salt/IKM
     * roles). Matches bitwarden_crypto/src/util.rs's hkdf_expand(), which
     * wraps the `hkdf` crate's Hkdf::from_prk() + expand() — itself a
     * textbook RFC 5869 implementation, so this is not a Bitwarden-specific
     * construction, just the missing "expand-only" primitive.
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
     * Base64url-encode, WITH padding.
     *
     * Confirmed against bitwarden_encoding's B64Url type: "indifferent
     * about padding when decoding, but always produces padding when
     * encoding" — the padding-free convention common elsewhere (e.g. JWTs)
     * does not apply here, so this deliberately keeps the trailing '='.
     * Used for the key material in a Send's access URL fragment.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return strtr(base64_encode($bytes), '+/', '-_');
    }

    /**
     * Best-effort zeroing of a secret held in a PHP string.
     *
     * "Best-effort": PHP strings are copy-on-write and immutable from the
     * language's own point of view, so earlier copies made along the way
     * (functions received the value by value, string concatenation, etc.)
     * are not reachable to wipe. This only clears the specific variable
     * passed in — call it on every local holding a secret, as close as
     * possible to where that secret stops being needed, but treat it as
     * reducing exposure, not as a hard guarantee.
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
