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
 * Bitwarden Send driver talking to the Bitwarden API directly in PHP — no
 * `bw` binary, no shell access, works on hosts that can only reach out over
 * HTTPS (e.g. GLPI Cloud). Authenticates as a dedicated service account via
 * its API key (client_credentials grant), the same flow `bw login --apikey`
 * uses.
 *
 * Config field names (native_identity_url, native_api_url, ...) and the
 * Config::getNativeClientSecret()/getNativeMasterPassword() decrypting
 * getters this class defaults its two secret constructor parameters to
 * are wired up in Config.php together with the rest of the native driver's
 * configuration screen — see that commit for the schema.
 *
 * The /connect/token response field names below (Key, Kdf, KdfIterations,
 * access_token, ...) could not be confirmed against a live call during
 * development — bitwarden/server's own source was not reachable — so both
 * the OAuth-standard snake_case names (access_token, expires_in) and the
 * PascalCase names third-party write-ups of the same endpoint show (Key,
 * Kdf, KdfIterations) are accepted; whichever one is real will be picked
 * up, and the other simply never matches. The opt-in integration test (see
 * tests/NativeSendDriverIntegrationTest.php, added once createSend() exists)
 * is what actually proves this against the real API.
 */
class NativeSendDriver implements SendDriverInterface
{
    /** @var array<string,mixed> */
    private array $conf;
    private string $clientSecret;
    private string $masterPassword;

