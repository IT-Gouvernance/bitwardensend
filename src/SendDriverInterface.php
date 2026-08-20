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
 * A way to create/revoke a Bitwarden Send. Two implementations exist:
 * CliSendDriver (talks to `bw serve`/the `bw` binary — the historical, only
 * way this plugin worked) and NativeSendDriver (talks to the Bitwarden API
 * directly in PHP, no binary — the only option on hosts without shell/system
 * access, e.g. GLPI Cloud). SendDriverFactory picks between the two based on
 * plugin configuration; nothing else in the plugin should instantiate either
 * driver directly.
 */
interface SendDriverInterface
{
    /**
     * Create a text Send and return what the rest of the plugin needs to
     * store and show it. Throws RuntimeException (a message safe to show the
     * user — never a raw secret/credential) on any failure.
     */
    public function createSend(SendPayload $payload): SendResult;

    /**
     * Revoke (delete) a Send on the Bitwarden side.
     */
    public function deleteSend(string $uuid): void;

    /**
     * Verify the current configuration works, without creating a persistent
     * Send. Returns a short machine-readable status keyword — what counts as
     * a valid keyword differs per driver (e.g. the CLI driver's vault
     * "locked"/"unlocked" concept has no equivalent in the native driver),
     * so callers must not assume a shared vocabulary; front/config.form.php
     * maps each driver's own keywords to a user-facing message.
     *
     * @throws RuntimeException if the check itself could not be performed
     *     (e.g. unreachable endpoint) — as opposed to a bad-but-checkable
     *     status, which is returned as a keyword instead.
     */
    public function testConnection(): string;

    /**
     * Whether this driver's configuration is complete enough, and the
     * current PHP environment has what it needs (e.g. required extensions),
     * to plausibly work — a cheap, local check, not a network round-trip
     * (that's testConnection()'s job).
     */
    public function isAvailable(): bool;
}
