<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use ProjectTask;
use Session;

class WeeklyReportService
{
    private const TABLE = 'glpi_plugin_projectflow_weeklyreports';

    public function __construct(
        private readonly TaskService $tasks = new TaskService(),
        private readonly ActivityService $activities = new ActivityService(),
        private readonly WorklogService $worklogs = new WorklogService(),
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly MetaService $meta = new MetaService(),
    ) {}

    public function generate(int $projectId, mixed $weekStart = null): ?array
    {
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem()) return null;
        [$start, $end] = $this->weekRange($weekStart);
        $tasks = $this->tasks->getProjectTasks($projectId);
        $activities = $this->activities->getForProjectRange($projectId, $start, $end);
        $worklogs = $this->worklogs->getForProject($projectId, $start, $end, 1000);
        $hours = $this->worklogs->getSummary($projectId, $start, $end);
        $projectMeta = $this->meta->getForProject($projectId);
        $budgetMinutes = max(0, (int) ($projectMeta['hours_budget_minutes'] ?? 0));
        $projectHours = $this->worklogs->getSummary($projectId);
        $meetings = array_values(array_filter($this->meetings->getForProject($projectId, 500), static function (array $m) use ($start, $end): bool {
            $d = substr((string) $m['start_at'], 0, 10); return $d >= $start && $d <= $end;
        }));

        $completed=[]; $updated=[]; $attention=[]; $next=[];
        foreach($tasks as $task){
            $modified=substr((string)($task['date_mod']??''),0,10);
            $finished=!empty($task['state']['is_finished'])||$task['percent_done']>=100;
            if($finished && (($task['real_end_date'] && substr((string)$task['real_end_date'],0,10)>=$start && substr((string)$task['real_end_date'],0,10)<=$end) || ($modified>=$start&&$modified<=$end))) $completed[]=$task;
            elseif($modified>=$start&&$modified<=$end) $updated[]=$task;
            if(!empty($task['attention'])) $attention[]=$task;
            if(!$finished) $next[]=$task;
        }

        $progress=(int)($project->fields['percent_done']??0);
        $lines=[];
        $lines[]='RELATÓRIO SEMANAL - '.(string)($project->fields['name']??('Projeto #'.$projectId));
        $lines[]='Período: '.date('d/m/Y',strtotime($start)).' a '.date('d/m/Y',strtotime($end));
        $lines[]='Progresso atual: '.$progress.'%';
        $lines[]='';
        $lines[]='RESUMO EXECUTIVO';
        $lines[]=$this->summarySentence($completed,$updated,$activities,$meetings,$hours);
        $lines[]='';
        $lines[]='ENTREGAS CONCLUÍDAS';
        $lines=array_merge($lines,$this->taskLines($completed,'Nenhuma entrega concluída registrada no período.'));
        $lines[]='';
        $lines[]='EM ANDAMENTO / ATUALIZAÇÕES';
        $lines=array_merge($lines,$this->taskLines($updated,'Nenhuma tarefa com atualização registrada no período.'));
        if($activities){
            $lines[]='';$lines[]='ATIVIDADES REGISTRADAS';
            foreach(array_slice($activities,0,30) as $a)$lines[]='• '.$a['task_name'].' — '.$a['content'].' ('.$a['user_name'].')';
        }
        $lines[]='';$lines[]='PONTOS DE ATENÇÃO';
        if(!$attention)$lines[]='• Nenhum ponto de atenção aberto.'; else foreach($attention as $t)$lines[]='• '.$t['name'].($t['attention_note']?' — '.$t['attention_note']:'');
        $lines[]='';$lines[]='PRÓXIMOS PASSOS';
        if(!$next){$lines[]='• Nenhuma tarefa ativa pendente.';}else{usort($next,static fn(array $a,array $b):int=>(($a['plan_end_ts']??PHP_INT_MAX)<=>($b['plan_end_ts']??PHP_INT_MAX))?:($a['id']<=>$b['id']));foreach(array_slice($next,0,10) as $t)$lines[]='• '.$t['name'].' — '.$t['state']['name'].($t['plan_end_date']?' / prazo '.date('d/m/Y',strtotime($t['plan_end_date'])):'');}
        $lines[]='';$lines[]='REUNIÕES / ALINHAMENTOS';
        if(!$meetings)$lines[]='• Nenhuma reunião registrada no período.'; else foreach($meetings as $m)$lines[]='• '.date('d/m',strtotime($m['start_at'])).' — '.$m['title'].' ('.$m['duration_label'].')'.($m['summary']?' — '.$m['summary']:'');
        $lines[]='';$lines[]='HORAS';
        $lines[]='• Execução na semana: '.$hours['execution_label'];
        $lines[]='• Reuniões na semana: '.$hours['meeting_label'];
        $lines[]='• Total da semana: '.$hours['total_label'];
        if($budgetMinutes>0){$used=max(0,(int)($projectHours['total_minutes']??0));$remaining=$budgetMinutes-$used;$lines[]='• Total acumulado do projeto: '.WorklogService::formatMinutes($used).' de '.WorklogService::formatMinutes($budgetMinutes);$lines[]=$remaining>=0?'• Saldo de horas: '.WorklogService::formatMinutes($remaining):'• Excedente de horas: '.WorklogService::formatMinutes(abs($remaining));}
        $lines[]='';$lines[]='Gerado pelo Project Flow em '.date('d/m/Y H:i').'.';
        $text=implode("\n",$lines);

