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
        return trim((string) ($this->conf['api_url'] ?? '')) !== '';
    }

    /**
     * Create a text Send.
     *
     * @param array<string,mixed> $options
     * @return array{uuid:string,access_id:string,access_url:string,deletion_date:?string}
     */
    private function createTextSend(string $name, string $text, array $options = []): array
    {
        $max_access = (int) ($options['max_access_count'] ?? 0);
        $password   = (string) ($options['password'] ?? '');

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
        return is_array($data) ? $data : [];
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
                __('The Bitwarden client is not logged in. Run "bw login" on the server.', 'bitwardensend')
            );
        }

        $password = Config::getMasterPassword();
        if ($password === '') {
            throw new RuntimeException(
                __('The Bitwarden vault is locked and no master password is configured.', 'bitwardensend')
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
        $data = $response['data'] ?? [];
        if (isset($data['template']['status'])) {
            return (string) $data['template']['status'];
        }

        if (isset($data['status'])) {
            return (string) $data['status'];
        }

        return 'unknown';
    }

    /**
     * HTTP call to the Vault Management API.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim((string) ($this->conf['api_url'] ?? ''), '/');
        if ($base === '') {
            throw new RuntimeException(__('Bitwarden API URL is not configured', 'bitwardensend'));
        }

        $handle = curl_init($base . $path);
        if ($handle === false) {
            throw new RuntimeException(__('Unable to initialize cURL', 'bitwardensend'));
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => (int) ($this->conf['timeout'] ?? 15),
            CURLOPT_CONNECTTIMEOUT => 5,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen((string) $json);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $raw   = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $code  = curl_getinfo($handle, CURLINFO_HTTP_CODE);
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

        return $decoded;
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
        if (!empty($response['message'])) {
            return (string) $response['message'];
        }

        if (!empty($response['data']['message'])) {
            return (string) $response['data']['message'];
        }

        return __('Unknown Bitwarden API error', 'bitwardensend');
    }

    /**
     * @param array<string,mixed> $send
     * @return array{uuid:string,access_id:string,access_url:string,deletion_date:?string}
     */
    private function normalize(array $send): array
    {
        $access_id = (string) ($send['accessId'] ?? '');
        $key       = (string) ($send['key'] ?? '');
        $url       = (string) ($send['accessUrl'] ?? '');

        if ($url === '' && $access_id !== '' && $key !== '') {
            $url = rtrim((string) ($this->conf['send_base_url'] ?? 'https://send.bitwarden.com/#'), '/')
                 . $access_id . '/' . $key;
        }

        if ($url === '') {
            throw new RuntimeException(
                __('The Send was created but no access link was returned.', 'bitwardensend')
            );
        }

        return [
            'uuid'          => (string) ($send['id'] ?? ''),
            'access_id'     => $access_id,
            'access_url'    => $url,
            'deletion_date' => isset($send['deletionDate']) ? (string) $send['deletionDate'] : null,
        ];
    }
}
