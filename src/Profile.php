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

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Plugin rights tab on Administration > Profiles.
 *
 * GLPI never renders plugin rights on its own: registering a right in
 * glpi_profilerights makes it storable, not editable. This class provides the tab
 * and the form.
 */
class Profile extends \Profile
{
    public static $rightname = 'profile';

    /**
     * Inherited from \Profile, so late static binding would otherwise resolve the
     * table from this class name.
     */
    public static function getTable($classname = null): string
    {
        return 'glpi_profiles';
    }

    /**
     * Right bits exposed in the form, mapped to what they actually allow.
     *
     * Labels describe the effect rather than the CRUD verb: "Revoke Send links" is
     * more useful to whoever assigns the right than "Update".
     *
     * @return array<int,string>
     */
    public static function getRightDefinitions(): array
    {
        return [
            READ   => __('See the Bitwarden Sends tab', 'bitwardensend'),
            CREATE => __('Create Send links', 'bitwardensend'),
            UPDATE => __('Revoke Send links', 'bitwardensend'),
            PURGE  => __('Delete stored Send entries', 'bitwardensend'),
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && !$item->isNewItem() && Session::haveRight('profile', READ)
            && self::isCentralInterface($item)) {
            return self::createTabEntry(Send::getTypeName(0), 0, self::class, Send::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && !$item->isNewItem() && self::isCentralInterface($item)) {
            self::showForProfile((int) $item->getID());
        }
        return true;
    }

    /**
     * These rights only ever matter for the "central" interface: the plugin's own
     * tab and timeline action are never rendered under the simplified/self-service
     * ("helpdesk") interface, no matter what a profile is granted there — GLPI does
     * not surface plugin tabs on that interface. Showing this rights tab on a
     * helpdesk profile would just be a control with no visible effect once
     * assigned.
     */
    private static function isCentralInterface(\Profile $profile): bool
    {
        return ($profile->fields['interface'] ?? 'central') === 'central';
    }

    /**
     * Current bitmask stored for a profile.
     */
    public static function getRightValue(int $profiles_id): int
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => 'rights',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => Send::$rightname,
            ],
            'LIMIT'  => 1,
        ]);

        foreach ($iterator as $row) {
            return (int) $row['rights'];
        }

        return 0;
    }

    public static function showForProfile(int $profiles_id): void
    {
        $current = self::getRightValue($profiles_id);

        // Purely visual: ties each right to an icon that hints at its effect
        // (view / add / revoke / delete) without repeating the label.
        $icons = [
            READ   => 'ti-eye',
            CREATE => 'ti-plus',
            UPDATE => 'ti-ban',
            PURGE  => 'ti-trash',
        ];

        $rights = [];
        foreach (self::getRightDefinitions() as $bit => $label) {
            $rights[] = [
                'value'   => $bit,
                'label'   => $label,
                'icon'    => $icons[$bit] ?? 'ti-key',
                'checked' => ($current & $bit) === $bit,
            ];
        }

        TemplateRenderer::getInstance()->display('@bitwardensend/profile.html.twig', [
            'profiles_id'  => $profiles_id,
            'rights'       => $rights,
            // front/send.form.php always requires READ before checking the
            // action-specific right, so CREATE/UPDATE/PURGE are inert without it.
            'read_hint'    => __(
                'The rights below require this one: every action in the tab checks it first.',
                'bitwardensend'
            ),
            'can_update'   => Session::haveRight('profile', UPDATE),
        ]);
    }

    /**
     * Store the submitted rights.
     *
     * Writes the bitmask directly rather than going through the core profile form
     * conventions, so the field naming here is self-contained.
     *
     * @param array<string,mixed> $input
     */
    public static function saveFromInput(array $input): bool
    {
        global $DB;

        $profiles_id = (int) ($input['profiles_id'] ?? 0);
        if ($profiles_id <= 0) {
            Session::addMessageAfterRedirect(
                __('No profile selected.', 'bitwardensend'),
                false,
                ERROR
            );
            return false;
        }

        $submitted = is_array($input['rights'] ?? null) ? $input['rights'] : [];

        $value = 0;
        foreach (array_keys(self::getRightDefinitions()) as $bit) {
            if (!empty($submitted[$bit])) {
                $value |= (int) $bit;
            }
        }

        $where = [
            'profiles_id' => $profiles_id,
            'name'        => Send::$rightname,
        ];

        $exists = false;
        foreach ($DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_profilerights', 'WHERE' => $where]) as $row) {
            $exists = (int) $row['cpt'] > 0;
        }

        // A profile created after the plugin was installed has no row yet.
        $result = $exists
            ? $DB->update('glpi_profilerights', ['rights' => $value], $where)
            : $DB->insert('glpi_profilerights', $where + ['rights' => $value]);

        if (!$result) {
            Session::addMessageAfterRedirect(
                __('Could not update the rights.', 'bitwardensend'),
                false,
                ERROR
            );
            return false;
        }

        // Reflect the change immediately when editing one's own profile, otherwise
        // the session keeps the previous rights until the next profile switch.
        if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profiles_id) {
            $_SESSION['glpiactiveprofile'][Send::$rightname] = $value;
        }

        Session::addMessageAfterRedirect(__('Rights updated.', 'bitwardensend'), true, INFO);

        return true;
    }
}
