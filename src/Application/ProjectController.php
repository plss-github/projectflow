<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Config;
use GlpiPlugin\Projectflow\Service\AssetService;
use GlpiPlugin\Projectflow\Service\MetaService;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
use GlpiPlugin\Projectflow\Service\TaskService;
use GlpiPlugin\Projectflow\Service\WeeklyReportService;
use Session;

class ProjectController
{
    public function show(int $projectId): ?array
    {
        $projects=new ProjectService();$project=$projects->getProject($projectId);if($project===null)return null;
        $tasksService=new TaskService();$tasks=$tasksService->getProjectTasks($projectId);$refs=new ReferenceService();$meta=new MetaService();
        $overdue=0;$completed=0;$milestones=0;$attention=0;
        foreach($tasks as $task){$overdue+=(int)$task['is_overdue'];$completed+=(int)($task['percent_done']>=100||!empty($task['state']['is_finished']));$milestones+=(int)$task['is_milestone'];$attention+=(int)!empty($task['attention']);}
        $states=$refs->getProjectStates();$stateProgress=$meta->getStateProgressMap();foreach($states as &$state)$state['progress']=$stateProgress[$state['id']]??($state['is_finished']?100:0);unset($state);
        $reports=new WeeklyReportService();$weeks=$reports->weeksForProject($project);$weekly=$reports->generate($projectId,$weeks[0]['start']??date('Y-m-d'));
        return [
            'project'=>$project,'tasks'=>$tasks,'board'=>$tasksService->getBoard($tasks,Config::bool('show_finished_states',true)),'timeline'=>$tasksService->buildTimeline($tasks,$project),'sprints'=>$this->buildSprints($tasks),
            'states'=>$states,'task_types'=>$refs->getTaskTypes(),'users'=>$refs->getUsers(),'groups'=>$refs->getGroups(),'suppliers'=>$refs->getSuppliers(),'contacts'=>$refs->getContacts(),'project_types'=>$refs->getProjectTypes(),'priorities'=>$refs->getPriorities(),'budgets'=>$refs->getBudgets(),'ticket_categories'=>$refs->getTicketCategories((int)$project['entity_id']),'contracts'=>$refs->getContracts((int)$project['entity_id']),'asset_types'=>(new AssetService())->getTypes(),
            'task_stats'=>['total'=>count($tasks),'completed'=>$completed,'overdue'=>$overdue,'milestones'=>$milestones,'attention'=>$attention],
            'weeks'=>$weeks,'weekly_report'=>$weekly,'saved_reports'=>$reports->getSaved($projectId),
            'default_task_state_id'=>Config::int('default_task_state_id',0),'auto_progress_on_kanban'=>Config::bool('auto_progress_on_kanban',true),'auto_add_task_member_to_project_team'=>Config::bool('auto_add_task_member_to_project_team',true),'current_user_id'=>(int)Session::getLoginUserID(),
            'ajax_task_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/task.php','ajax_project_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/project.php','ajax_document_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/document.php','dashboard_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/index.php','my_tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine','tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine','csrf_token'=>Session::getNewCSRFToken(),'compact_cards'=>Config::bool('compact_cards'),
        ];
    }

    private function buildSprints(array $tasks): array
    {
        $groups=[];
        foreach($tasks as $task){$date=$task['plan_start_date']?:$task['plan_end_date']; if(!$date){$key='backlog';$label='Sem planejamento';}else{$d=new \DateTimeImmutable($date);$m=$d->modify('monday this week');$e=$m->modify('+6 days');$key=$m->format('Y-m-d');$label=$m->format('d/m').' - '.$e->format('d/m/Y');}
            if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'label'=>$label,'tasks'=>[],'completed'=>0];$groups[$key]['tasks'][]=$task;if($task['percent_done']>=100||!empty($task['state']['is_finished']))$groups[$key]['completed']++;}
        uksort($groups,static function($a,$b){if($a==='backlog')return 1;if($b==='backlog')return -1;return strcmp($a,$b);});return array_values($groups);
    }
}
