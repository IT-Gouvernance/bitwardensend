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
use GlpiPlugin\Bitwardensend\NativeSendDriver;
use GlpiPlugin\Bitwardensend\SendCrypto;
use GlpiPlugin\Bitwardensend\SendPayload;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof against the real Bitwarden API: creates an actual Send,
 * reads it back through the same unauthenticated endpoint a real recipient
 * would hit, decrypts it independently of NativeSendDriver's own code path,
 * and compares to the plaintext that went in. This is what would actually
 * catch a wrong HKDF info string, a field-name mismatch in the /connect/token
 * response, or any other detail that could not be confirmed against a live
 * account during development (see NativeSendDriver's own class doc comment).
 *
 * Opt-in on purpose: it needs a real, disposable Bitwarden account and
 * spends real API calls, so it is not something CI (or a casual local
 * `phpunit` run) should do without asking. Set every one of these
 * environment variables to run it for real — any one missing and the test
 * skips itself instead of failing:
 *
 *   BW_TEST_IDENTITY_URL     e.g. https://identity.bitwarden.com
 *   BW_TEST_API_URL          e.g. https://api.bitwarden.com
 *   BW_TEST_WEB_VAULT_URL    e.g. https://vault.bitwarden.com
 *   BW_TEST_CLIENT_ID        the test account's API client_id
 *   BW_TEST_CLIENT_SECRET    the test account's API client_secret
 *   BW_TEST_EMAIL            the test account's email
 *   BW_TEST_MASTER_PASSWORD  the test account's master password (PBKDF2 KDF)
 *
 * Locally:
 *   BW_TEST_IDENTITY_URL=... BW_TEST_API_URL=... [...] vendor/bin/phpunit \
 *       tests/NativeSendDriverIntegrationTest.php
 *
 * In CI: add these as repository secrets and export them as env vars in a
 * dedicated, manually-triggered job — never on a shared/public runner
 * without one, since it exercises a real account's credentials. Use a
 * disposable Bitwarden account created solely for this, never a real one.
 */
final class NativeSendDriverIntegrationTest extends TestCase
{
    /** @var array<string,string> */
    private array $env;

    protected function setUp(): void
    {
        $names = [
            'BW_TEST_IDENTITY_URL',
            'BW_TEST_API_URL',
            'BW_TEST_WEB_VAULT_URL',
            'BW_TEST_CLIENT_ID',
            'BW_TEST_CLIENT_SECRET',
            'BW_TEST_EMAIL',
            'BW_TEST_MASTER_PASSWORD',
        ];

        $env = [];
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                self::markTestSkipped(sprintf(
                    'Set %s (and the other BW_TEST_* variables — see this file\'s own '
                    . 'header) to run the native driver against a real Bitwarden account.',
                    $name
                ));
            }

            $env[$name] = $value;
        }

        $this->env = $env;
    }

    public function testCreateSendReadBackAndRevoke(): void
    {
        $driver = new NativeSendDriver(
            [
                'native_identity_url'   => $this->env['BW_TEST_IDENTITY_URL'],
                'native_api_url'        => $this->env['BW_TEST_API_URL'],
                'native_web_vault_url'  => $this->env['BW_TEST_WEB_VAULT_URL'],
                'native_client_id'      => $this->env['BW_TEST_CLIENT_ID'],
                'native_email'          => $this->env['BW_TEST_EMAIL'],
                'timeout'               => 15,
            ],
            $this->env['BW_TEST_CLIENT_SECRET'],
            $this->env['BW_TEST_MASTER_PASSWORD']
        );

        $plaintext = 'bitwardensend integration test ' . bin2hex(random_bytes(8));

        $result = $driver->createSend(new SendPayload(
            name: 'bitwardensend integration test',
            text: $plaintext,
            hidden: false,
            deletionDate: gmdate('Y-m-d\TH:i:s.000\Z', strtotime('+1 day')),
        ));

        try {
            self::assertNotSame('', $result->uuid);
            self::assertNotSame('', $result->accessId);
            self::assertStringContainsString(
                $this->env['BW_TEST_WEB_VAULT_URL'],
                $result->accessUrl
            );

            // Parse the access URL exactly as a recipient's browser would:
            // everything after the last '/' is the base64url key material,
            // never sent to or returned by the server.
            $fragment = parse_url($result->accessUrl, PHP_URL_FRAGMENT) ?? '';
            $segments = explode('/', $fragment);
            $keyMaterialB64 = array_pop($segments);
            $keyMaterial = $this->base64UrlDecode($keyMaterialB64);
            self::assertSame(16, strlen($keyMaterial));

            $accessResponse = $this->httpGet(
                rtrim($this->env['BW_TEST_API_URL'], '/') . '/sends/access/' . $result->accessId
            );

            $sendKey = SendCrypto::deriveSendKey($keyMaterial);
            $sendEncKey = substr($sendKey, 0, 32);
            $sendMacKey = substr($sendKey, 32, 32);

            $nameField = $accessResponse['name'] ?? $accessResponse['Name'] ?? null;
            $textField = $accessResponse['text']['text']
                ?? $accessResponse['Text']['Text']
                ?? null;
            self::assertIsString($nameField, 'Access response had no readable name field.');
            self::assertIsString($textField, 'Access response had no readable text field.');

            self::assertSame(
                'bitwardensend integration test',
                EncString::parse($nameField)->decrypt($sendEncKey, $sendMacKey)
            );
            self::assertSame(
                $plaintext,
                EncString::parse($textField)->decrypt($sendEncKey, $sendMacKey)
            );
        } finally {
            // Always attempt cleanup, even on assertion failure, so a
            // failing run doesn't leave a live Send behind on the test
            // account.
            $driver->deleteSend($result->uuid);
        }
    }

    /**
     * Tolerant of both padded and unpadded input, unlike SendCrypto's own
     * base64UrlEncode() (which only ever produces padded output) — this
     * mirrors what a real client reading a URL must do, matching
     * bitwarden_encoding's B64Url: "indifferent about padding when
     * decoding".
     */
    private function base64UrlDecode(string $value): string
    {
        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }

    /**
     * @return array<string,mixed>
     */
    private function httpGet(string $url): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Same requirement as NativeSendDriver's own httpRequest() — see
            // its comment for why this specific value.
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Bitwarden-Client-Version: 2025.6.0',
            ],
        ]);
        $raw = curl_exec($handle);
        $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        self::assertNotFalse($raw, 'GET ' . $url . ' failed at the transport level.');
        $decoded = json_decode((string) $raw, true);
        self::assertIsArray($decoded, sprintf('GET %s returned non-JSON (HTTP %d).', $url, $code));

        return $decoded;
    }
}