        return ['project_id'=>$projectId,'week_start'=>$start,'week_end'=>$end,'text'=>$text,'progress'=>$progress,'completed'=>$completed,'updated'=>$updated,'activities'=>$activities,'attention'=>$attention,'next'=>$next,'meetings'=>$meetings,'worklogs'=>$worklogs,'hours'=>$hours];
    }

    public function save(int $projectId, string $weekStart, string $content): int|false
    {
        global $DB;
        $project=new Project(); if(!$project->getFromDB($projectId)||!$project->can($projectId,UPDATE)||!$DB->tableExists(self::TABLE))return false;
        [$start,$end]=$this->weekRange($weekStart); $content=mb_substr(trim($content),0,100000); if($content==='')return false;
        try {
            $DB->doQuery('START TRANSACTION');
            $DB->delete(self::TABLE,['projects_id'=>$projectId,'week_start'=>$start]);
            $ok=$DB->insert(self::TABLE,['projects_id'=>$projectId,'week_start'=>$start,'week_end'=>$end,'content'=>$content,'users_id'=>(int)Session::getLoginUserID(),'date_generation'=>date('Y-m-d H:i:s')]);
            if(!$ok){$DB->doQuery('ROLLBACK');return false;}
            $id=(int)$DB->insertId();
            $DB->doQuery('COMMIT');
            return $id;
        } catch (\Throwable $e) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) {}
            \Toolbox::logError('[Project Flow] weekly report transaction: '.$e->getMessage());
            return false;
        }
    }

    public function getSaved(int $projectId,int $limit=20): array
    {
        global $DB; $project=new Project(); if(!$project->getFromDB($projectId)||!$project->canViewItem()||!$DB->tableExists(self::TABLE))return [];
        $out=[]; foreach($DB->request(['FROM'=>self::TABLE,'WHERE'=>['projects_id'=>$projectId],'ORDERBY'=>['week_start DESC'],'LIMIT'=>max(1,min(100,$limit))]) as $r)$out[]=['id'=>(int)$r['id'],'week_start'=>$r['week_start'],'week_end'=>$r['week_end'],'content'=>(string)$r['content'],'user_name'=>(int)$r['users_id']?getUserName((int)$r['users_id']):'Sistema','date_generation'=>$r['date_generation']];
        return $out;
    }

    public function weeksForProject(array $project,int $count=16): array
    {
        $now=new \DateTimeImmutable('today'); $start=$now->modify('monday this week'); $weeks=[];
        for($i=0;$i<$count;$i++){ $s=$start->modify('-'.$i.' week');$e=$s->modify('+6 days');$weeks[]=['start'=>$s->format('Y-m-d'),'end'=>$e->format('Y-m-d'),'label'=>$s->format('d/m/Y').' - '.$e->format('d/m/Y')]; }
        return $weeks;
    }

    private function weekRange(mixed $value): array
    {
        $raw=trim((string)$value);
        try {
            $d=$raw!==''?new \DateTimeImmutable($raw):new \DateTimeImmutable('today');
        } catch (\Throwable) {
            $d=new \DateTimeImmutable('today');
        }
        $monday=$d->modify('monday this week'); return [$monday->format('Y-m-d'),$monday->modify('+6 days')->format('Y-m-d')];
    }
    private function taskLines(array $tasks,string $empty): array { if(!$tasks)return ['• '.$empty]; $out=[]; foreach($tasks as $t)$out[]='• '.$t['name'].' — '.$t['percent_done'].'% / '.$t['state']['name']; return $out; }
    private function summarySentence(array $completed,array $updated,array $activities,array $meetings,array $hours): string
    { return sprintf('Na semana foram concluídas %d tarefa(s), %d tarefa(s) tiveram atualização, %d atividade(s) foram registradas e ocorreram %d reunião(ões). O esforço registrado foi de %s, sendo %s em execução e %s em reuniões.',count($completed),count($updated),count($activities),count($meetings),$hours['total_label'],$hours['execution_label'],$hours['meeting_label']); }
}
