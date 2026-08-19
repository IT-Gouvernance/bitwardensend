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

/**
 * Picks the active SendDriverInterface implementation from plugin
 * configuration. The only place in the plugin that should know the concrete
 * driver classes exist — everything else (Send.php, front/config.form.php)
 * talks to whatever this returns through the interface alone.
 */
final class SendDriverFactory
{
    /**
     * @param array<string,mixed>|null $conf Defaults to Config::getConfig()
     *     when omitted — callers that already have it at hand (e.g. inside a
     *     loop) can pass it directly instead of hitting the cache lookup
     *     again.
     */
    public static function create(?array $conf = null): SendDriverInterface
    {
        $conf ??= Config::getConfig();

        // 'cli' is the default: existing installs upgrading to this version
        // have no 'send_driver' column value yet, and must keep behaving
        // exactly as before until someone deliberately opts into 'native'.
        return ($conf['send_driver'] ?? 'cli') === 'native'
            ? new NativeSendDriver($conf)
            : new CliSendDriver($conf);
    }
}
