<?php

namespace GlpiPlugin\Projectflow\Application;

use GlpiPlugin\Projectflow\Config;
use GlpiPlugin\Projectflow\Service\AssetService;
use GlpiPlugin\Projectflow\Service\MetaService;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\ReferenceService;
use GlpiPlugin\Projectflow\Service\TaskService;
use GlpiPlugin\Projectflow\Service\WeeklyReportService;
use GlpiPlugin\Projectflow\Service\WorklogService;
use Session;

class ProjectController
{
    public function show(int $projectId): ?array
    {
        $projects=new ProjectService();$project=$projects->getProject($projectId);if($project===null)return null;
        $tasksService=new TaskService();$tasks=$tasksService->getProjectTasks($projectId);
        // Hours dedicated to each task (execution + meetings linked to it).
        $taskHours=(new WorklogService())->getTaskTotals($projectId);$emptyHours=['execution_minutes'=>0,'meeting_minutes'=>0,'total_minutes'=>0,'execution_label'=>'0h','meeting_label'=>'0h','total_label'=>'0h'];
        foreach($tasks as &$t){$h=$taskHours[(int)$t['id']]??$emptyHours;$planned=(int)round(((int)($t['planned_duration']??0))/60);$h['planned_minutes']=$planned;$h['planned_label']=$planned>0?WorklogService::formatMinutes($planned):'';$h['planned_percent']=$planned>0?(int)round($h['total_minutes']/$planned*100):null;$t['hours']=$h;}unset($t);$refs=new ReferenceService();$meta=new MetaService();
        $overdue=0;$completed=0;$milestones=0;$attention=0;
        foreach($tasks as $task){$overdue+=(int)$task['is_overdue'];$completed+=(int)($task['percent_done']>=100||!empty($task['state']['is_finished']));$milestones+=(int)$task['is_milestone'];$attention+=(int)!empty($task['attention']);}
        $states=$refs->getProjectStates();$stateProgress=$meta->getStateProgressMap();foreach($states as &$state)$state['progress']=$stateProgress[$state['id']]??($state['is_finished']?100:0);unset($state);
        $upcomingTasks=[];foreach($tasks as $task){if(empty($task['state']['is_finished'])){$upcomingTasks[]=$task;if(count($upcomingTasks)>=5)break;}}
        // The weekly report is generated on demand (tab opened / "Gerar"), not on every page load.
        $reports=new WeeklyReportService();$weeks=$reports->weeksForProject($project);$weekly=['text'=>'','week_start'=>$weeks[0]['start']??date('Y-m-d')];
        // Tasks in tree order (parent, then its subtasks right below, indented by depth).
        $byParent=[];$ids=[];foreach($tasks as $t){$ids[(int)$t['id']]=true;}
        foreach($tasks as $t){$pid=(int)($t['parent_id']??0);if($pid>0&&!isset($ids[$pid]))$pid=0;$byParent[$pid][]=$t;}
        $tree=[];$walk=function(int $pid,int $depth)use(&$walk,&$tree,$byParent){foreach($byParent[$pid]??[] as $t){$t['depth']=min($depth,6);$t['has_children']=!empty($byParent[(int)$t['id']]);$tree[]=$t;if($depth<20)$walk((int)$t['id'],$depth+1);}};$walk(0,0);
        return [
            'project'=>$project,'tasks'=>$tasks,'tasks_tree'=>$tree,'team_roles'=>($teamRoles=(new \GlpiPlugin\Projectflow\Service\TeamRoleService())->listRoles($projectId)),'team_groups'=>self::teamGroups($project['team']??[],$teamRoles,!empty($project['can_update'])),'upcoming_tasks'=>$upcomingTasks,'board'=>$tasksService->getBoard($tasks,Config::bool('show_finished_states',true)),'timeline'=>$tasksService->buildTimeline($tasks,$project),'gantt'=>$tasksService->buildGantt($tree,$project),'sprints'=>$this->buildSprints($tasks),
            'states'=>$states,'task_types'=>$refs->getTaskTypes(),'users'=>$refs->getUsers(),'groups'=>$refs->getGroups(),'suppliers'=>$refs->getSuppliers(),'contacts'=>$refs->getContacts(),'project_types'=>$refs->getProjectTypes(),'priorities'=>$refs->getPriorities(),'budgets'=>$refs->getBudgets(),'ticket_categories'=>$refs->getTicketCategories((int)$project['entity_id']),'contracts'=>$refs->getContracts((int)$project['entity_id']),'asset_types'=>(new AssetService())->getTypes(),
            'task_stats'=>['total'=>count($tasks),'completed'=>$completed,'overdue'=>$overdue,'milestones'=>$milestones,'attention'=>$attention],
            'weeks'=>$weeks,'ai_enabled'=>\GlpiPlugin\Projectflow\Service\AiReportService::isEnabled(),'weekly_report'=>$weekly,'saved_reports'=>$reports->getSaved($projectId),
            'default_task_state_id'=>$tasksService->defaultTaskStateId([]),'auto_progress_on_kanban'=>Config::bool('auto_progress_on_kanban',true),'auto_add_task_member_to_project_team'=>Config::bool('auto_add_task_member_to_project_team',true),'current_user_id'=>(int)Session::getLoginUserID(),
            'plugin_webdir'=>PLUGIN_PROJECTFLOW_WEBDIR,'ajax_task_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/task.php','ajax_project_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/project.php','ajax_document_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/ajax/document.php','dashboard_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/index.php','templates_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/templates.php','my_tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine','tasks_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/tasks.php?scope=mine','csrf_token'=>Session::getNewCSRFToken(),'compact_cards'=>Config::bool('compact_cards'),
        ];
    }

