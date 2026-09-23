<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Projectflow\Automation;
use GlpiPlugin\Projectflow\Menu;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('PLUGIN_PROJECTFLOW_VERSION', '3.4.3');
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
    // Progress rules (state change / notification by percent) for every task update,
    // whatever the screen: Project Flow, Kanban or native GLPI form.
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['projectflow'] = [
        ProjectTask::class => 'plugin_projectflow_item_update',
    ];

    if (!Session::getLoginUserID()) {
        return;
    }

    $replaceNative = plugin_projectflow_replaces_native_projects();
    if ($replaceNative && Project::canView()) {
        // Tools > Projects opens Project Flow; no separate "Project Flow" entry is needed.
        plugin_projectflow_redirect_native_project_list();
    }
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['projectflow'] = 'plugin_projectflow_redefine_menus';

    $menus = [];
    if (!$replaceNative && Project::canView()) {
        $menus['tools'] = Menu::class;
    }
    // Project tasks are reached only from the portfolio ("Minhas tarefas" button); there is no
    // entry under Assistance.
    if ($menus !== []) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['projectflow'] = $menus;
    }

    // Front controllers register Project Flow assets immediately before
    // rendering the GLPI header. This avoids inspecting every GLPI request
    // and keeps assets scoped to Project Flow screens only.

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['projectflow'] = 'front/config.php';
    }
}

/**
 * Register Project Flow assets for the current rendered page.
 *
 * GLPI 11 exposes files from plugin /public directories through the canonical
 * /plugins/<key>/ URL. Hook paths remain relative to that public directory.
 */
function plugin_projectflow_register_assets(): void
{
    global $PLUGIN_HOOKS;


    // GLPI appends ?v=<plugin version> and serves plugin assets with a 30-day cache. A content
    // stamp in the path makes browsers pick up CSS/JS changes even within the same version.
    $stamp = static function (string $relative): string {
        $file = PLUGIN_PROJECTFLOW_DIR . '/public/' . $relative;
        $meta = @filemtime($file) . '-' . @filesize($file);
        return $relative . '?h=' . substr(sha1($meta), 0, 10);
    };
    $css = $stamp('css/projectflow-3.4.3.css');
    $js = $stamp('js/projectflow-3.4.3.js');

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['projectflow'] ??= [];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['projectflow'] ??= [];

    if (!in_array($css, $PLUGIN_HOOKS[Hooks::ADD_CSS]['projectflow'], true)) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['projectflow'][] = $css;
    }
    if (!in_array($js, $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['projectflow'], true)) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['projectflow'][] = $js;
    }
}

/**
 * Whether Tools > Projects is taken over by Project Flow (config `replace_native_projects_menu`,
 * enabled by default).
 */
function plugin_projectflow_replaces_native_projects(): bool
{
    static $value = null;
    if ($value === null) {
        try {
            $value = \GlpiPlugin\Projectflow\Config::bool('replace_native_projects_menu', true);
        } catch (\Throwable) {
            $value = false;
        }
    }
    return $value;
}

/** Menu sector/item used by Project Flow manager pages for the GLPI header and breadcrumb. */
function plugin_projectflow_header_item(): string
{
    return plugin_projectflow_replaces_native_projects() ? 'project' : Menu::class;
}

/**
 * Point the native Tools > Projects entry to Project Flow. The native list stays reachable
 * through the "Lista nativa" button (URL with query string, which is not redirected).
 */
function plugin_projectflow_redefine_menus(array $menus): array
{
    // Menus cached in session by older versions may still hold removed entries.
    if (isset($menus['helpdesk']['content'])) {
        unset($menus['helpdesk']['content']['glpiplugin\\projectflow\\assistancemenu']);
    }
    if (!plugin_projectflow_replaces_native_projects()) {
        return $menus;
    }
    if (isset($menus['tools']['content'])) {
        unset($menus['tools']['content'][strtolower(Menu::class)]);
    }
    if (!isset($menus['tools']['content']['project']) || !is_array($menus['tools']['content']['project'])) {
        return $menus;
    }
    $page = '/plugins/projectflow/front/index.php';
    $menus['tools']['content']['project']['page'] = $page;
    $menus['tools']['content']['project']['links']['search'] = $page;
    if (($menus['tools']['default'] ?? '') === '/front/project.php') {
        $menus['tools']['default'] = $page;
    }
    return $menus;
}

/**
 * Plain GET of the native project list (menu click, breadcrumb, bookmark without filters)
 * goes to the Project Flow portfolio. Any query string (search criteria, reset, sort...)
 * keeps the native list available.
 */
function plugin_projectflow_redirect_native_project_list(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    $root = rtrim((string) ($GLOBALS['CFG_GLPI']['root_doc'] ?? ''), '/');
    if ($query !== '' || $path !== $root . '/front/project.php') {
        return;
    }
    header('Location: ' . PLUGIN_PROJECTFLOW_WEBDIR . '/front/index.php', true, 302);
    exit;
}

/**
 * Render a Project Flow template.
 *
 * GLPI compiles Twig templates by file path and, in production mode, never re-reads the
 * source (`auto_reload` is off; debug mode uses a separate compiled cache). When the plugin
 * files are replaced without a reinstall (Docker volume, git pull, manual copy) - or when the
 * compiled cache cannot be purged by the web user - old screens keep being served outside
 * debug mode.
 *
 * Rendering from the template source makes the compiled cache key depend on the template
 * content: a changed file always produces a fresh compilation, while unchanged templates
 * keep using the normal Twig disk cache.
 */
function plugin_projectflow_display(string $template, array $variables = []): void
{
    $name = '@projectflow/' . $template;
    $path = PLUGIN_PROJECTFLOW_DIR . '/templates/' . basename($template);
    $source = is_file($path) ? file_get_contents($path) : false;
    $renderer = \Glpi\Application\View\TemplateRenderer::getInstance();
    if ($source === false || !method_exists($renderer, 'getEnvironment')) {
        $renderer->display($name, $variables);
        return;
    }
    echo $renderer->getEnvironment()->createTemplate($source, $name)->render($variables);
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
