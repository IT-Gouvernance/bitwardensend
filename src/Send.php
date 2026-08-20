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

use CommonDBTM;
use CommonGLPI;
use CommonITILObject;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use Html;
use ITILFollowup;
use Session;
use Toolbox;

/**
 * A Bitwarden Send link attached to an ITIL object.
 */
class Send extends CommonDBTM
{
    public static $rightname = 'plugin_bitwardensend_send';

    public $dohistory = false;

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_bitwardensend_sends';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Bitwarden Send', 'Bitwarden Sends', $nb, 'bitwardensend');
    }

    public static function getIcon(): string
    {
        return 'ti ti-shield-lock';
    }

    /**
     * Supported ITIL itemtypes.
     *
     * @return list<string>
     */
    public static function getSupportedItemtypes(): array
    {
        return ['Ticket', 'Change', 'Problem'];
    }

    public static function getDefaultFollowupTemplate(): string
    {
        // Translated so that a fresh install stores the template in the language
        // of the user running the installation.
        return __(
            "Hello,\n\n"
            . "Here is a secure link to retrieve the confidential information for this request:\n\n"
            . "{url}\n\n"
            . "The link expires on {expiration} and can be opened {max_access} time(s). "
            . "Once viewed, the content is no longer available.\n\n"
            . "Kind regards,",
            'bitwardensend'
        );
    }

    /**
     * GLPI's own followup templates ("gabarits de suivi", Setup > Templates),
     * offered in the creation form as an alternative to this plugin's own
     * configured template. Selecting one only replaces the text shown in the
     * editor: the {url}, {expiration} and {max_access} substitution in
     * addFollowup() runs on whatever ends up in that field regardless of where
     * it came from, so a GLPI template using those same placeholders works
     * exactly like the plugin's own default.
     *
     * Returns [] when the class does not exist (older GLPI without followup
     * templates), the current user lacks read rights on it, or none are visible
     * from the item's entity — the selector is then simply omitted.
     *
     * @return list<array{id:int,name:string,content:string}>
     */
    public static function getFollowupTemplatesForItem(CommonITILObject $item): array
    {
        global $DB;

        // Wrapped end to end: this selector is a convenience, never a
        // requirement, so any schema/API difference across GLPI versions
        // must degrade to "no GLPI templates offered", not break the Send
        // creation dialog itself.
        try {
            if (!class_exists(\ITILFollowupTemplate::class)) {
                return [];
            }

            // Matches GLPI's usual convention for these dropdown classes
            // (same as TaskTemplate/SolutionTemplate); falls back to the same
            // string if the property itself is not declared on this version.
            $rightname = property_exists(\ITILFollowupTemplate::class, 'rightname')
                ? \ITILFollowupTemplate::$rightname
                : 'itilfollowuptemplate';
            if (!Session::haveRight($rightname, READ)) {
                return [];
            }

            $table = \ITILFollowupTemplate::getTable();
            if (!$DB->tableExists($table)) {
                return [];
            }

            $where = [];
            if ($DB->fieldExists($table, 'is_active')) {
                $where['is_active'] = 1;
            }
            if ($DB->fieldExists($table, 'entities_id')) {
                // A plain match on the item's own entity, deliberately not
                // reproducing GLPI's own recursive-entity resolution: simpler,
                // and this is a convenience picker gated behind its own read
                // right already, not an isolation boundary.
                $where['entities_id'] = (int) ($item->fields['entities_id'] ?? 0);
            }

            $templates = [];
            $iterator = $DB->request([
                'FROM'  => $table,
                'WHERE' => $where,
                'ORDER' => 'name ASC',
            ]);

            foreach ($iterator as $row) {
                $templates[] = [
                    'id'      => (int) ($row['id'] ?? 0),
                    'name'    => (string) ($row['name'] ?? ''),
                    'content' => (string) ($row['content'] ?? ''),
                ];
            }

            return $templates;
        } catch (\Throwable $e) {
            Toolbox::logDebug('[bitwardensend] ' . $e->getMessage());
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Timeline answer action (Setup > Plugins hook: timeline_answer_actions)
    // ------------------------------------------------------------------

    /**
     * Entry added to GLPI's "answer" split dropdown/button row (next to
     * Followup/Task/Solution/...), per the modern (GLPI >= 10.0)
     * Hooks::TIMELINE_ANSWER_ACTIONS contract — superseding the pre-10.0
     * Hooks::TIMELINE_ACTIONS this plugin used to register: that one renders
     * into a separate, visually disconnected "legacy" toolbar rather than
     * alongside the other answer buttons.
     *
     * All the gating that used to live in setup.php (rights, item type,
     * new-item state) is done here instead, so the hook itself can just
     * always be registered: returning [] is exactly what "nothing to show"
     * means for this contract.
     *
     * The returned 'item' does not need to be a real GLPI entity — it is
     * only ever handed back to our own '@bitwardensend/answer_form.html.twig'
     * as the 'subitem' variable (never through GLPI's generic showForm()
     * fallback, since a 'template' is always provided) — so it doubles as a
     * plain data carrier for everything that template needs.
     *
     * @param array<string,mixed> $params
     * @return array<string,array<string,mixed>>
     */
    public static function getTimelineAnswerActions($params = []): array
    {
        $item = is_array($params) ? ($params['item'] ?? null) : null;

        if (!($item instanceof CommonITILObject) || $item->isNewItem()) {
            return [];
        }
        if (!in_array($item->getType(), self::getSupportedItemtypes(), true)) {
            return [];
        }
        if (!self::canCreate()) {
            return [];
        }

        // A plain object, not a real GLPI entity: this is only ever handed
        // back to answer_form.html.twig as 'subitem' (see the class-level
        // doc above), so it doubles as a carrier for that data.
        $subitem = (object) self::buildFormContext($item, true);

        return [
            // Key must stay stable across releases: GLPI uses it to key the
            // dropdown entry.
            'PluginBitwardensendSend' => [
                // Groups/styles like a regular followup once posted — which is
                // exactly what this ends up creating, link aside.
                'type'         => 'ITILFollowup',
                // A plain, backslash-free token: this becomes an HTML id
                // ("new-{class}-block") and a jQuery/Bootstrap selector
                // target, where the namespaced PHP class name would not be
                // safe to use unescaped.
                'class'        => 'PluginBitwardensendSend',
                'icon'         => self::getIcon(),
                'label'        => self::getTypeName(1),
                'short_label'  => self::getTypeName(1),
                'template'     => '@bitwardensend/answer_form.html.twig',
                'item'         => $subitem,
                'hide_in_menu' => false,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Tab on ITIL objects
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!in_array($item->getType(), self::getSupportedItemtypes(), true) || !self::canView()) {
            return '';
        }

        $count = 0;
        if (!empty($_SESSION['glpishow_count_on_tabs'])) {
            $count = countElementsInTable(self::getTable(), [
                'itemtype' => $item->getType(),
                'items_id' => $item->getID(),
            ]);
        }

        return self::createTabEntry(self::getTypeName(0), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonITILObject) {
            self::showForItem($item);
        }
        return true;
    }

    public static function showForItem(CommonITILObject $item): void
    {
        global $DB;

        // Bitwarden deletes the Send itself once past this date; nothing here
        // marks the GLPI row as revoked when that happens, so it is recomputed
        // at display time instead of being stored.
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $sends = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => $item->getType(),
                'items_id' => $item->getID(),
            ],
            'ORDER' => 'date_creation DESC',
        ]);

        foreach ($iterator as $row) {
            $row['user_name']             = $row['users_id'] ? getUserName($row['users_id']) : '';
            $row['date_creation_display'] = $row['date_creation'] ? Html::convDateTime($row['date_creation']) : '';
            $row['deletion_date_display'] = $row['deletion_date'] ? Html::convDateTime($row['deletion_date']) : '';
            $row['is_expired']            = !$row['is_revoked'] && $row['deletion_date'] !== null
                                             && $row['deletion_date'] < $now;
            $sends[] = $row;
        }

        TemplateRenderer::getInstance()->display('@bitwardensend/list.html.twig', [
            'item'       => $item,
            'sends'      => $sends,
            'can_create' => self::canCreate(),
            'can_update' => self::canUpdate(),
            'can_purge'  => self::canPurge(),
            'csrf_token' => Session::getNewCSRFToken(),
        ]);
    }

    // ------------------------------------------------------------------
    // Creation form (modal, opened from the "Bitwarden Sends" tab's own
    // "New Bitwarden Send" button — see ajax/form.php)
    // ------------------------------------------------------------------

    public static function showCreateForm(CommonITILObject $item): void
    {
        TemplateRenderer::getInstance()->display('@bitwardensend/form.html.twig', self::buildFormContext($item));
    }

    /**
     * Everything the creation form needs, regardless of which of its two
     * entry points is rendering it (the tab's own modal, via showCreateForm()
     * above, or the timeline's inline answer action, via
     * getTimelineAnswerActions()).
     *
     * @param bool $forceFollowup True for the timeline's inline form, where
     *     posting a followup is the whole point of using that area rather
     *     than a toggle — see _send_form_fields.html.twig. False (the tab's
     *     own modal) keeps that toggle, defaulting to the configured value.
     *
     * @return array{
     *     itemtype: string,
     *     items_id: int,
     *     default_name: string,
     *     conf: array<string,mixed>,
     *     followup_templates: list<array{id:int,name:string,content:string}>,
     *     csrf_token: string,
     *     force_followup: bool
     * }
     */
    private static function buildFormContext(CommonITILObject $item, bool $forceFollowup = false): array
    {
        $conf = Config::getConfig();

        // The template is stored as plain text (edited in a plain textarea on
        // the config page), but seeds a rich text (TinyMCE) editor here: as
        // HTML, a bare "\n" is not a line break, so the default's blank lines
        // between paragraphs would otherwise collapse into a single run-on
        // paragraph the first time this form renders.
        $conf['followup_template'] = nl2br(htmlspecialchars((string) $conf['followup_template']));

        return [
            'itemtype'           => $item->getType(),
            'items_id'           => $item->getID(),
            'default_name'       => sprintf('%s #%d', $item->getTypeName(1), $item->getID()),
            'conf'               => $conf,
            'force_followup'     => $forceFollowup,
            'followup_templates' => !empty($conf['allow_glpi_followup_templates'])
                ? self::getFollowupTemplatesForItem($item)
                : [],
            'csrf_token'         => Session::getNewCSRFToken(),
        ];
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * Create the Bitwarden Send, store it and optionally post a followup.
     *
     * @param array<string,mixed> $input
     */
    public static function createFromInput(array $input): bool
    {
        $itemtype = (string) ($input['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? 0);

        if (!in_array($itemtype, self::getSupportedItemtypes(), true)) {
            Session::addMessageAfterRedirect(
                __('Unsupported item type.', 'bitwardensend'),
                false,
                ERROR
            );
            return false;
        }

        $item = getItemForItemtype($itemtype);
        if (!$item || !$item->getFromDB($items_id) || !$item->canViewItem()) {
            Session::addMessageAfterRedirect(
                __('Item not found or access denied.', 'bitwardensend'),
                false,
                ERROR
            );
            return false;
        }

        $secret = (string) ($input['secret'] ?? '');
        if (trim($secret) === '') {
            Session::addMessageAfterRedirect(
                __('The content to share is empty.', 'bitwardensend'),
                false,
                ERROR
            );
            return false;
        }

        $conf = Config::getConfig();

        $days = (int) ($input['deletion_days'] ?? 0);
        if ($days <= 0) {
            $days = (int) $conf['default_deletion_days'];
        }
        $days = max(1, min(31, $days));

        $expiration_ts = strtotime('+' . $days . ' days');
        $deletion_date = gmdate('Y-m-d\TH:i:s.000\Z', $expiration_ts);
        $max_access    = max(0, (int) ($input['max_access_count'] ?? 0));
        $password      = (string) ($input['password'] ?? '');

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = sprintf('%s #%d', $item->getTypeName(1), $items_id);
        }

        try {
            $result = SendDriverFactory::create($conf)->createSend(new SendPayload(
                name: $name,
                text: $secret,
                notes: sprintf('GLPI %s #%d', $itemtype, $items_id),
                hidden: !empty($input['hidden']),
                maxAccessCount: $max_access,
                deletionDate: $deletion_date,
                password: $password,
                hideEmail: !empty($input['hide_email']),
            ));
        } catch (\Throwable $e) {
            Toolbox::logDebug('[bitwardensend] ' . $e->getMessage());
            Session::addMessageAfterRedirect(
                sprintf(__('Could not create the Send: %s', 'bitwardensend'), $e->getMessage()),
                false,
                ERROR
            );
            return false;
        }

        $send = new self();
        $send->add([
            'name'                  => $name,
            'itemtype'              => $itemtype,
            'items_id'              => $items_id,
            'users_id'              => (int) Session::getLoginUserID(),
            'entities_id'           => (int) ($item->fields['entities_id'] ?? 0),
            'send_uuid'             => $result->uuid,
            'access_id'             => $result->accessId,
            'access_url'            => !empty($conf['store_access_url']) ? $result->accessUrl : null,
            'deletion_date'         => date('Y-m-d H:i:s', $expiration_ts),
            'max_access_count'      => $max_access > 0 ? $max_access : null,
            'is_password_protected' => $password !== '' ? 1 : 0,
            'date_creation'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        if (!empty($input['add_followup'])) {
            self::addFollowup(
                $item,
                (string) ($input['followup_content'] ?? ''),
                $result->accessUrl,
                $expiration_ts,
                $max_access,
                !empty($input['followup_is_private'])
            );
        }

        Session::addMessageAfterRedirect(
            sprintf(
                __('Bitwarden Send link created: %s', 'bitwardensend'),
                '<a href="' . htmlspecialchars($result->accessUrl, ENT_QUOTES) . '" target="_blank" rel="noopener">'
                . htmlspecialchars($result->accessUrl, ENT_QUOTES) . '</a>'
            ),
            false,
            INFO
        );

        return true;
    }

    /**
     * Post the link as a followup of the ITIL object.
     */
    private static function addFollowup(
        CommonITILObject $item,
        string $template,
        string $url,
        int $expiration_ts,
        int $max_access,
        bool $is_private
    ): void {
        if (trim($template) === '') {
            $template = Config::getConfig()['followup_template'];
        }

        $escaped_url = htmlspecialchars($url, ENT_QUOTES);
        $link        = '<a href="' . $escaped_url . '" target="_blank" rel="noopener">' . $escaped_url . '</a>';

        $content = str_replace(
            [
                // GLPI's own rich text editor rewrites any href="..." that does not
                // already look like a valid absolute URL, prefixing it with the GLPI
                // base URL — so a bare {url_raw} typed straight into an href on a
                // GLPI followup template gets corrupted the moment that template is
                // saved, before this plugin ever sees it. Wrapping it in an already-
                // absolute (fake, reserved-TLD) URL gives the editor nothing to "fix".
                // Must run before the bare '{url_raw}' replacement below, since that
                // would otherwise consume the token this one still needs to match.
                'https://bitwardensend.invalid/{url_raw}',
                '{url}',
                '{url_raw}',
                '{expiration}',
                '{max_access}',
            ],
            [
                $escaped_url,
                // {url} is a whole clickable link: safe on its own in the text, but
                // breaks the markup if placed inside an href="..." — {url_raw} is the
                // bare, attribute-safe URL for exactly that case (a custom link,
                // e.g. from a GLPI followup template with its own wording).
                $link,
                $escaped_url,
                Html::convDateTime(date('Y-m-d H:i:s', $expiration_ts)),
                $max_access > 0 ? (string) $max_access : __('an unlimited number of', 'bitwardensend'),
            ],
            $template
        );

        // Stored as HTML, like a followup typed in the rich text editor.
        $content = nl2br($content);

        $followup = new ITILFollowup();
        $created  = $followup->add([
            'itemtype'   => $item->getType(),
            'items_id'   => $item->getID(),
            'content'    => $content,
            'is_private' => $is_private ? 1 : 0,
            'users_id'   => (int) Session::getLoginUserID(),
        ]);

        if (!$created) {
            Session::addMessageAfterRedirect(
                __('The Send was created but the followup could not be added.', 'bitwardensend'),
                false,
                WARNING
            );
        }
    }

    // ------------------------------------------------------------------
    // Revocation
    // ------------------------------------------------------------------

    /**
     * Delete the link on the Bitwarden side, then flag the row as revoked.
     */
    public function revoke(): bool
    {
        try {
            SendDriverFactory::create()->deleteSend((string) $this->fields['send_uuid']);
        } catch (\Throwable $e) {
            Toolbox::logDebug('[bitwardensend] ' . $e->getMessage());
            Session::addMessageAfterRedirect(
                sprintf(__('Could not revoke the link: %s', 'bitwardensend'), $e->getMessage()),
                false,
                ERROR
            );
            return false;
        }

        $this->update([
            'id'         => $this->fields['id'],
            'is_revoked' => 1,
            'access_url' => null,
        ]);

        Session::addMessageAfterRedirect(__('Link revoked.', 'bitwardensend'), false, INFO);

        return true;
    }

    // ------------------------------------------------------------------
    // Automatic action (Setup > Automatic actions)
    // ------------------------------------------------------------------

    public static function cronInfo(string $name): array
    {
        if ($name === 'cleanup') {
            return [
                'description' => __(
                    'Delete revoked or expired Bitwarden Send entries past the configured retention',
                    'bitwardensend'
                ),
                'parameter'   => __('Retention (days)', 'bitwardensend'),
            ];
        }
        return [];
    }

    /**
     * URL of this action's edit form (Setup > Automatic actions), where its
     * retention parameter and frequency are actually configured. Null if the
     * task row does not exist yet (plugin not installed/updated).
     */
    public static function getCleanupCronUrl(): ?string
    {
        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, 'cleanup')) {
            return CronTask::getFormURLWithID((int) $task->fields['id']);
        }
        return null;
    }

    /**
     * Purge GLPI-side rows once revoked or expired for longer than the
     * retention configured on this automatic action (Setup > Automatic
     * actions > Bitwarden Send, same place as its frequency). The Bitwarden
     * Send itself is already gone by then (deleted on revoke, or
     * self-deleted by Bitwarden past its expiration) — this only cleans up
     * the local record.
     */
    public static function cronCleanup(?CronTask $task = null): int
    {
        global $DB;

        $days = $task !== null ? (int) ($task->fields['param'] ?? 0) : 0;
        if ($days <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'OR' => [
                    // Revoked: retention counted from the revoke date (date_mod).
                    ['is_revoked' => 1, 'date_mod' => ['<', $cutoff]],
                    // Never revoked but past its own expiration: counted from deletion_date.
                    ['is_revoked' => 0, 'deletion_date' => ['<', $cutoff]],
                ],
            ],
            // Defensive cap: a daily run catches up incrementally rather than
            // ever deleting an unbounded batch in one pass.
            'LIMIT' => 500,
        ]);

        $count = 0;
        foreach ($iterator as $row) {
            $send = new self();
            if ($send->delete(['id' => $row['id']], true)) {
                $count++;
            }
        }

        if ($task !== null) {
            $task->addVolume($count);
        }

        return $count > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    public function rawSearchOptions()
    {
        $options = [];

        $options[] = [
            'id'   => 'common',
            'name' => self::getTypeName(0),
        ];

        $options[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
        ];

        $options[] = [
            'id'       => 3,
            'table'    => self::getTable(),
            'field'    => 'itemtype',
            'name'     => __('Item type'),
            'datatype' => 'itemtypename',
        ];

        $options[] = [
            'id'       => 4,
            'table'    => self::getTable(),
            'field'    => 'items_id',
            'name'     => __('Item ID'),
            'datatype' => 'number',
        ];

        $options[] = [
            'id'       => 5,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Created by', 'bitwardensend'),
            'datatype' => 'dropdown',
        ];

        $options[] = [
            'id'       => 6,
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Creation date'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => 7,
            'table'    => self::getTable(),
            'field'    => 'deletion_date',
            'name'     => __('Expiration', 'bitwardensend'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => 8,
            'table'    => self::getTable(),
            'field'    => 'is_revoked',
            'name'     => __('Revoked', 'bitwardensend'),
            'datatype' => 'bool',
        ];

        return $options;
    }
}