    /** Team members grouped by function (functions in use, then "Sem função"). */
    private static function teamGroups(array $team,array $roles,bool $canUpdate): array
    {
        $groups=[];
        foreach($roles as $r){$members=array_values(array_filter($team,static fn(array $m):bool=>!empty($m['role'])&&(int)$m['role']['id']===(int)$r['id']));if($members)$groups[]=$r+['members'=>$members,'count'=>count($members)];}
        $none=array_values(array_filter($team,static fn(array $m):bool=>empty($m['role'])));
        if($none)$groups[]=['id'=>0,'name'=>'Sem função','color'=>'#94a3b8','comment'=>$canUpdate?'Escolha a função no card de cada pessoa.':'','members'=>$none,'count'=>count($none)];
        return $groups;
    }

    private function buildSprints(array $tasks): array
    {
        $groups=[];
        foreach($tasks as $task){$date=$task['plan_start_date']?:$task['plan_end_date']; if(!$date){$key='backlog';$label='Sem planejamento';}else{$d=new \DateTimeImmutable($date);$m=$d->modify('monday this week');$e=$m->modify('+6 days');$key=$m->format('Y-m-d');$label=$m->format('d/m').' - '.$e->format('d/m/Y');}
            if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'label'=>$label,'tasks'=>[],'completed'=>0];$groups[$key]['tasks'][]=$task;if($task['percent_done']>=100||!empty($task['state']['is_finished']))$groups[$key]['completed']++;}
        uksort($groups,static function($a,$b){if($a==='backlog')return 1;if($b==='backlog')return -1;return strcmp($a,$b);});
        $thisWeek=(new \DateTimeImmutable('monday this week'))->format('Y-m-d');
        foreach($groups as $k=>&$g){$n=count($g['tasks']);$g['percent']=$n?(int)round($g['completed']/$n*100):0;$g['is_current']=$k===$thisWeek;$g['is_past']=$k!=='backlog'&&$k<$thisWeek;$g['overdue']=count(array_filter($g['tasks'],static fn($t)=>!empty($t['is_overdue'])));}unset($g);
        return array_values($groups);
    }
}
