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

use GlpiPlugin\Bitwardensend\CliSendDriver;
use PHPUnit\Framework\TestCase;

/**
 * Only isAvailable(): a pure config check, no GLPI dependency as long as
 * $conf is passed explicitly to the constructor (omitting it falls back to
 * Config::getConfig(), which needs a real GLPI bootstrap). Every other
 * method talks to the local Vault Management API over HTTP, out of scope
 * for this standalone suite.
 *
 * @covers \GlpiPlugin\Bitwardensend\CliSendDriver
 */
final class CliSendDriverTest extends TestCase
{
    public function testIsAvailableWithApiUrlConfigured(): void
    {
        $driver = new CliSendDriver(['api_url' => 'http://127.0.0.1:8087']);
        self::assertTrue($driver->isAvailable());
    }

    public function testIsAvailableWithoutApiUrlConfigured(): void
    {
        $driver = new CliSendDriver([]);
        self::assertFalse($driver->isAvailable());
    }

    public function testIsAvailableWithBlankApiUrl(): void
    {
        $driver = new CliSendDriver(['api_url' => '   ']);
        self::assertFalse($driver->isAvailable());
    }

    /**
     * A non-string value for 'api_url' (e.g. from a corrupted config row)
     * must not be treated as available just because it is truthy.
     */
    public function testIsAvailableWithNonStringApiUrl(): void
    {
        $driver = new CliSendDriver(['api_url' => 12345]);
        self::assertFalse($driver->isAvailable());
    }
}
