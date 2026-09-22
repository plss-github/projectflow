<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Projectflow\AssistanceMenu;
use GlpiPlugin\Projectflow\Automation;
use GlpiPlugin\Projectflow\Menu;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('PLUGIN_PROJECTFLOW_VERSION', '3.4.1');
define('PLUGIN_PROJECTFLOW_GLPI_MIN', '11.0.0');
define('PLUGIN_PROJECTFLOW_GLPI_MAX', '11.0.99');

define('PLUGIN_PROJECTFLOW_DIR', __DIR__);
// GLPI 11 exposes plugin resources through the canonical /plugins/<key> URL,
// regardless of whether the plugin is physically installed in plugins/ or marketplace/.
define('PLUGIN_PROJECTFLOW_WEBDIR', ($GLOBALS['CFG_GLPI']['root_doc'] ?? '') . '/plugins/projectflow');

function plugin_init_projectflow(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Automation::class);

    if (!Plugin::isPluginActive('projectflow')) {
        return;
    }

    // Keep plugin-owned metadata synchronized with native GLPI lifecycle events.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['projectflow'] = [
        ProjectTask::class => 'plugin_projectflow_item_purge',
        Project::class => 'plugin_projectflow_item_purge',
    ];

    if (!Session::getLoginUserID()) {
        return;
    }

    $menus = [];
    if (Project::canView()) {
        $menus['tools'] = Menu::class;
    }
    if (ProjectTask::canView()) {
        // Add a native-looking entry directly inside GLPI's Assistance section.
        $menus['helpdesk'] = AssistanceMenu::class;
    }
    if ($menus !== []) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['projectflow'] = $menus;
    }

    $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $canonicalPath = (string) (parse_url(PLUGIN_PROJECTFLOW_WEBDIR, PHP_URL_PATH) ?? PLUGIN_PROJECTFLOW_WEBDIR);

    // GLPI 11 canonical URL is /plugins/projectflow/. Keep marketplace support so
    // existing bookmarks/menu entries created before this release still load assets.
    $isProjectflowPage = ($canonicalPath !== '' && str_starts_with($requestPath, rtrim($canonicalPath, '/') . '/'))
        || str_contains($requestPath, '/plugins/projectflow/')
        || str_contains($requestPath, '/marketplace/projectflow/');

    if ($isProjectflowPage) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['projectflow'][] = 'css/projectflow-3.4.1.css';
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['projectflow'][] = 'js/projectflow-3.4.1.js';
    }

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['projectflow'] = 'front/config.php';
    }
}

function plugin_version_projectflow(): array
{
    return [
        'name' => 'Project Flow',
        'version' => PLUGIN_PROJECTFLOW_VERSION,
        'author' => 'Kawan Costa de Santana',
        'license' => 'GPLv3+',
        'homepage' => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_PROJECTFLOW_GLPI_MIN, 'max' => PLUGIN_PROJECTFLOW_GLPI_MAX],
            'php' => ['min' => '8.2'],
        ],
    ];
}

function plugin_projectflow_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_PROJECTFLOW_GLPI_MIN, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_PROJECTFLOW_GLPI_MAX, '<=');
}

function plugin_projectflow_check_config(bool $verbose = false): bool
{
    return true;
}
