<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Config;
use GlpiPlugin\Projectflow\Service\PortfolioService;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
use Project;
use Session;

class DashboardController
{
    public function index(array $get = [], bool $archived = false): array
    {
        $refs = new ReferenceService();
        $target = PLUGIN_PROJECTFLOW_WEBDIR . '/front/' . ($archived ? 'archived.php' : 'index.php');
        $page = (new PortfolioService())->page($get, $archived, $target);
        $projects = $page['projects'];
        $portfolios = [];
        return [
            'projects'=>$projects,
            'stats'=>$page['stats'],
            'page'=>$page,
            'archived_mode'=>$archived,
            'archived_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/archived.php',
            'portfolio_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/index.php',
            'states'=>$refs->getProjectStates(),
            'priorities'=>$refs->getPriorities(),
            'manager_dropdown'=>\User::dropdown(['name'=>'users_id','right'=>'all','display'=>false,'width'=>'100%','display_emptychoice'=>true,'emptylabel'=>'Não definido']),
            'groups'=>$refs->getGroups(),
            'entities'=>$refs->getEntities(),
            'project_types'=>$refs->getProjectTypes(),
            'templates'=>$refs->getTemplates(),
            'portfolios'=>$portfolios,
            'can_create_project'=>Session::haveRight(Project::$rightname,CREATE),
            'native_create_url'=>Project::getFormURL(false),
            'native_list_url'=>Project::getSearchURL(true).'?reset=reset',
            'ajax_project_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/project.php',
            'my_tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine',
            'tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine',
            'workload_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/workload.php','manager_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/manager.php',
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
