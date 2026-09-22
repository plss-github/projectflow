<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Service\ReferenceService;
use Project;
use Session;

class TemplatesController
{
    public function index(): array
    {
        $refs=new ReferenceService();
        return ['templates'=>$refs->getTemplates(),'states'=>$refs->getProjectStates(),'priorities'=>$refs->getPriorities(),'users'=>$refs->getUsers(),'groups'=>$refs->getGroups(),'entities'=>$refs->getEntities(),'project_types'=>$refs->getProjectTypes(),'can_create_project'=>Session::haveRight(Project::$rightname,CREATE),'ajax_project_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/project.php','dashboard_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/index.php','csrf_token'=>Session::getNewCSRFToken(),'default_project_state_id'=>\GlpiPlugin\Projectflow\Config::int('default_project_state_id',0),'default_execution_mode'=>(string)\GlpiPlugin\Projectflow\Config::get('default_execution_mode','direct'),'default_cost_mode'=>(string)\GlpiPlugin\Projectflow\Config::get('default_cost_mode','hours')];
    }
}
