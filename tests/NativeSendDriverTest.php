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

use GlpiPlugin\Bitwardensend\NativeSendDriver;
use PHPUnit\Framework\TestCase;

/**
 * Only isAvailable(): a pure config check, no GLPI dependency as long as
 * $conf/$clientSecret/$masterPassword are all passed explicitly to the
 * constructor (omitting any of them falls back to Config's own getters,
 * which need a real GLPI bootstrap). Every other method talks to the
 * Bitwarden API over HTTP, out of scope for this standalone suite.
 *
 * Assumes the openssl extension is loaded, same as the rest of this test
 * suite (tests.yml enables it) — isAvailable() returns false outright
 * without it, regardless of configuration.
 *
 * @covers \GlpiPlugin\Bitwardensend\NativeSendDriver
 */
final class NativeSendDriverTest extends TestCase
{
    /**
     * @return array<string,string>
     */
    private function completeConf(): array
    {
        return [
            'native_identity_url'  => 'https://identity.bitwarden.com',
            'native_api_url'       => 'https://api.bitwarden.com',
            'native_web_vault_url' => 'https://vault.bitwarden.com',
            'native_client_id'     => 'client-id',
            'native_email'         => 'user@example.com',
        ];
    }

    public function testIsAvailableWithEverythingConfigured(): void
    {
        $driver = new NativeSendDriver($this->completeConf(), 'client-secret', 'master-password');
        self::assertTrue($driver->isAvailable());
    }

    public function testIsAvailableWithNoConfigAtAll(): void
    {
        $driver = new NativeSendDriver([], '', '');
        self::assertFalse($driver->isAvailable());
    }

    /**
     * @dataProvider missingRequiredField
     */
    public function testIsAvailableWithOneRequiredFieldMissing(string $field): void
    {
        $conf = $this->completeConf();
        unset($conf[$field]);

        $driver = new NativeSendDriver($conf, 'client-secret', 'master-password');
        self::assertFalse($driver->isAvailable());
    }

    /**
     * @return list<array{0:string}>
     */
    public static function missingRequiredField(): array
    {
        return [
            ['native_identity_url'],
            ['native_api_url'],
            ['native_web_vault_url'],
            ['native_client_id'],
            ['native_email'],
        ];
    }

    public function testIsAvailableWithoutClientSecret(): void
    {
        $driver = new NativeSendDriver($this->completeConf(), '', 'master-password');
        self::assertFalse($driver->isAvailable());
    }

    public function testIsAvailableWithoutMasterPassword(): void
    {
        $driver = new NativeSendDriver($this->completeConf(), 'client-secret', '');
        self::assertFalse($driver->isAvailable());
    }
}
