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

use GLPIKey;
use Throwable;
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
            'api_url'                        => 'http://127.0.0.1:8087',
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
                if (is_array($data)) {
                    $row = $data;
                }
            }
        }

        foreach ($row as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
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
        $rawValue = self::getConfig()['master_password'] ?? '';

        return self::decrypt(is_string($rawValue) ? $rawValue : '');
    }

    /**
     * Decrypted API client secret for the native driver's service account.
     */
    public static function getNativeClientSecret(): string
    {
        $rawValue = self::getConfig()['native_client_secret'] ?? '';

        return self::decrypt(is_string($rawValue) ? $rawValue : '');
    }

    /**
     * Decrypted master password for the native driver's own service account
     * (separate from master_password above, which is for the CLI driver).
     */
    public static function getNativeMasterPassword(): string
    {
        $rawValue = self::getConfig()['native_master_password'] ?? '';

        return self::decrypt(is_string($rawValue) ? $rawValue : '');
    }

    /**
     * Decrypts a value encrypted with encrypt() below — public so other
     * plugin classes storing their own GLPI-key-encrypted values (Send's
     * stored access_url) share this one implementation instead of each
     * wrapping GLPIKey themselves.
     */
    public static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (string) (new GLPIKey())->decrypt($value);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @see decrypt()
     */
    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return (new GLPIKey())->encrypt($value);
    }

    /**
     * Checks the fields the selected driver/mode actually needs. Mirrors
     * NativeSendDriver/CliSendDriver's own runtime checks by hand.
     *
     * @param array<int|string,mixed> $input
     * @return list<string> labels of the missing fields
     */
    public static function validateInput(array $input): array
    {
        $errors = [];

        $driver = in_array($input['send_driver'] ?? 'cli', ['cli', 'native'], true)
            ? $input['send_driver'] : 'cli';

        if ($driver === 'cli') {
            $rawApiUrl = $input['api_url'] ?? '';
            $apiUrl    = is_string($rawApiUrl) ? $rawApiUrl : '';
            if (trim($apiUrl) === '') {
                $errors[] = __('Local API URL', 'bitwardensend');
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
            $rawValue = $input[$field] ?? '';
            $value    = is_string($rawValue) ? $rawValue : '';
            if (trim($value) === '') {
                $errors[] = $label;
            }
        }

        $rawClientSecret  = $input['native_client_secret'] ?? '';
        $clientSecret     = is_string($rawClientSecret) ? $rawClientSecret : '';
        $hasClientSecret  = self::getNativeClientSecret() !== '' && empty($input['clear_native_client_secret']);
        if (trim($clientSecret) === '' && !$hasClientSecret) {
            $errors[] = __('API client secret', 'bitwardensend');
        }

        $rawMasterPassword = $input['native_master_password'] ?? '';
        $masterPassword    = is_string($rawMasterPassword) ? $rawMasterPassword : '';
        $hasMasterPassword = self::getNativeMasterPassword() !== '' && empty($input['clear_native_master_password']);
        if (trim($masterPassword) === '' && !$hasMasterPassword) {
            $errors[] = __('Master password', 'bitwardensend');
        }

        return $errors;
    }

    /**
     * Save the configuration coming from the form.
     *
     * @param array<int|string,mixed> $input
     */
    public static function saveFromInput(array $input): bool
    {
        global $DB;

        $rawApiUrl = $input['api_url'] ?? '';
        $apiUrl    = is_string($rawApiUrl) ? $rawApiUrl : '';

        $rawSendBaseUrl = $input['send_base_url'] ?? '';
        $sendBaseUrl    = is_string($rawSendBaseUrl) ? $rawSendBaseUrl : '';

        $rawTimeout = $input['timeout'] ?? 15;
        $timeout    = is_numeric($rawTimeout) ? (int) $rawTimeout : 15;

        $rawDeletionDays = $input['default_deletion_days'] ?? 7;
        $deletionDays    = is_numeric($rawDeletionDays) ? (int) $rawDeletionDays : 7;

        $rawMaxAccess = $input['default_max_access_count'] ?? 1;
        $maxAccess    = is_numeric($rawMaxAccess) ? (int) $rawMaxAccess : 1;

        $rawFollowupTemplate = $input['followup_template'] ?? '';
        $followupTemplate    = is_string($rawFollowupTemplate) ? $rawFollowupTemplate : '';

        $rawSendDriver = $input['send_driver'] ?? 'cli';
        $sendDriver    = in_array($rawSendDriver, ['cli', 'native'], true) ? $rawSendDriver : 'cli';

        $rawIdentityUrl = $input['native_identity_url'] ?? '';
        $identityUrl    = is_string($rawIdentityUrl) ? $rawIdentityUrl : '';

        $rawNativeApiUrl = $input['native_api_url'] ?? '';
        $nativeApiUrl    = is_string($rawNativeApiUrl) ? $rawNativeApiUrl : '';

        $rawWebVaultUrl = $input['native_web_vault_url'] ?? '';
        $webVaultUrl    = is_string($rawWebVaultUrl) ? $rawWebVaultUrl : '';

        $rawClientId = $input['native_client_id'] ?? '';
        $clientId    = is_string($rawClientId) ? $rawClientId : '';

        $rawEmail = $input['native_email'] ?? '';
        $email    = is_string($rawEmail) ? $rawEmail : '';

        $fields = [
            'api_url'                       => rtrim(trim($apiUrl), '/'),
            'send_base_url'                 => trim($sendBaseUrl),
            'timeout'                       => max(1, $timeout),
            'default_deletion_days'         => max(1, $deletionDays),
            'default_max_access_count'      => max(0, $maxAccess),
            'default_hide_email'            => empty($input['default_hide_email']) ? 0 : 1,
            'add_followup'                  => empty($input['add_followup']) ? 0 : 1,
            'followup_is_private'           => empty($input['followup_is_private']) ? 0 : 1,
            'store_access_url'              => empty($input['store_access_url']) ? 0 : 1,
            'followup_template'             => $followupTemplate,
            'allow_glpi_followup_templates' => empty($input['allow_glpi_followup_templates']) ? 0 : 1,
            'password_generator_enabled'    => empty($input['password_generator_enabled']) ? 0 : 1,
            'send_driver'                   => $sendDriver,
            'native_identity_url'           => rtrim(trim($identityUrl), '/'),
            'native_api_url'                => rtrim(trim($nativeApiUrl), '/'),
            'native_web_vault_url'          => rtrim(trim($webVaultUrl), '/'),
            'native_client_id'              => trim($clientId),
            'native_email'                  => trim($email),
            'date_mod'                      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];

        // Secrets are only rewritten when a new value is submitted.
        $rawMasterPasswordInput = $input['master_password'] ?? '';
        if (is_string($rawMasterPasswordInput) && $rawMasterPasswordInput !== '') {
            $fields['master_password'] = self::encrypt($rawMasterPasswordInput);
        }

        $rawNativeClientSecretInput = $input['native_client_secret'] ?? '';
        if (is_string($rawNativeClientSecretInput) && $rawNativeClientSecretInput !== '') {
            $fields['native_client_secret'] = self::encrypt($rawNativeClientSecretInput);
        }

        $rawNativeMasterPasswordInput = $input['native_master_password'] ?? '';
        if (is_string($rawNativeMasterPasswordInput) && $rawNativeMasterPasswordInput !== '') {
            $fields['native_master_password'] = self::encrypt($rawNativeMasterPasswordInput);
        }

        if (!empty($input['clear_master_password'])) {
            $fields['master_password'] = '';
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
            if (!is_array($row)) {
                return 0;
            }

            $cpt = $row['cpt'] ?? 0;
            return is_numeric($cpt) ? (int) $cpt : 0;
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
        if ($item instanceof \Config && Session::haveRight('config', UPDATE)) {
            self::showConfigForm();
        }

        return true;
    }

    public static function showConfigForm(): void
    {
        // getTabNameForItem() does not check this either — GLPI's generic
        // tab dispatcher (ajax/common.tabs.php) can reach this method
        // directly with a deterministic tab key, without going through
        // that label check. Re-checked here too so this method is safe on
        // its own, regardless of caller.
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $conf = self::getConfig(true);

        TemplateRenderer::getInstance()->display('@bitwardensend/config.html.twig', [
            'conf'                          => $conf,
            'has_master_password'           => self::getMasterPassword() !== '',
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

        $rawRootDoc = $CFG_GLPI['root_doc'] ?? '';
        $rootDoc    = is_string($rawRootDoc) ? $rawRootDoc : '';

        return $rootDoc . '/front/config.form.php?forcetab=' . urlencode(self::class . '$1');
    }
}
