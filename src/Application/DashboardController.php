<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Config;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
use Project;
use Session;

class DashboardController
{
    public function index(): array
    {
        $service = new ProjectService();
        $projects = $service->getAccessibleProjects(Config::int('dashboard_limit',250));
        $refs = new ReferenceService();
        $portfolios = array_values(array_unique(array_filter(array_map(static fn($p)=>$p['portfolio'],$projects))));
        natcasesort($portfolios);
        return [
            'projects'=>$projects,
            'stats'=>$service->getDashboardStats($projects),
            'states'=>$refs->getProjectStates(),
            'priorities'=>$refs->getPriorities(),
            'users'=>$refs->getUsers(),
            'groups'=>$refs->getGroups(),
            'entities'=>$refs->getEntities(),
            'project_types'=>$refs->getProjectTypes(),
            'templates'=>$refs->getTemplates(),
            'portfolios'=>$portfolios,
            'can_create_project'=>Session::haveRight(Project::$rightname,CREATE),
            'native_create_url'=>Project::getFormURL(false),
            'ajax_project_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/project.php',
            'my_tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine',
            'tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine',
            'templates_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/templates.php',
            'csrf_token'=>Session::getNewCSRFToken(),
            'compact_cards'=>Config::bool('compact_cards'),
            'default_view'=>(string)Config::get('default_dashboard_view','cards'),
            'default_project_state_id'=>Config::int('default_project_state_id',0),
            'default_execution_mode'=>(string)Config::get('default_execution_mode','direct'),
            'default_cost_mode'=>(string)Config::get('default_cost_mode','hours'),
        ];
    }
}
