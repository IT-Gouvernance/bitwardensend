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

use GlpiPlugin\Bitwardensend\EncString;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \GlpiPlugin\Bitwardensend\EncString
 */
final class EncStringTest extends TestCase
{
    private function keys(): array
    {
        return [random_bytes(32), random_bytes(32)];
    }

    public function testRoundTrip(): void
    {
        [$encKey, $macKey] = $this->keys();

        $enc = EncString::encrypt('Hello, Send!', $encKey, $macKey);
        $serialized = (string) $enc;

        $parsed = EncString::parse($serialized);
        self::assertSame('Hello, Send!', $parsed->decrypt($encKey, $macKey));
    }

    public function testRoundTripWithEmptyPlaintext(): void
    {
        [$encKey, $macKey] = $this->keys();

        $enc = EncString::encrypt('', $encKey, $macKey);
        $parsed = EncString::parse((string) $enc);

        self::assertSame('', $parsed->decrypt($encKey, $macKey));
    }

    public function testSerializedFormatHasThreePipeSeparatedBase64Parts(): void
    {
        [$encKey, $macKey] = $this->keys();

        $serialized = (string) EncString::encrypt('some text', $encKey, $macKey);

        self::assertMatchesRegularExpression(
            '/^2\.[A-Za-z0-9+\/]+=*\|[A-Za-z0-9+\/]*=*\|[A-Za-z0-9+\/]+=*$/',
            $serialized,
        );
    }

    public function testDecryptRejectsTamperedMac(): void
    {
        [$encKey, $macKey] = $this->keys();

        $enc = EncString::encrypt('secret content', $encKey, $macKey);
        $serialized = (string) $enc;

        // Flip one byte of the decoded mac, then re-encode — mangling the
        // base64 text directly (e.g. strrev()) can move its padding '='
        // out of place and produce a string that is not valid base64 at
        // all, which parse() rejects before decrypt() ever gets to check
        // the MAC. Tampering the decoded bytes keeps it valid base64 so
        // this test actually exercises the MAC check.
        $parts = explode('|', $serialized);
        $macBytes = base64_decode($parts[2]);
        $macBytes[0] = chr(ord($macBytes[0]) ^ 0xFF);
        $parts[2] = base64_encode($macBytes);
        $tampered = implode('|', $parts);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MAC/');
        EncString::parse($tampered)->decrypt($encKey, $macKey);
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        [$encKey, $macKey] = $this->keys();

        $serialized = (string) EncString::encrypt('secret content', $encKey, $macKey);

        $parts = explode('|', $serialized);
        $parts[1] = base64_encode(strrev(base64_decode($parts[1])));
        $tampered = implode('|', $parts);

        $this->expectException(RuntimeException::class);
        EncString::parse($tampered)->decrypt($encKey, $macKey);
    }

    public function testDecryptWithWrongKeysFailsMacCheck(): void
    {
        [$encKey, $macKey] = $this->keys();
        [$otherEncKey, $otherMacKey] = $this->keys();

        $serialized = (string) EncString::encrypt('secret content', $encKey, $macKey);

        $this->expectException(RuntimeException::class);
        EncString::parse($serialized)->decrypt($otherEncKey, $otherMacKey);
    }

    public function testParseRejectsUnsupportedType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/type/i');
        EncString::parse('0.' . base64_encode('iv-goes-here-16b') . '|' . base64_encode('data'));
    }

    public function testParseRejectsMissingParts(): void
    {
        $this->expectException(RuntimeException::class);
        EncString::parse('2.' . base64_encode(random_bytes(16)) . '|' . base64_encode('data'));
    }

    public function testParseRejectsMissingTypePrefix(): void
    {
        $this->expectException(RuntimeException::class);
        EncString::parse('nodothere');
    }

    public function testParseRejectsInvalidBase64(): void
    {
        $this->expectException(RuntimeException::class);
        EncString::parse('2.not base64!!|not base64!!|not base64!!');
    }

    public function testParseRejectsWrongIvLength(): void
    {
        $shortIv = base64_encode('short');
        $mac = base64_encode(random_bytes(32));

        $this->expectException(RuntimeException::class);
        EncString::parse('2.' . $shortIv . '|' . base64_encode('data') . '|' . $mac);
    }

    public function testParseRejectsWrongMacLength(): void
    {
        $iv = base64_encode(random_bytes(16));
        $shortMac = base64_encode('short');

        $this->expectException(RuntimeException::class);
        EncString::parse('2.' . $iv . '|' . base64_encode('data') . '|' . $shortMac);
    }

    public function testEncryptRejectsWrongKeyLengths(): void
    {
        $this->expectException(RuntimeException::class);
        EncString::encrypt('text', 'too-short', random_bytes(32));
    }
}
