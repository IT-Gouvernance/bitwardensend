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

use GlpiPlugin\Bitwardensend\Config;
use GlpiPlugin\Bitwardensend\SendDriverFactory;

Session::checkRight('config', UPDATE);

// The CSRF token is validated automatically by GLPI (the plugin declares
// "csrf_compliant" in setup.php). Tokens are single use, so calling
// Session::checkCSRF() again here would always fail.

if (isset($_POST['update'])) {
    $missingFields = Config::validateInput($_POST);

    if ($missingFields !== []) {
        // Every piece of this message is a developer-authored __() literal
        // (validateInput() only ever returns its own hardcoded field labels),
        // never anything from $_POST itself.
        /** @psalm-suppress TaintedHtml */
        Session::addMessageAfterRedirect(
            sprintf(
                __('Configuration not saved: required fields are missing: %s', 'bitwardensend'),
                implode(', ', $missingFields)
            ),
            false,
            ERROR
        );
    } elseif (Config::saveFromInput($_POST)) {
        /** @psalm-suppress TaintedHtml */
        Session::addMessageAfterRedirect(__('Configuration saved.', 'bitwardensend'), true, INFO);
    } else {
        /** @psalm-suppress TaintedHtml */
        Session::addMessageAfterRedirect(__('Could not save the configuration.', 'bitwardensend'), false, ERROR);
    }

    Html::redirect(Config::getConfigTabUrl());
}

if (isset($_POST['test'])) {
    try {
        $status = SendDriverFactory::create()->testConnection();

        // Every branch's own text is a __() literal; only the default case
        // below interpolates $status, which is escaped there since it comes
        // from the driver (bw serve locally, or the CLI status output).
        /** @psalm-suppress TaintedHtml */
        match ($status) {
            'unlocked', 'ok' => Session::addMessageAfterRedirect(
                __('Connected, vault unlocked. The plugin is ready to use.', 'bitwardensend'),
                true,
                INFO
            ),
            'locked' => Session::addMessageAfterRedirect(
                __(
                    'The service answers but the vault is locked. Set the master password below so the '
                    . 'plugin can unlock it, or unlock the service manually on the server.',
                    'bitwardensend'
                ),
                false,
                WARNING
            ),
            'unauthenticated' => Session::addMessageAfterRedirect(
                __(
                    'The service answers but no account is logged in. Run "bw login --apikey" on the '
                    . 'server as the service user.',
                    'bitwardensend'
                ),
                false,
                ERROR
            ),
            default => Session::addMessageAfterRedirect(
                sprintf(__('Unexpected vault status: %s', 'bitwardensend'), htmlspecialchars($status, ENT_QUOTES)),
                false,
                WARNING
            ),
        };
    } catch (Throwable $e) {
        /** @psalm-suppress TaintedHtml */
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage(), ENT_QUOTES), false, ERROR);
    }

    Html::redirect(Config::getConfigTabUrl());
}

Html::redirect(Config::getConfigTabUrl());
