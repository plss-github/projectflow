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
        $all=$refs->getTemplates(true);
        return ['templates'=>array_values(array_filter($all,static fn($t)=>!$t['is_archived'])),'archived_templates'=>array_values(array_filter($all,static fn($t)=>$t['is_archived'])),'source_projects'=>array_values((new \GlpiPlugin\Projectflow\Service\ProjectService())->getAccessibleProjectIndex(null)),'task_types'=>$refs->getTaskTypes(),'tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine','states'=>$refs->getProjectStates(),'priorities'=>$refs->getPriorities(),'users'=>$refs->getUsers(),'manager_dropdown'=>\User::dropdown(['name'=>'users_id','right'=>'all','display'=>false,'width'=>'100%','display_emptychoice'=>true,'emptylabel'=>'Não definido']),'groups'=>$refs->getGroups(),'entities'=>$refs->getEntities(),'project_types'=>$refs->getProjectTypes(),'can_create_project'=>Session::haveRight(Project::$rightname,CREATE),'ajax_project_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/project.php','dashboard_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/index.php','csrf_token'=>Session::getNewCSRFToken(),'default_project_state_id'=>\GlpiPlugin\Projectflow\Config::int('default_project_state_id',0),'default_execution_mode'=>(string)\GlpiPlugin\Projectflow\Config::get('default_execution_mode','direct'),'default_cost_mode'=>(string)\GlpiPlugin\Projectflow\Config::get('default_cost_mode','hours')];
    }
}