    /**
     * $clientSecret/$masterPassword default to Config's own decrypting
     * getters (the normal path, used by SendDriverFactory) but can be
     * passed explicitly instead — the only way this class touches GLPI at
     * all is through those two getters and Config::getConfig(), so
     * supplying all three directly (as the opt-in integration test does,
     * from plain environment variables) makes this class fully usable with
     * no GLPI bootstrap.
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
            if (trim((string) ($this->conf[$field] ?? '')) === '') {
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
            // Never the plaintext password: the real API only ever sees a
            // PBKDF2 hash of it, salted with this Send's own (not the
            // user's) key material — see SendCrypto::hashSendPassword(). The
            // CLI driver can send the password as-is because `bw serve`
            // itself does this hashing before this plugin's request to it
            // ever reaches the real Bitwarden API; here, this plugin *is*
            // that client, so it has to do it.
            'password'  => $hasPassword ? SendCrypto::hashSendPassword($payload->password, $keyMaterial) : null,
            'disabled'  => $payload->disabled,
            'hideEmail' => $payload->hideEmail,
            'key'       => (string) EncString::encrypt($keyMaterial, $userEncKey, $userMacKey),
        ];
        SendCrypto::zero($sendEncKey);
        SendCrypto::zero($sendMacKey);
        SendCrypto::zero($userEncKey);
        SendCrypto::zero($userMacKey);

        $apiUrl = rtrim((string) ($this->conf['native_api_url'] ?? ''), '/');
        if ($apiUrl === '') {
            throw new RuntimeException(__('Bitwarden API URL is not configured', 'bitwardensend'));
        }

        $response = $this->httpRequest(
            'POST',
            $apiUrl . '/sends',
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $session['accessToken']]
        );

        $id       = (string) ($response['id'] ?? '');
        $accessId = (string) ($response['accessId'] ?? '');
        if ($id === '' || $accessId === '') {
            throw new RuntimeException(__('Bitwarden did not return a Send id/accessId', 'bitwardensend'));
        }

        $webVaultUrl = rtrim((string) ($this->conf['native_web_vault_url'] ?? ''), '/');
        $accessUrl = $webVaultUrl . '/#/send/' . $accessId . '/' . SendCrypto::base64UrlEncode($keyMaterial);

        return new SendResult(
            uuid: $id,
            accessId: $accessId,
            accessUrl: $accessUrl,
            deletionDate: isset($response['deletionDate']) ? (string) $response['deletionDate'] : null,
        );
    }

    public function deleteSend(string $uuid): void
    {
        if ($uuid === '') {
            throw new RuntimeException(__('Unknown Send identifier', 'bitwardensend'));
        }

        // Only the bearer token, not the full authenticate(): revoking
        // needs no key material at all, and skipping the master-key
        // derivation avoids paying for a (deliberately expensive,
        // potentially 600k+ iteration) PBKDF2 run on every revoke for
        // nothing.
        $accessToken = $this->requestAccessToken()['accessToken'];

        $apiUrl = rtrim((string) ($this->conf['native_api_url'] ?? ''), '/');
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
     * Authenticate as the configured service account and decrypt its user
     * key. Returns the access token (for the /sends calls that follow) and
     * the 64-byte user enc+mac key pair (see SendCrypto::stretchKey()) that
     * encrypts a Send's own key material for the server.
     *
     * Nothing here is cached across calls: each Send operation is
     * infrequent and short-lived enough (a single HTTP request plus some
     * local key derivation) that re-authenticating every time is simpler
     * and safer than managing a token's lifetime across requests, at the
     * cost of one extra round trip per operation.
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

        $email = (string) ($this->conf['native_email'] ?? '');

        try {
            $masterKey = SendCrypto::deriveMasterKey($masterPassword, $email, $kdfType, $token['kdfIterations']);
        } catch (RuntimeException $e) {
            // SendCrypto has no GLPI dependency at all (see its own class
            // doc comment) and so cannot call __() itself — translating its
            // one possible failure (an Argon2id account) here instead.
            throw new RuntimeException(__(
                'This account uses the Argon2id KDF, which the native driver cannot reproduce '
                . 'in PHP. Use a service account configured with PBKDF2, or switch this Send '
                . 'driver to "cli".',
                'bitwardensend'
            ));
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
        } catch (RuntimeException $e) {
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
        $identityUrl = rtrim((string) ($this->conf['native_identity_url'] ?? ''), '/');
        if ($identityUrl === '') {
            throw new RuntimeException(__('Bitwarden identity URL is not configured.', 'bitwardensend'));
        }

        $clientId = (string) ($this->conf['native_client_id'] ?? '');
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
            // Device metadata is required by the token endpoint (it refuses
            // requests with none) but does not affect the cryptography
            // below — at most it mislabels this login in the account's own
            // "new device" security notifications. deviceType 21 is
            // Bitwarden's "SDK" classification in every third-party
            // reference found during development; not confirmed against a
            // live account, but low-stakes if wrong.
            'deviceType'       => 21,
            'deviceIdentifier' => self::deviceIdentifier($clientId),
            'deviceName'       => 'glpi-bitwardensend',
        ];
        SendCrypto::zero($clientSecret);

        $response = $this->httpRequest(
            'POST',
            $identityUrl . '/connect/token',
            http_build_query($body, '', '&', PHP_QUERY_RFC1738),
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

    /**
     * A stable, UUID-shaped device identifier — required by the token
     * endpoint, but nothing here depends on it matching any specific value
     * across requests beyond "the same client_id always sends the same
     * one", which this satisfies without persisting anything new: it's
     * just derived from the client_id itself, deterministically.
     */
    private static function deviceIdentifier(string $clientId): string
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

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => (int) ($this->conf['timeout'] ?? 15),
            CURLOPT_CONNECTTIMEOUT => 5,
            // Never negotiable: this driver is specifically for hosts that
            // cannot be trusted with a local vault unlock, so the transport
            // to Bitwarden's own servers must be genuinely verified — no
            // config flag exists anywhere in this plugin to turn this off.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $options[CURLOPT_HTTPHEADER] = array_merge(['Accept: application/json'], $headers);

        curl_setopt_array($handle, $options);

        $raw   = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $code  = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            throw new RuntimeException(sprintf(
                __('Cannot reach the Bitwarden API (%s)', 'bitwardensend'),
                $error
            ));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                __('Unexpected response from the Bitwarden API (HTTP %d)', 'bitwardensend'),
                $code
            ));
        }

        if ($code < 200 || $code >= 300) {
            $message = (string) ($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? $code);
            throw new RuntimeException(sprintf(__('Bitwarden API error: %s', 'bitwardensend'), $message));
        }

        return $decoded;
    }
}
