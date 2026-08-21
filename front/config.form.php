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

if (isset($_POST['update'])) {
    $missingFields = Config::validateInput($_POST);

    if (!empty($missingFields)) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('Configuration not saved: required fields are missing: %s', 'bitwardensend'),
                implode(', ', $missingFields)
            ),
            false,
            ERROR
        );
    } elseif (Config::saveFromInput($_POST)) {
        Session::addMessageAfterRedirect(__('Configuration saved.', 'bitwardensend'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Could not save the configuration.', 'bitwardensend'), false, ERROR);
    }
    Html::redirect(Config::getConfigTabUrl());
}

if (isset($_POST['test'])) {
    try {
        $status = SendDriverFactory::create()->testConnection();

        switch ($status) {
            case 'unlocked':
            case 'ok':
                Session::addMessageAfterRedirect(
                    __('Connected, vault unlocked. The plugin is ready to use.', 'bitwardensend'),
                    true,
                    INFO
                );
                break;

            case 'locked':
                Session::addMessageAfterRedirect(
                    __(
                        'The service answers but the vault is locked. Set the master password below so the '
                        . 'plugin can unlock it, or unlock the service manually on the server.',
                        'bitwardensend'
                    ),
                    false,
                    WARNING
                );
                break;

            case 'unauthenticated':
                Session::addMessageAfterRedirect(
                    __(
                        'The service answers but no account is logged in. Run "bw login --apikey" on the '
                        . 'server as the service user.',
                        'bitwardensend'
                    ),
                    false,
                    ERROR
                );
                break;

            default:
                Session::addMessageAfterRedirect(
                    sprintf(__('Unexpected vault status: %s', 'bitwardensend'), $status),
                    false,
                    WARNING
                );
        }
    } catch (Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect(Config::getConfigTabUrl());
}

Html::redirect(Config::getConfigTabUrl());
