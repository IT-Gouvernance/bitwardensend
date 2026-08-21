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

use GlpiPlugin\Bitwardensend\Send;

Session::checkRight(Send::$rightname, READ);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Html::back();
}

if (isset($_POST['create_send'])) {
    Session::checkRight(Send::$rightname, CREATE);
    Send::createFromInput($_POST);
    Html::back();
}

if (isset($_POST['revoke'], $_POST['id'])) {
    Session::checkRight(Send::$rightname, UPDATE);
    $send = new Send();
    // checkRight() above only confirms the global right; canUpdateItem()
    // additionally checks this specific record's entity, so a user cannot
    // revoke a Send belonging to an entity they have no access to just by
    // guessing its id.
    if ($send->getFromDB((int) $_POST['id']) && $send->canUpdateItem()) {
        $send->revoke();
    }
    Html::back();
}

if (isset($_POST['purge'], $_POST['id'])) {
    Session::checkRight(Send::$rightname, PURGE);
    $send = new Send();
    if ($send->getFromDB((int) $_POST['id']) && $send->canPurgeItem()) {
        $send->delete(['id' => (int) $_POST['id']], true);
    }
    Html::back();
}

Html::back();
