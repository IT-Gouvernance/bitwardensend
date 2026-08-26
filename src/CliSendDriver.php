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
 * Bitwarden Send driver backed by the official client's local API
 * (`bw serve`'s Vault Management API over HTTP).
 *
 * Formerly the plugin's only driver (as the class `Client`); renamed to sit
 * behind SendDriverInterface alongside NativeSendDriver.
 */
class CliSendDriver implements SendDriverInterface
{
    /** @var array<string,mixed> */
    private array $conf;

    /**
     * @param array<string,mixed>|null $conf
     */
    public function __construct(?array $conf = null)
    {
        $this->conf = $conf ?? Config::getConfig();
    }

    public function createSend(SendPayload $payload): SendResult
    {
        $result = $this->createTextSend($payload->name, $payload->text, [
            'notes'             => $payload->notes,
            'hidden'            => $payload->hidden,
            'max_access_count'  => $payload->maxAccessCount,
            'deletion_date'     => $payload->deletionDate,
            'expiration_date'   => $payload->expirationDate,
            'password'          => $payload->password,
            'hide_email'        => $payload->hideEmail,
        ]);

        return new SendResult(
            uuid: $result['uuid'],
            accessId: $result['access_id'],
            accessUrl: $result['access_url'],
            deletionDate: $result['deletion_date'],
        );
    }

    public function isAvailable(): bool
    {
        $apiUrl = $this->conf['api_url'] ?? '';
        $apiUrl = is_string($apiUrl) ? $apiUrl : '';

        return trim($apiUrl) !== '';
    }

    /**
     * Create a text Send.
     *
     * @param array<string,mixed> $options
     * @return array{uuid:string,access_id:string,access_url:string,deletion_date:?string}
     */
    private function createTextSend(string $name, string $text, array $options = []): array
    {
        $rawMaxAccess = $options['max_access_count'] ?? 0;
        $max_access   = is_numeric($rawMaxAccess) ? (int) $rawMaxAccess : 0;
        $rawPassword  = $options['password'] ?? '';
        $password     = is_string($rawPassword) ? $rawPassword : '';

        $payload = [
            'type'           => 0,
            'name'           => $name,
            'notes'          => $options['notes'] ?? null,
            'text'           => [
                'text'   => $text,
                'hidden' => (bool) ($options['hidden'] ?? true),
            ],
            'file'           => null,
            'maxAccessCount' => $max_access > 0 ? $max_access : null,
            'deletionDate'   => $options['deletion_date'] ?? null,
            'expirationDate' => $options['expiration_date'] ?? null,
            'password'       => $password !== '' ? $password : null,
            'disabled'       => false,
            'hideEmail'      => (bool) ($options['hide_email'] ?? false),
        ];

        return $this->normalize($this->createViaServe($payload));
    }

    /**
     * Delete (revoke) a Send on the Bitwarden side.
     */
    public function deleteSend(string $uuid): void
    {
        if ($uuid === '') {
            throw new RuntimeException(__('Unknown Send identifier', 'bitwardensend'));
        }

        $this->ensureUnlocked();
        $response = $this->request('DELETE', '/object/send/' . rawurlencode($uuid));
        if (empty($response['success'])) {
            throw new RuntimeException($this->errorMessage($response));
        }
    }

    /**
     * Test the connection and return the vault status.
     *
     * When the vault is locked and a master password is configured, an unlock is
     * attempted so that the reported status reflects what the plugin can
     * actually use.
     */
    public function testConnection(): string
    {
        $status = $this->extractStatus($this->request('GET', '/status'));

        if ($status === 'locked' && Config::getMasterPassword() !== '') {
            $this->ensureUnlocked();
            $status = $this->extractStatus($this->request('GET', '/status'));
        }

        return $status;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createViaServe(array $payload): array
    {
        $this->ensureUnlocked();

        $response = $this->request('POST', '/object/send', $payload);
        if (empty($response['success'])) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $data = $response['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Make sure the vault is unlocked, unlocking it when a master password is
     * configured.
     */
    private function ensureUnlocked(): void
    {
        $status = $this->extractStatus($this->request('GET', '/status'));

        if ($status === 'unlocked') {
            return;
        }

        if ($status === 'unauthenticated') {
            throw new RuntimeException(
                __('The Bitwarden client is not logged in. Run "bw login" on the server.', 'bitwardensend'),
            );
        }

        $password = Config::getMasterPassword();
        if ($password === '') {
            throw new RuntimeException(
                __('The Bitwarden vault is locked and no master password is configured.', 'bitwardensend'),
            );
        }

        $response = $this->request('POST', '/unlock', ['password' => $password]);
        if (empty($response['success'])) {
            throw new RuntimeException($this->errorMessage($response));
        }
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractStatus(array $response): string
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return 'unknown';
        }

        $template = $data['template'] ?? null;
        if (is_array($template) && isset($template['status']) && is_scalar($template['status'])) {
            return (string) $template['status'];
        }

        if (isset($data['status']) && is_scalar($data['status'])) {
            return (string) $data['status'];
        }

        return 'unknown';
    }

    /**
     * HTTP call to the Vault Management API.
     *
     * @param non-empty-string $method
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $rawApiUrl = $this->conf['api_url'] ?? '';
        $apiUrl    = is_string($rawApiUrl) ? $rawApiUrl : '';
        $base      = rtrim($apiUrl, '/');
        if ($base === '') {
            throw new RuntimeException(__('Bitwarden API URL is not configured', 'bitwardensend'));
        }

        $handle = curl_init($base . $path);
        if ($handle === false) {
            throw new RuntimeException(__('Unable to initialize cURL', 'bitwardensend'));
        }

        $rawTimeout = $this->conf['timeout'] ?? 15;
        $timeout    = is_numeric($rawTimeout) ? (int) $rawTimeout : 15;

        $headers    = ['Accept: application/json'];
        $postFields = '';

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException(__('Unable to encode the request body', 'bitwardensend'));
            }

            $postFields = $json;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        // Built as a single literal, not mutated afterwards — phpstan can't
        // reliably re-check curl_setopt_array()'s option types once the
        // array is assembled through separate `$options[...] = ...` writes.
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // The configured api_url is admin-supplied, not attacker-supplied
            // under normal use — this is defense in depth, not the primary
            // control. Restricting the scheme keeps a misconfigured (or
            // tampered) URL from turning this into a local file reader
            // (file://) or a protocol-smuggling primitive (gopher://, ...).
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // libcurl verifies by default, but explicit beats implicit for
            // a request carrying the vault master password.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => $headers,
        ];
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
                $error,
            ));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                __('Unexpected response from the Bitwarden API (HTTP %d)', 'bitwardensend'),
                $code,
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Human readable error out of an API response.
     *
     * @param array<string,mixed> $response
     */
    private function errorMessage(array $response): string
    {
        $message = $response['message'] ?? null;
        if (!empty($message) && is_scalar($message)) {
            return (string) $message;
        }

        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $dataMessage = $data['message'] ?? null;
            if (!empty($dataMessage) && is_scalar($dataMessage)) {
                return (string) $dataMessage;
            }
        }

        return __('Unknown Bitwarden API error', 'bitwardensend');
    }

    /**
     * @param array<string,mixed> $send
     * @return array{uuid:string,access_id:string,access_url:string,deletion_date:?string}
     */
    private function normalize(array $send): array
    {
        $rawAccessId = $send['accessId'] ?? '';
        $access_id   = is_string($rawAccessId) ? $rawAccessId : '';
        $rawKey      = $send['key'] ?? '';
        $key         = is_string($rawKey) ? $rawKey : '';
        $rawUrl      = $send['accessUrl'] ?? '';
        $url         = is_string($rawUrl) ? $rawUrl : '';

        if ($url === '' && $access_id !== '' && $key !== '') {
            $rawBaseUrl = $this->conf['send_base_url'] ?? 'https://send.bitwarden.com/#';
            $baseUrl    = is_string($rawBaseUrl) ? $rawBaseUrl : 'https://send.bitwarden.com/#';
            $url        = rtrim($baseUrl, '/') . $access_id . '/' . $key;
        }

        if ($url === '') {
            throw new RuntimeException(
                __('The Send was created but no access link was returned.', 'bitwardensend'),
            );
        }

        $rawId = $send['id'] ?? '';
        $uuid  = is_string($rawId) ? $rawId : '';

        $rawDeletionDate = $send['deletionDate'] ?? null;

        return [
            'uuid'          => $uuid,
            'access_id'     => $access_id,
            'access_url'    => $url,
            'deletion_date' => is_string($rawDeletionDate) ? $rawDeletionDate : null,
        ];
    }
}
