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
 * Everything a SendDriverInterface::createSend() needs, independent of which
 * driver ends up handling it (CLI or native). Immutable: built once from the
 * creation form's input, then handed as-is to whichever driver is active.
 */
final class SendPayload
{
    public function __construct(
        public readonly string $name,
        public readonly string $text,
        public readonly ?string $notes = null,
        public readonly bool $hidden = true,
        public readonly ?int $maxAccessCount = null,
        public readonly ?string $deletionDate = null,
        public readonly ?string $expirationDate = null,
        public readonly ?string $password = null,
        public readonly bool $hideEmail = false,
        public readonly bool $disabled = false,
    ) {
    }
}
