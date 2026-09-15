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
 * Renders exactly one GLPI followup template's content against a single
 * item, on demand - the technician picking a template in the Send creation
 * form's selector triggers this, instead of every visible template being
 * rendered eagerly whenever the form itself loads (see
 * Send::getFollowupTemplatesForItem() and Send::renderFollowupTemplateForItem()
 * for why that eager approach was a problem). Matches how GLPI's own
 * ajax/itilfollowup.php renders exactly one template, on demand, for a
 * plain followup.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Bitwardensend\Send;

Session::checkRight(Send::$rightname, CREATE);

$rawItemtype = $_GET['itemtype'] ?? '';
$itemtype    = is_string($rawItemtype) ? $rawItemtype : '';
$rawItemsId  = $_GET['items_id'] ?? 0;
$items_id    = is_numeric($rawItemsId) ? (int) $rawItemsId : 0;
$rawTemplateId = $_GET['template_id'] ?? 0;
$template_id   = is_numeric($rawTemplateId) ? (int) $rawTemplateId : 0;

// GLPI's kernel sets the response's HTTP status from the exception thrown
// (same convention as core's own ajax/itilfollowup.php) - not
// http_response_code(), which core's own static analysis forbids plugins
// and core code alike from calling directly, due to a PHP bug where it can
// silently fail to set the intended code (https://bugs.php.net/bug.php?id=81451).
if (!in_array($itemtype, Send::getSupportedItemtypes(), true) || $template_id <= 0) {
    throw new BadRequestHttpException(__('Invalid request.', 'bitwardensend'));
}

$item = getItemForItemtype($itemtype);
if (!($item instanceof CommonITILObject) || !$item->getFromDB($items_id) || !$item->canViewItem()) {
    throw new AccessDeniedHttpException(__('Item not found or access denied.', 'bitwardensend'));
}

// Same right/entity/is_active scoping as the list the template was picked
// from (Send::getFollowupTemplatesForItem()) - this cannot render a
// template the caller could not already see listed there.
$content = Send::renderFollowupTemplateForItem($item, $template_id);
if ($content === null) {
    throw new NotFoundHttpException(__('Template not found.', 'bitwardensend'));
}

// Set only once a JSON body is actually about to be sent: an exception
// thrown above is rendered by GLPI's kernel with its own Content-Type, and
// setting application/json before that would just mislabel that body.
header('Content-Type: application/json; charset=UTF-8');

$encoded = json_encode(['content' => $content], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    throw new RuntimeException(__('Could not encode the rendered template.', 'bitwardensend'));
}

echo $encoded;
