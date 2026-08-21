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

use GlpiPlugin\Bitwardensend\Config;
use GlpiPlugin\Bitwardensend\Send;

/**
 * Plugin installation.
 */
function plugin_bitwardensend_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    $config_table = Config::getTable();
    if (!$DB->tableExists($config_table)) {
        $query = "CREATE TABLE `{$config_table}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `api_url` varchar(255) NOT NULL DEFAULT 'http://127.0.0.1:8087',
            `send_base_url` varchar(255) NOT NULL DEFAULT 'https://send.bitwarden.com/#',
            `master_password` text,
            `timeout` int NOT NULL DEFAULT 15,
            `default_deletion_days` int NOT NULL DEFAULT 1,
            `default_max_access_count` int NOT NULL DEFAULT 0,
            `default_hide_email` tinyint NOT NULL DEFAULT 0,
            `add_followup` tinyint NOT NULL DEFAULT 1,
            `followup_is_private` tinyint NOT NULL DEFAULT 0,
            `store_access_url` tinyint NOT NULL DEFAULT 0,
            `followup_template` text,
            `allow_glpi_followup_templates` tinyint NOT NULL DEFAULT 1,
            `password_generator_enabled` tinyint NOT NULL DEFAULT 1,
            `send_driver` varchar(10) NOT NULL DEFAULT 'cli',
            `native_identity_url` varchar(255) NOT NULL DEFAULT 'https://identity.bitwarden.com',
            `native_api_url` varchar(255) NOT NULL DEFAULT 'https://api.bitwarden.com',
            `native_web_vault_url` varchar(255) NOT NULL DEFAULT 'https://vault.bitwarden.com',
            `native_client_id` varchar(255) NOT NULL DEFAULT '',
            `native_email` varchar(255) NOT NULL DEFAULT '',
            `native_client_secret` text,
            `native_master_password` text,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    $sends_table = Send::getTable();
    if (!$DB->tableExists($sends_table)) {
        $query = "CREATE TABLE `{$sends_table}` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$sign} NOT NULL DEFAULT 0,
            `users_id` int {$sign} NOT NULL DEFAULT 0,
            `entities_id` int {$sign} NOT NULL DEFAULT 0,
            `send_uuid` varchar(255) DEFAULT NULL,
            `access_id` varchar(255) DEFAULT NULL,
            `access_url` text,
            `deletion_date` timestamp NULL DEFAULT NULL,
            `max_access_count` int DEFAULT NULL,
            `is_password_protected` tinyint NOT NULL DEFAULT 0,
            `is_revoked` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `item` (`itemtype`,`items_id`),
            KEY `users_id` (`users_id`),
            KEY `entities_id` (`entities_id`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    // GLPI calls this same function again on update, so nothing below can be
    // a plain unguarded INSERT.

    // The single config row, created once — never touched again so an admin's
    // edited followup template isn't reset by a later update.
    if (plugin_bitwardensend_countRows($config_table) === 0) {
        $DB->insert($config_table, [
            'id'                => 1,
            'followup_template' => Send::getDefaultFollowupTemplate(),
            'date_mod'          => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    // addProfileRights() has no "already exists" check of its own, hence the guard.
    if (plugin_bitwardensend_countRows('glpi_profilerights', ['name' => Send::$rightname]) === 0) {
        ProfileRight::addProfileRights([Send::$rightname]);
    }

    // Give the installing profile full rights right away.
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $DB->update(
            'glpi_profilerights',
            ['rights' => ALLSTANDARDRIGHT],
            [
                'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                'name'        => Send::$rightname,
            ]
        );
        $_SESSION['glpiactiveprofile'][Send::$rightname] = ALLSTANDARDRIGHT;
    }

    CronTask::register(Send::class, 'cleanup', DAY_TIMESTAMP, [
        'comment' => __(
            'Delete revoked or expired Bitwarden Send entries past the configured retention',
            'bitwardensend'
        ),
        'mode'    => CronTask::MODE_INTERNAL,
        'param'   => 30,
    ]);

    return true;
}

/**
 * @param array<string,mixed> $where
 */
function plugin_bitwardensend_countRows(string $table, array $where = []): int
{
    global $DB;

    if (!$DB->tableExists($table)) {
        return 0;
    }

    $criteria = ['COUNT' => 'cpt', 'FROM' => $table];
    if ($where !== []) {
        $criteria['WHERE'] = $where;
    }

    foreach ($DB->request($criteria) as $row) {
        return (int) $row['cpt'];
    }

    return 0;
}

/**
 * Plugin uninstallation.
 */
function plugin_bitwardensend_uninstall(): bool
{
    global $DB;

    foreach ([Send::getTable(), Config::getTable()] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery(sprintf('DROP TABLE `%s`', $table));
        }
    }

    ProfileRight::deleteProfileRights([Send::$rightname]);
    CronTask::unregister('Bitwardensend');

    $DB->delete('glpi_displaypreferences', ['itemtype' => Send::class]);
    $DB->delete('glpi_logs', ['itemtype' => Send::class]);

    return true;
}
