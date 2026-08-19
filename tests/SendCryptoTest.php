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

namespace GlpiPlugin\Bitwardensend\Tests;

use GlpiPlugin\Bitwardensend\SendCrypto;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GlpiPlugin\Bitwardensend\SendCrypto
 */
final class SendCryptoTest extends TestCase
{
    /**
     * Real test vectors, lifted as-is from
     * bitwarden_crypto/src/keys/shareable_key.rs's own unit test
     * (test_derive_shareable_key) in bitwarden/sdk-internal — this is not
     * a vector we invented, it is the upstream Rust implementation's own
     * fixture, so a match here means our derive_shareable_key port is
     * byte-for-byte interoperable with the real one, not just internally
     * self-consistent.
     */
    public function testDeriveShareableKeyMatchesUpstreamTestVectors(): void
    {
        $key = SendCrypto::deriveShareableKey('&/$%F1a895g67HlX', 'test_key', null);
        self::assertSame(
            '4PV6+PcmF2w7YHRatvyMcVQtI7zvCyssv/wFWmzjiH6Iv9altjmDkuBD1aagLVaLezbthbSe+ktR+U6qswxNnQ==',
            base64_encode($key)
        );

        $key = SendCrypto::deriveShareableKey('67t9b5g67$%Dh89n', 'test_key', 'test');
        self::assertSame(
            'F9jVQmrACGx9VUPjuzfMYDjr726JtL300Y3Yg+VYUnVQtQ1s8oImJ5xtp1KALC9h2nav04++1LDW4iFD+infng==',
            base64_encode($key)
        );
    }

    public function testDeriveShareableKeyOutputIs64Bytes(): void
    {
        $key = SendCrypto::deriveShareableKey(random_bytes(16), 'test_key', null);
        self::assertSame(64, strlen($key));
    }

    public function testDeriveSendKeyRejectsWrongLengthKeyMaterial(): void
    {
        $this->expectException(\RuntimeException::class);
        SendCrypto::deriveSendKey('too-short');
    }

    public function testDeriveSendKeyIsDeriveShareableKeyWithSendSendFixed(): void
    {
        $keyMaterial = random_bytes(16);

        self::assertSame(
            SendCrypto::deriveShareableKey($keyMaterial, 'send', 'send'),
            SendCrypto::deriveSendKey($keyMaterial)
        );
    }

    public function testStretchKeyProduces64Bytes(): void
    {
        $stretched = SendCrypto::stretchKey(random_bytes(32));
        self::assertSame(64, strlen($stretched));
    }

    public function testStretchKeyRejectsWrongLengthMasterKey(): void
    {
        $this->expectException(\RuntimeException::class);
        SendCrypto::stretchKey('too-short');
    }

    public function testStretchKeyEncAndMacHalvesDiffer(): void
    {
        // Not a cryptographic assertion, just a sanity check that "enc" and
        // "mac" info strings actually produce different output — a copy-paste
        // bug reusing the same info for both would silently pass everything
        // else while making the two halves of the stretched key identical.
        $stretched = SendCrypto::stretchKey(random_bytes(32));
        self::assertNotSame(substr($stretched, 0, 32), substr($stretched, 32, 32));
    }

    public function testDeriveMasterKeyRejectsArgon2id(): void
    {
        $this->expectException(\RuntimeException::class);
        SendCrypto::deriveMasterKey('password', 'user@example.com', 'argon2id', 3);
    }

    public function testDeriveMasterKeyIsDeterministic(): void
    {
        $a = SendCrypto::deriveMasterKey('password', 'User@Example.COM', 'pbkdf2', 600000);
        $b = SendCrypto::deriveMasterKey('password', ' user@example.com ', 'pbkdf2', 600000);

        // Email is trimmed and lowercased before use as salt, so these two
        // differently-cased/spaced inputs must land on the same key.
        self::assertSame($a, $b);
        self::assertSame(32, strlen($a));
    }

    public function testBase64UrlEncodeKeepsPadding(): void
    {
        // 16 bytes -> 24 base64 chars incl. 0 padding normally, so pick a
        // length that actually produces '=' padding to prove it survives.
        $encoded = SendCrypto::base64UrlEncode(random_bytes(5));
        self::assertStringEndsWith('=', $encoded);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
    }

    public function testRandomKeyMaterialIsSixteenBytesAndVaries(): void
    {
        $a = SendCrypto::randomKeyMaterial();
        $b = SendCrypto::randomKeyMaterial();

        self::assertSame(16, strlen($a));
        self::assertNotSame($a, $b);
    }
}
