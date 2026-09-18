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
 * Same rule set and builder chain GLPI's own PluginsRector.php applies for
 * every plugin, reproduced directly instead of `require`-ing that file from
 * an adjacent GLPI checkout: GlpiSetList now comes from glpi-project/rector-glpi
 * (a normal Composer dependency - see composer.json's require-dev), so this
 * runs from a bare clone of this repository alone, no GLPI checkout needed
 * next to it. `src/Plugin.php` is still required directly below: that part
 * is a separate concern (making GLPI core's own Plugin class resolvable for
 * registerPluginAutoloading() further down), which rector-glpi does not
 * provide - GLPI core itself, not just its Rector ruleset, is still needed
 * for that one file.
 */

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Configuration\RectorConfigBuilder;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use RectorGlpi\Set\GlpiSetList;

require_once __DIR__ . '/../../src/Plugin.php';

/**
 * Marks the plugin as loaded so `Plugin*` classes resolve through the legacy
 * autoloader during static analysis, without a full framework bootstrap (DB,
 * session, plugin init logic). Copied from GLPI core's own PluginsRector.php,
 * which every plugin previously `require`d this same logic from.
 *
 * @param string[] $paths
 */
function registerPluginAutoloading(array $paths): void
{
    $plugin_root = \dirname($paths[0] ?? __DIR__);
    $plugin_key  = \strtolower(\basename($plugin_root));

    if (!\defined('GLPI_PLUGINS_DIRECTORIES')) {
        \define('GLPI_PLUGINS_DIRECTORIES', [\dirname($plugin_root)]);
    }

    $loaded_plugins_property = new ReflectionProperty(Plugin::class, 'loaded_plugins');
    $loaded_plugins          = $loaded_plugins_property->getValue();

    if (!\in_array($plugin_key, $loaded_plugins, true)) {
        $loaded_plugins_property->setValue(null, [...$loaded_plugins, $plugin_key]);
    }
}

$paths = [
    __DIR__ . '/src',
    __DIR__ . '/ajax',
    __DIR__ . '/front',
    __DIR__ . '/hook.php',
    __DIR__ . '/setup.php',
    __DIR__ . '/tests',
];

registerPluginAutoloading($paths);

/** @var RectorConfigBuilder $config */
$config = RectorConfig::configure()
    ->withPaths($paths)
    ->withRootFiles()
    ->withSets([
        GlpiSetList::GLPI_DEFAULT_SET,
    ])
    ->withCache(
        cacheDirectory: 'var/rector',
        cacheClass: FileCacheStorage::class,
    )
    ->withParallel(timeoutSeconds: 300)
    ->withImportNames(removeUnusedImports: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
    )
    // withPhpVersion() intentionally not called - both (withPhpSets and
    // withPhpVersion) resolve the PHP version from this plugin's own
    // composer.json.
    ->withPhpSets()
    ->withSkip([
        // GLPI plugins receive request data as strings ($_POST, $_GET,
        // CommonDBTM::$input). strict_types=1 turns scalar coercion into
        // runtime TypeErrors.
        SafeDeclareStrictTypesRector::class,
    ]);

return $config;
