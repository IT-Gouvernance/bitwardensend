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
 * Talks to the Bitwarden API directly in PHP — no `bw` binary, no shell
 * access. Authenticates as a service account via its API key
 * (client_credentials grant), same as `bw login --apikey`.
 *
 * The /connect/token response field casing (Key vs key, Kdf vs kdf...)
 * wasn't confirmed against a live call while writing this, so both the
 * snake_case and PascalCase names get checked below — see
 * tests/NativeSendDriverIntegrationTest.php for the one that actually runs
 * against a real account.
 */
class NativeSendDriver implements SendDriverInterface
{
    /** @var array<string,mixed> */
    private array $conf;

    private readonly string $clientSecret;

    private readonly string $masterPassword;

    /**
     * The three params default to Config's getters, but can be passed
     * directly (the integration test does this, from env vars, so it can
     * run without a GLPI bootstrap).
     *
     * @param array<string,mixed>|null $conf
     */
    public function __construct(?array $conf = null, ?string $clientSecret = null, ?string $masterPassword = null)
    {
        $this->conf = $conf ?? Config::getConfig();
        $this->clientSecret = $clientSecret ?? Config::getNativeClientSecret();
        $this->masterPassword = $masterPassword ?? Config::getNativeMasterPassword();
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('openssl')) {
            return false;
        }

        foreach (['native_identity_url', 'native_api_url', 'native_web_vault_url', 'native_client_id', 'native_email'] as $field) {
            $rawValue = $this->conf[$field] ?? '';
            $value    = is_string($rawValue) ? $rawValue : '';
            if (trim($value) === '') {
                return false;
            }
        }

