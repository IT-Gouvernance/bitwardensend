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
 *
 * Bitwarden Send plugin for GLPI 11
 *
 * Adds a button to the ITIL timeline that lets a technician share a secret
 * (password, key, token...) through a Bitwarden Send link, and posts that link
 * as a followup.
 */

use GlpiPlugin\Bitwardensend\Config;
use GlpiPlugin\Bitwardensend\Profile;
use GlpiPlugin\Bitwardensend\Send;

define('PLUGIN_BITWARDENSEND_VERSION', '1.0.0-beta2');
define('PLUGIN_BITWARDENSEND_MIN_GLPI', '11.0.0');
define('PLUGIN_BITWARDENSEND_MAX_GLPI', '11.0.99');

/**
 * Plugin initialization.
 */
function plugin_init_bitwardensend(): void
{
    global $PLUGIN_HOOKS;

    // Hook names are passed as plain strings rather than through the
    // Glpi\Plugin\Hooks constants: a missing constant would raise a fatal error
    // if GLPI ever renames or moves that class.
    $PLUGIN_HOOKS['csrf_compliant']['bitwardensend'] = true;

    // JavaScript and CSS are inlined by templates/assets.html.twig instead of
    // being served as static files: the GLPI 11 router does not always expose
    // them when plugins live outside the webroot (404).
    // If static assets are served correctly on your instance, you may switch back to:
    //   $PLUGIN_HOOKS['add_javascript']['bitwardensend'] = ['js/bitwardensend.js'];
    //   $PLUGIN_HOOKS['add_css']['bitwardensend']        = ['css/bitwardensend.css'];

    Plugin::registerClass(Config::class, ['addtabon' => ['Config']]);
    Plugin::registerClass(Send::class, ['addtabon' => Send::getSupportedItemtypes()]);

    // Plugin rights are storable once registered in glpi_profilerights, but GLPI
    // does not render them: this tab provides the form.
    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);

    if (!Session::getLoginUserID()) {
        return;
    }

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['bitwardensend'] = 'front/config.form.php';
    }

    // Entry in the timeline's "answer" split button, next to Followup/Task/
    // Solution/... — rights, item type and new-item state are all checked
    // inside Send::getTimelineAnswerActions() itself, so the hook is simply
    // always registered here (an empty array is exactly "nothing to add").
    $PLUGIN_HOOKS['timeline_answer_actions']['bitwardensend'] = [Send::class, 'getTimelineAnswerActions'];
}

/**
 * Plugin description.
 */
function plugin_version_bitwardensend(): array
{
    return [
        'name'         => 'Bitwarden Send',
        'version'      => PLUGIN_BITWARDENSEND_VERSION,
        'author'       => '<a href="https://www.it-gouvernance.fr/">IT Gouvernance</a>',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://www.it-gouvernance.fr/',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BITWARDENSEND_MIN_GLPI,
                'max' => PLUGIN_BITWARDENSEND_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Technical prerequisites.
 */
function plugin_bitwardensend_check_prerequisites(): bool
{
    if (!function_exists('curl_init')) {
        echo 'The PHP curl extension is required.';
        return false;
    }
    return true;
}

/**
 * Configuration check.
 */
function plugin_bitwardensend_check_config($verbose = false): bool
{
    return true;
}