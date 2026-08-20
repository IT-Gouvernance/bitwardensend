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
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Plugin configuration (a single row, id = 1).
 */
class Config extends CommonDBTM
{
    public static $rightname = 'config';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_bitwardensend_configs';
    }

    public static function getTypeName($nb = 0)
    {
        // Delegated so that both classes resolve through the same catalog entry.
        return Send::getTypeName(1);
    }

    public static function getIcon(): string
    {
        return 'ti ti-shield-lock';
    }

    /**
     * Current configuration, with fallback values.
     *
     * @return array<string,mixed>
     */
    public static function getConfig(bool $reload = false): array
    {
        global $DB;

        if (self::$cache !== null && !$reload) {
            return self::$cache;
        }

        $defaults = [
            'backend'                        => 'serve',
            'api_url'                        => 'http://127.0.0.1:8087',
            'cli_path'                       => '/usr/local/bin/bw',
            'cli_appdata_dir'                => '/var/lib/bitwarden-cli',
            'cli_session'                    => '',
            'send_base_url'                  => 'https://send.bitwarden.com/#',
            'master_password'                => '',
            'timeout'                        => 15,
            'default_deletion_days'          => 7,
            'default_max_access_count'       => 1,
            'default_hide_email'             => 0,
            'add_followup'                   => 1,
            'followup_is_private'            => 0,
            'store_access_url'               => 0,
            'followup_template'              => Send::getDefaultFollowupTemplate(),
            'allow_glpi_followup_templates'  => 1,
            'password_generator_enabled'     => 1,
            'send_driver'                    => 'cli',
            'native_identity_url'            => 'https://identity.bitwarden.com',
            'native_api_url'                 => 'https://api.bitwarden.com',
            'native_web_vault_url'           => 'https://vault.bitwarden.com',
            'native_client_id'               => '',
            'native_email'                   => '',
            'native_client_secret'           => '',
            'native_master_password'         => '',
        ];

        $row = [];
        if ($DB->tableExists(self::getTable())) {
            $iterator = $DB->request(['FROM' => self::getTable(), 'LIMIT' => 1]);
            foreach ($iterator as $data) {
                $row = $data;
            }
        }

        foreach ($row as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $defaults[$key] = $value;
        }

        self::$cache = $defaults;
        return self::$cache;
    }

    /**
     * Decrypted master password (empty when not set).
     */
    public static function getMasterPassword(): string
    {
        return self::decrypt((string) (self::getConfig()['master_password'] ?? ''));
    }

    /**
     * Decrypted BW_SESSION value (CLI mode only).
     */
    public static function getCliSession(): string
    {
        return self::decrypt((string) (self::getConfig()['cli_session'] ?? ''));
    }

    /**
     * Decrypted API client secret for the native driver's service account.
     */
    public static function getNativeClientSecret(): string
    {
        return self::decrypt((string) (self::getConfig()['native_client_secret'] ?? ''));
    }

    /**
     * Decrypted master password for the native driver's own service account
     * (separate from master_password above, which is for the CLI driver).
     */
    public static function getNativeMasterPassword(): string
    {
        return self::decrypt((string) (self::getConfig()['native_master_password'] ?? ''));
    }

    private static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return (string) (new \GLPIKey())->decrypt($value);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return (string) (new \GLPIKey())->encrypt($value);
    }

    /**
     * Checks the fields the selected driver/mode actually needs. Mirrors
     * NativeSendDriver/CliSendDriver's own runtime checks by hand.
     *
     * @param array<string,mixed> $input
     * @return list<string> labels of the missing fields
     */
    public static function validateInput(array $input): array
    {
        $errors = [];

        $driver = in_array($input['send_driver'] ?? 'cli', ['cli', 'native'], true)
            ? $input['send_driver'] : 'cli';

        if ($driver === 'cli') {
            $backend = in_array($input['backend'] ?? 'serve', ['serve', 'cli'], true)
                ? $input['backend'] : 'serve';

            if ($backend === 'serve' && trim((string) ($input['api_url'] ?? '')) === '') {
                $errors[] = __('Local API URL', 'bitwardensend');
            }
            if ($backend === 'cli' && trim((string) ($input['cli_path'] ?? '')) === '') {
                $errors[] = __('Path to the bw binary', 'bitwardensend');
            }
            return $errors;
        }

        $requiredNative = [
            'native_identity_url'  => __('Identity URL', 'bitwardensend'),
            'native_api_url'       => __('API URL', 'bitwardensend'),
            'native_web_vault_url' => __('Web vault URL', 'bitwardensend'),
            'native_client_id'     => __('API client ID', 'bitwardensend'),
            'native_email'         => __('Account email', 'bitwardensend'),
        ];
        foreach ($requiredNative as $field => $label) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                $errors[] = $label;
            }
        }

        $hasClientSecret = self::getNativeClientSecret() !== '' && empty($input['clear_native_client_secret']);
        if (trim((string) ($input['native_client_secret'] ?? '')) === '' && !$hasClientSecret) {
            $errors[] = __('API client secret', 'bitwardensend');
        }

        $hasMasterPassword = self::getNativeMasterPassword() !== '' && empty($input['clear_native_master_password']);
        if (trim((string) ($input['native_master_password'] ?? '')) === '' && !$hasMasterPassword) {
            $errors[] = __('Master password', 'bitwardensend');
        }

        return $errors;
    }

    /**
     * Save the configuration coming from the form.
     *
     * @param array<string,mixed> $input
     */
    public static function saveFromInput(array $input): bool
    {
        global $DB;

        $fields = [
            'backend'                       => in_array($input['backend'] ?? 'serve', ['serve', 'cli'], true)
                                                 ? $input['backend'] : 'serve',
            'api_url'                       => rtrim(trim((string) ($input['api_url'] ?? '')), '/'),
            'cli_path'                      => trim((string) ($input['cli_path'] ?? '')),
            'cli_appdata_dir'               => trim((string) ($input['cli_appdata_dir'] ?? '')),
            'send_base_url'                 => trim((string) ($input['send_base_url'] ?? '')),
            'timeout'                       => max(1, (int) ($input['timeout'] ?? 15)),
            'default_deletion_days'         => max(1, (int) ($input['default_deletion_days'] ?? 7)),
            'default_max_access_count'      => max(0, (int) ($input['default_max_access_count'] ?? 1)),
            'default_hide_email'            => !empty($input['default_hide_email']) ? 1 : 0,
            'add_followup'                  => !empty($input['add_followup']) ? 1 : 0,
            'followup_is_private'           => !empty($input['followup_is_private']) ? 1 : 0,
            'store_access_url'              => !empty($input['store_access_url']) ? 1 : 0,
            'followup_template'             => (string) ($input['followup_template'] ?? ''),
            'allow_glpi_followup_templates' => !empty($input['allow_glpi_followup_templates']) ? 1 : 0,
            'password_generator_enabled'    => !empty($input['password_generator_enabled']) ? 1 : 0,
            'send_driver'                   => in_array($input['send_driver'] ?? 'cli', ['cli', 'native'], true)
                                                 ? $input['send_driver'] : 'cli',
            'native_identity_url'           => rtrim(trim((string) ($input['native_identity_url'] ?? '')), '/'),
            'native_api_url'                => rtrim(trim((string) ($input['native_api_url'] ?? '')), '/'),
            'native_web_vault_url'          => rtrim(trim((string) ($input['native_web_vault_url'] ?? '')), '/'),
            'native_client_id'              => trim((string) ($input['native_client_id'] ?? '')),
            'native_email'                  => trim((string) ($input['native_email'] ?? '')),
            'date_mod'                      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];

        // Secrets are only rewritten when a new value is submitted.
        if (($input['master_password'] ?? '') !== '') {
            $fields['master_password'] = self::encrypt((string) $input['master_password']);
        }
        if (($input['cli_session'] ?? '') !== '') {
            $fields['cli_session'] = self::encrypt((string) $input['cli_session']);
        }
        if (($input['native_client_secret'] ?? '') !== '') {
            $fields['native_client_secret'] = self::encrypt((string) $input['native_client_secret']);
        }
        if (($input['native_master_password'] ?? '') !== '') {
            $fields['native_master_password'] = self::encrypt((string) $input['native_master_password']);
        }
        if (!empty($input['clear_master_password'])) {
            $fields['master_password'] = '';
        }
        if (!empty($input['clear_cli_session'])) {
            $fields['cli_session'] = '';
        }
        if (!empty($input['clear_native_client_secret'])) {
            $fields['native_client_secret'] = '';
        }
        if (!empty($input['clear_native_master_password'])) {
            $fields['native_master_password'] = '';
        }

        if (self::countRows() === 0) {
            $fields['id'] = 1;
            $result = $DB->insert(self::getTable(), $fields);
        } else {
            $result = $DB->update(self::getTable(), $fields, ['id' => 1]);
        }

        self::$cache = null;

        return (bool) $result;
    }

    /**
     * Number of rows in the configuration table (avoids countElementsInTable()
     * here, since we need this before deciding INSERT vs UPDATE).
     */
    private static function countRows(): int
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return 0;
        }

        foreach ($DB->request(['COUNT' => 'cpt', 'FROM' => self::getTable()]) as $row) {
            return (int) $row['cpt'];
        }

        return 0;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Config) {
            // Passing the class/icon explicitly here too, some GLPI versions
            // don't pick the icon up from getIcon() alone.
            return self::createTabEntry(self::getTypeName(), 0, self::class, self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Config) {
            self::showConfigForm();
        }
        return true;
    }

    public static function showConfigForm(): void
    {
        $conf = self::getConfig(true);

        TemplateRenderer::getInstance()->display('@bitwardensend/config.html.twig', [
            'conf'                          => $conf,
            'has_master_password'           => self::getMasterPassword() !== '',
            'has_cli_session'               => self::getCliSession() !== '',
            'has_native_client_secret'      => self::getNativeClientSecret() !== '',
            'has_native_master_password'    => self::getNativeMasterPassword() !== '',
            'cleanup_cron_url'              => Send::getCleanupCronUrl(),
            'cleanup_cron_name'             => Send::getTypeName(1) . ' — cleanup',
            'can_update'                    => Session::haveRight('config', UPDATE),
        ]);
    }

    /**
     * URL of the configuration tab.
     */
    public static function getConfigTabUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/front/config.form.php?forcetab=' . urlencode(self::class . '$1');
    }
}