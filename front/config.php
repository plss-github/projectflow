<?php

use GlpiPlugin\Projectflow\Config;
use GlpiPlugin\Projectflow\Service\CatalogService;
use GlpiPlugin\Projectflow\Service\MetaService;
use GlpiPlugin\Projectflow\Service\ProgressRuleService;
use GlpiPlugin\Projectflow\Service\ReferenceService;


Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}

$refs = new ReferenceService();
$meta = new MetaService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF is already validated (and the token consumed) by GLPI 11's kernel
    // (CheckCsrfListener) for every non-AJAX POST; a second check here always fails.
    Config::set('dashboard_limit', (string) max(25, min(1000, (int) ($_POST['dashboard_limit'] ?? 250))));
    Config::set('show_finished_states', !empty($_POST['show_finished_states']) ? '1' : '0');
    Config::set('compact_cards', !empty($_POST['compact_cards']) ? '1' : '0');
    Config::set('auto_progress_on_kanban', !empty($_POST['auto_progress_on_kanban']) ? '1' : '0');
    Config::set('auto_add_task_member_to_project_team', !empty($_POST['auto_add_task_member_to_project_team']) ? '1' : '0');
    Config::set(
        'default_dashboard_view',
        in_array(($_POST['default_dashboard_view'] ?? 'cards'), ['cards', 'table'], true)
            ? (string) $_POST['default_dashboard_view']
            : 'cards'
    );
    Config::set('health_due_soon_days', (string) max(1, min(60, (int) ($_POST['health_due_soon_days'] ?? 7))));
    $stateIds=array_column($refs->getProjectStates(),'id');
    $defaultProjectState=(int)($_POST['default_project_state_id']??0);
    $defaultTaskState=(int)($_POST['default_task_state_id']??0);
    if(in_array($defaultProjectState,$stateIds,true)) Config::set('default_project_state_id',(string)$defaultProjectState);
    if(in_array($defaultTaskState,$stateIds,true)) Config::set('default_task_state_id',(string)$defaultTaskState);
    Config::set('default_execution_mode', in_array(($_POST['default_execution_mode']??'direct'),['direct','ticket'],true)?(string)$_POST['default_execution_mode']:'direct');
    Config::set('default_cost_mode', in_array(($_POST['default_cost_mode']??'hours'),['hours','money'],true)?(string)$_POST['default_cost_mode']:'hours');
    Config::set('reminder_email_enabled', !empty($_POST['reminder_email_enabled'])?'1':'0');
    Config::set('replace_native_projects_menu', !empty($_POST['replace_native_projects_menu'])?'1':'0');
    unset($_SESSION['glpimenu']); // rebuild the GLPI menu with the new setting

    foreach ((array) ($_POST['state_progress'] ?? []) as $stateId => $percent) {
        $stateId = (int) $stateId;
        if ($stateId <= 0) continue;
        $meta->setStateProgress($stateId, max(0, min(100, (int) $percent)));
    }

    Session::addMessageAfterRedirect('Configurações do Project Flow salvas.', true, INFO);
    // GLPI 11 routes every request through public/index.php, so PHP_SELF is /index.php.
    Html::redirect(PLUGIN_PROJECTFLOW_WEBDIR . '/front/config.php');
}

ProgressRuleService::ensureTable();
$catalog = new CatalogService();
$stateProgress = $meta->getStateProgressMap();
$states = $refs->getProjectStates();
foreach ($states as &$state) {
    $state['progress'] = $stateProgress[$state['id']] ?? ($state['is_finished'] ? 100 : 0);
}
unset($state);

plugin_projectflow_register_assets();
Html::header('Project Flow - Configurações', $_SERVER['PHP_SELF'], 'tools',plugin_projectflow_header_item());
plugin_projectflow_display('config.html.twig', [
    'dashboard_limit' => Config::int('dashboard_limit', 250),
    'show_finished_states' => Config::bool('show_finished_states', true),
    'compact_cards' => Config::bool('compact_cards'),
    'auto_progress_on_kanban' => Config::bool('auto_progress_on_kanban', true),
    'auto_add_task_member_to_project_team' => Config::bool('auto_add_task_member_to_project_team', true),
    'default_dashboard_view' => (string) Config::get('default_dashboard_view', 'cards'),
    'health_due_soon_days' => Config::int('health_due_soon_days', 7),
    'default_project_state_id'=>Config::int('default_project_state_id',0),
    'default_task_state_id'=>Config::int('default_task_state_id',0),
    'default_execution_mode'=>(string)Config::get('default_execution_mode','direct'),
    'default_cost_mode'=>(string)Config::get('default_cost_mode','hours'),
    'reminder_email_enabled'=>Config::bool('reminder_email_enabled',true),
    'replace_native_projects_menu'=>Config::bool('replace_native_projects_menu',true),
    'states' => $states,
    'catalog_states' => array_map(static fn(array $s): array => $s + ['progress' => $s['is_finished'] ? 100 : ($stateProgress[$s['id']] ?? 0)], $catalog->list('state')),
    'project_types' => $catalog->list('project_type'),
    'task_types' => $catalog->list('task_type'),
    'rules' => (new ProgressRuleService())->list(),
    'groups' => $refs->getGroups(),
    'ajax_config_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/config.php',
    'csrf_token' => Session::getNewCSRFToken(),
]);
Html::footer();