        return $this->clientSecret !== '' && $this->masterPassword !== '';
    }

    public function testConnection(): string
    {
        $this->authenticate();
        return 'ok';
    }

    public function createSend(SendPayload $payload): SendResult
    {
        $session = $this->authenticate();
        $userEncKey = substr($session['userKey'], 0, 32);
        $userMacKey = substr($session['userKey'], 32, 32);
        SendCrypto::zero($session['userKey']);

        $keyMaterial = SendCrypto::randomKeyMaterial();
        $sendKey = SendCrypto::deriveSendKey($keyMaterial);
        $sendEncKey = substr($sendKey, 0, 32);
        $sendMacKey = substr($sendKey, 32, 32);
        SendCrypto::zero($sendKey);

        $hasPassword = $payload->password !== null && $payload->password !== '';

        $body = [
            'type' => 0, // text
            'name' => (string) EncString::encrypt($payload->name, $sendEncKey, $sendMacKey),
            'notes' => ($payload->notes !== null && $payload->notes !== '')
                ? (string) EncString::encrypt($payload->notes, $sendEncKey, $sendMacKey)
                : null,
            'text' => [
                'text'   => (string) EncString::encrypt($payload->text, $sendEncKey, $sendMacKey),
                'hidden' => $payload->hidden,
            ],
            'file' => null,
            'maxAccessCount' => ($payload->maxAccessCount !== null && $payload->maxAccessCount > 0)
                ? $payload->maxAccessCount
                : null,
            'deletionDate'   => $payload->deletionDate,
            'expirationDate' => $payload->expirationDate,
            // Hashed, not plaintext — see SendCrypto::hashSendPassword(). The
            // CLI driver can send it as-is because bw serve hashes it before
            // the request reaches Bitwarden; here we are the client, so we
            // have to do that step ourselves.
            'password'  => $hasPassword ? SendCrypto::hashSendPassword($payload->password, $keyMaterial) : null,
            'disabled'  => $payload->disabled,
            'hideEmail' => $payload->hideEmail,
            'key'       => (string) EncString::encrypt($keyMaterial, $userEncKey, $userMacKey),
        ];
        SendCrypto::zero($sendEncKey);
        SendCrypto::zero($sendMacKey);
        SendCrypto::zero($userEncKey);
        SendCrypto::zero($userMacKey);

        $rawApiUrl = $this->conf['native_api_url'] ?? '';
        $apiUrl    = rtrim(is_string($rawApiUrl) ? $rawApiUrl : '', '/');
        if ($apiUrl === '') {
            throw new RuntimeException(__('Bitwarden API URL is not configured', 'bitwardensend'));
        }

        $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encodedBody === false) {
            throw new RuntimeException(__('Unable to encode the request body', 'bitwardensend'));
        }

        $response = $this->httpRequest(
            'POST',
            $apiUrl . '/sends',
            $encodedBody,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $session['accessToken']]
        );

        $rawId       = $response['id'] ?? '';
        $id          = is_string($rawId) ? $rawId : '';
        $rawAccessId = $response['accessId'] ?? '';
        $accessId    = is_string($rawAccessId) ? $rawAccessId : '';
        if ($id === '' || $accessId === '') {
            throw new RuntimeException(__('Bitwarden did not return a Send id/accessId', 'bitwardensend'));
        }

        $rawWebVaultUrl = $this->conf['native_web_vault_url'] ?? '';
        $webVaultUrl    = rtrim(is_string($rawWebVaultUrl) ? $rawWebVaultUrl : '', '/');
        $accessUrl = $webVaultUrl . '/#/send/' . $accessId . '/' . SendCrypto::base64UrlEncode($keyMaterial);

        $rawDeletionDate = $response['deletionDate'] ?? null;

        return new SendResult(
            uuid: $id,
            accessId: $accessId,
            accessUrl: $accessUrl,
            deletionDate: is_string($rawDeletionDate) ? $rawDeletionDate : null,
        );
    }

    public function deleteSend(string $uuid): void
    {
        if ($uuid === '') {
            throw new RuntimeException(__('Unknown Send identifier', 'bitwardensend'));
        }

        // Just the token, not the full authenticate() — revoking doesn't
        // need the user key, so skip the PBKDF2 run for nothing.
        $accessToken = $this->requestAccessToken()['accessToken'];

        $rawApiUrl = $this->conf['native_api_url'] ?? '';
        $apiUrl    = rtrim(is_string($rawApiUrl) ? $rawApiUrl : '', '/');
        if ($apiUrl === '') {
            throw new RuntimeException(__('Bitwarden API URL is not configured', 'bitwardensend'));
        }

        $this->httpRequest(
            'DELETE',
            $apiUrl . '/sends/' . rawurlencode($uuid),
            null,
            ['Authorization: Bearer ' . $accessToken]
        );
    }

    // ------------------------------------------------------------------
    // Authentication + user key unlock
    // ------------------------------------------------------------------

    /**
     * Logs in and decrypts the account's user key (see
     * SendCrypto::stretchKey()), which encrypts a Send's own key material
     * for the server. Not cached — re-authenticating each call is simpler
     * than tracking a token's lifetime, and Sends aren't created often
     * enough for the extra round trip to matter.
     *
     * @return array{accessToken:string,userKey:string}
     */
    private function authenticate(): array
    {
        $token = $this->requestAccessToken();

        $kdfType = ((int) $token['kdf']) === 0 ? 'pbkdf2' : 'argon2id';

        $masterPassword = $this->masterPassword;
        if ($masterPassword === '') {
            throw new RuntimeException(__(
                'No master password is configured for the native driver.',
                'bitwardensend'
            ));
        }

        $rawEmail = $this->conf['native_email'] ?? '';
        $email    = is_string($rawEmail) ? $rawEmail : '';

        try {
            $masterKey = SendCrypto::deriveMasterKey($masterPassword, $email, $kdfType, $token['kdfIterations']);
        } catch (RuntimeException $runtimeException) {
            // SendCrypto can't call __() itself (no GLPI dependency), so
            // translate its one failure case here.
            throw new RuntimeException(__(
                'This account uses the Argon2id KDF, which the native driver cannot reproduce '
                . 'in PHP. Use a service account configured with PBKDF2, or switch this Send '
                . 'driver to "cli".',
                'bitwardensend'
            ), $runtimeException->getCode(), $runtimeException);
        } finally {
            SendCrypto::zero($masterPassword);
        }

        $stretchedMasterKey = SendCrypto::stretchKey($masterKey);
        SendCrypto::zero($masterKey);

        $userKeyEncString = EncString::parse($token['key']);
        $encKey = substr($stretchedMasterKey, 0, 32);
        $macKey = substr($stretchedMasterKey, 32, 32);

        try {
            $userKey = $userKeyEncString->decrypt($encKey, $macKey);
        } catch (RuntimeException) {
            // Never let the underlying exception message leak key material —
            // it can't, since EncString's own messages never include key
            // bytes, but re-wrapping keeps that guarantee obviously true
            // here too rather than relying on every future EncString change
            // to preserve it.
            throw new RuntimeException(__(
                'Could not decrypt the account user key: wrong master password?',
                'bitwardensend'
            ));
        } finally {
            SendCrypto::zero($stretchedMasterKey);
            SendCrypto::zero($encKey);
            SendCrypto::zero($macKey);
        }

        if (strlen($userKey) !== 64) {
            throw new RuntimeException(__('Decrypted user key has an unexpected length.', 'bitwardensend'));
        }

        return ['accessToken' => $token['accessToken'], 'userKey' => $userKey];
    }

    /**
     * POST {identityUrl}/connect/token with the service account's API key,
     * returning the fields authenticate() needs. Field names are
     * intentionally read defensively — see the class doc comment.
     *
     * @return array{accessToken:string,kdf:int,kdfIterations:int,key:string}
     */
    private function requestAccessToken(): array
    {
        $rawIdentityUrl = $this->conf['native_identity_url'] ?? '';
        $identityUrl    = rtrim(is_string($rawIdentityUrl) ? $rawIdentityUrl : '', '/');
        if ($identityUrl === '') {
            throw new RuntimeException(__('Bitwarden identity URL is not configured.', 'bitwardensend'));
        }

        $rawClientId = $this->conf['native_client_id'] ?? '';
        $clientId    = is_string($rawClientId) ? $rawClientId : '';
        $clientSecret = $this->clientSecret;
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException(__(
                'Bitwarden API client credentials are not configured.',
                'bitwardensend'
            ));
        }

        $body = [
            'grant_type'       => 'client_credentials',
            'scope'            => 'api',
            'client_id'        => $clientId,
            'client_secret'    => $clientSecret,
            // The endpoint rejects requests with no device info. 21 is the
            // "SDK" deviceType per third-party docs — worst case it just
            // mislabels the login in the account's device history.
            'deviceType'       => 21,
            'deviceIdentifier' => $this->deviceIdentifier($clientId),
            'deviceName'       => 'glpi-bitwardensend',
        ];
        SendCrypto::zero($clientSecret);

        // $body['client_secret'] is its own copy of the secret, unaffected
        // by zeroing $clientSecret above — encode it into the request body
        // first, then zero that copy too rather than leaving it sitting in
        // $body for the rest of this call. Copied into a plain string first:
        // zero() takes its argument by reference, and an array element isn't
        // guaranteed to be a string from a static analysis standpoint.
        $encodedBody = http_build_query($body, '', '&', PHP_QUERY_RFC1738);
        $bodyClientSecret = (string) $body['client_secret'];
        SendCrypto::zero($bodyClientSecret);
        $body['client_secret'] = $bodyClientSecret;

        $response = $this->httpRequest(
            'POST',
            $identityUrl . '/connect/token',
            $encodedBody,
            ['Content-Type: application/x-www-form-urlencoded']
        );

        $accessToken   = $response['access_token'] ?? $response['AccessToken'] ?? null;
        $kdf           = $response['kdf'] ?? $response['Kdf'] ?? null;
        $kdfIterations = $response['kdfIterations'] ?? $response['KdfIterations'] ?? null;
        $key           = $response['key'] ?? $response['Key'] ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException(__('Bitwarden did not return an access token.', 'bitwardensend'));
        }

        if (!is_numeric($kdf) || !is_numeric($kdfIterations)) {
            throw new RuntimeException(__('Bitwarden did not return KDF parameters.', 'bitwardensend'));
        }

        if (!is_string($key) || $key === '') {
            throw new RuntimeException(__(
                'Bitwarden did not return the account user key.',
                'bitwardensend'
            ));
        }

        return [
            'accessToken'   => $accessToken,
            'kdf'           => (int) $kdf,
            'kdfIterations' => (int) $kdfIterations,
            'key'           => $key,
        ];
    }

    /** UUID-shaped, derived from client_id so it's stable without persisting it anywhere. */
    private function deviceIdentifier(string $clientId): string
    {
        $hash = hash('sha256', 'bitwardensend:' . $clientId);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-'
             . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }

    /**
     * @param array<string,mixed>|string|null $body
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    private function httpRequest(string $method, string $url, $body, array $headers = []): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException(__('Unable to initialize cURL', 'bitwardensend'));
        }

        $rawTimeout = $this->conf['timeout'] ?? 15;
        $timeout    = is_numeric($rawTimeout) ? (int) $rawTimeout : 15;

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // No config flag to disable this — talking to Bitwarden's real
            // servers over an unverified connection defeats the point.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $options[CURLOPT_HTTPHEADER] = array_merge(
            [
                'Accept: application/json',
                // Required — the API rejects requests without it ("No client
                // version header found"). Bump this if a server ever starts
                // rejecting it as too old.
                'Bitwarden-Client-Version: 2025.6.0',
            ],
            $headers
        );

        curl_setopt_array($handle, $options);

        $raw   = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $code  = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        // Not explicitly closed: CurlHandle instances close themselves once
        // unset/out of scope, and curl_close() is deprecated as of PHP 8.5.

        if ($errno !== 0) {
            throw new RuntimeException(sprintf(
                __('Cannot reach the Bitwarden API (%s)', 'bitwardensend'),
                $error
            ));
        }

        $decoded = json_decode((string) $raw, true);

        if ($code < 200 || $code >= 300) {
            $rawMessage = is_array($decoded)
                ? ($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? $code)
                : $code;
            $message = is_scalar($rawMessage) ? (string) $rawMessage : (string) $code;
            throw new RuntimeException(sprintf(__('Bitwarden API error: %s', 'bitwardensend'), $message));
        }

        // DELETE /sends/{id} returns 200/204 with an empty body — not an error.
        if ($raw === '' || $raw === false) {
            return [];
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                __('Unexpected response from the Bitwarden API (HTTP %d)', 'bitwardensend'),
                $code
            ));
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
