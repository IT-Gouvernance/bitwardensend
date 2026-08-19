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

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight(Send::$rightname, CREATE);

header('Content-Type: text/html; charset=UTF-8');

$itemtype = $_REQUEST['itemtype'] ?? '';
$items_id = (int) ($_REQUEST['items_id'] ?? 0);

if (!in_array($itemtype, Send::getSupportedItemtypes(), true)) {
    echo '<div class="alert alert-danger">' . __('Unsupported item type.', 'bitwardensend') . '</div>';
    return;
}

$item = getItemForItemtype($itemtype);
if (!$item || !$item->getFromDB($items_id) || !$item->canViewItem()) {
    echo '<div class="alert alert-danger">' . __('Item not found or access denied.', 'bitwardensend') . '</div>';
    return;
}

Send::showCreateForm($item);
