<?php
// Cenário da Carga de trabalho (GLPI 11). Uso: docker compose cp tests/lab/workload_test.php glpi:/tmp/ && docker compose exec glpi php /tmp/workload_test.php
// Cria projetos, tarefas, grupo e um feriado de teste (e remove no fim): rode só em laboratório.
if (PHP_SAPI !== 'cli') {
    exit; // lab script: command line only
}
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();
use GlpiPlugin\Projectflow\Service\WorkloadService;
$auth = new Auth(); $auth->user = new User(); $auth->user->getFromDBbyName('glpi'); $auth->auth_succeded = true;
Session::init($auth); Session::changeActiveEntities(0, true);
global $DB;
$fails=0;
function check($l,$ok,$x=''){global $fails;echo ($ok?'PASS ':'FAIL ').$l.($ok?'':'  -> '.json_encode($x))."\n";$fails+=$ok?0:1;}
function uid($n){$u=new User();$u->getFromDBbyName($n);return (int)$u->getID();}
$tech=uid('tech'); $normal=uid('normal');
$t=time();
$p1=(int)(new Project())->add(['name'=>"WL A $t",'entities_id'=>0]);
$p2=(int)(new Project())->add(['name'=>"WL B $t",'entities_id'=>0]);
(new ProjectTeam())->add(['projects_id'=>$p1,'itemtype'=>'User','items_id'=>$normal]);
function task($p,$n,$s,$e,$h,$who,$pct=0){$id=(int)(new ProjectTask())->add(['projects_id'=>$p,'name'=>$n,'plan_start_date'=>$s,'plan_end_date'=>$e,'planned_duration'=>$h*3600,'percent_done'=>$pct,'entities_id'=>0]);
 foreach($who as $w)(new ProjectTaskTeam())->add(['projecttasks_id'=>$id,'itemtype'=>$w[0],'items_id'=>$w[1]]);return $id;}
$A=task($p1,'A','2026-11-02 08:00:00','2026-11-06 18:00:00',20,[['User',$tech]]);
$B=task($p1,'B','2026-11-09 08:00:00','2026-11-10 18:00:00',32,[['User',$tech],['User',$normal]]);
$E=task($p2,'E','2026-11-02 08:00:00','2026-11-03 18:00:00',10,[['User',$tech]]);
$N=task($p1,'N sem estimativa','2026-11-04 08:00:00','2026-11-05 18:00:00',0,[['User',$normal]]);
$U=task($p1,'U sem resp','2026-11-02 08:00:00','2026-11-02 18:00:00',4,[]);
$s=new WorkloadService();
$r=$s->build(['start'=>'2026-11-04','weeks'=>2,'mode'=>'plan']);
$row=fn($r,$k)=>current(array_filter($r['rows'],fn($x)=>$x['key']===$k));
check('window snaps to monday',$r['start']==='2026-11-02'&&$r['end']==='2026-11-15',[$r['start'],$r['end']]);
$rt=$row($r,"user:$tech");
check('tech wk0 = A 20h + E 10h = 1800',$rt['load'][0]['total']===1800,$rt['load'][0]);
check('tech capacity wk0 40h',$rt['capacity'][0]===2400,$rt['capacity']);
check('tech wk0 75%',$rt['load'][0]['pct']===75,$rt['load'][0]);
check('tech wk1 = B/2 16h',$rt['load'][1]['total']===960,$rt['load'][1]);
$rn=$row($r,"user:$normal");
check('normal wk1 16h, no_estimate 1',$rn['load'][1]['total']===960&&$rn['no_estimate']===1,$rn);
$none=$row($r,'none:0');
check('unassigned row has U with 4h',$none&&count(array_filter($none['tasks'],fn($x)=>$x['id']===$U&&$x['weeks'][0]===240))===1,$none);
// project tab: E counts as other project
$rp=$s->build(['start'=>'2026-11-02','weeks'=>2,'mode'=>'plan','focus_project_id'=>$p1]);
$rt=$row($rp,"user:$tech");
check('focus: tech main 1200 other 600',$rt['load'][0]['main']===1200&&$rt['load'][0]['other']===600,$rt['load'][0]);
check('focus: E listed as other project',count(array_filter($rt['tasks'],fn($x)=>$x['id']===$E&&$x['other_project']))===1);
// overload: add 30h more for tech on wk0
$O=task($p1,'O','2026-11-02 08:00:00','2026-11-06 18:00:00',30,[['User',$tech]]);
$r=(new WorkloadService())->build(['start'=>'2026-11-02','weeks'=>2,'mode'=>'plan','focus_project_id'=>$p1]);
$rt=$row($r,"user:$tech");
check('overload 150% and flagged',$rt['load'][0]['pct']===150&&$rt['overloaded_weeks']===1&&$r['summary']['overloaded']>=1,[$rt['load'][0],$r['summary']]);
check('sorted: tech first',$r['rows'][0]['key']==="user:$tech");
// calendar + holiday: Default calendar (12h/day capped to 8h) + holiday on 2026-11-03
$hid=(int)(new Holiday())->add(['name'=>'WL feriado','begin_date'=>'2026-11-03','end_date'=>'2026-11-03','is_perpetual'=>0,'entities_id'=>0,'is_recursive'=>1]);
$ch=(int)(new Calendar_Holiday())->add(['calendars_id'=>1,'holidays_id'=>$hid]);
$orig=$DB->request(['SELECT'=>['calendars_id','calendars_strategy'],'FROM'=>'glpi_entities','WHERE'=>['id'=>0]])->current();
$DB->update('glpi_entities',['calendars_id'=>1,'calendars_strategy'=>0],['id'=>0]);
$GLOBALS['GLPI_CACHE']->clear();
$r=(new WorkloadService())->build(['start'=>'2026-11-02','weeks'=>1,'mode'=>'plan']);
$rt=$row($r,"user:$tech");
check('calendar: capacity 4 days x 8h = 1920',$rt['capacity'][0]===1920,$rt['capacity']);
check('calendar: E (2 days, 1 holiday) all on 11-02, total A20+E10+O30=3600',$rt['load'][0]['total']===3600,$rt['load'][0]);
$DB->update('glpi_entities',$orig,['id'=>0]);
(new Calendar_Holiday())->delete(['id'=>$ch],true);(new Holiday())->delete(['id'=>$hid],true);
$GLOBALS['GLPI_CACHE']->clear();
// remaining mode: overdue task half done lands today
$today=date('Y-m-d');
$D=task($p2,'D atrasada',date('Y-m-d H:i:s',strtotime('-10 days')),date('Y-m-d H:i:s',strtotime('-3 days')),10,[['User',$normal]],50); $DB->update('glpi_projecttasks',['percent_done'=>50],['id'=>$D]);
$r=(new WorkloadService())->build(['weeks'=>2]);
$rn=$row($r,"user:$normal");
check('remaining: overdue 5h on next working day, flagged',$rn&&array_sum(array_column($rn['load'],'overdue'))===300,$rn['load']??null);
$cur=$r['weeks'][0]; $exp=0; for($t=strtotime(max($cur['start'],date('Y-m-d')));$t<=strtotime($cur['end']);$t+=86400) if(date('N',$t)<=5)$exp+=480;
check('remaining: current week capacity starts today',$rn['capacity'][0]===$exp,[$rn['capacity'],$exp]);
// group filter
$g=(int)(new Group())->add(['name'=>"WL grp $t",'entities_id'=>0,'is_recursive'=>1]);
(new Group_User())->add(['groups_id'=>$g,'users_id'=>$normal]);
$r=(new WorkloadService())->build(['start'=>'2026-11-02','weeks'=>2,'mode'=>'plan','group_id'=>$g]);
check('group filter: only normal (+group row none)',array_column($r['rows'],'key')===["user:$normal"],array_column($r['rows'],'key'));
// cleanup
foreach([$p1,$p2] as $p)(new Project())->delete(['id'=>$p],true);
(new Group())->delete(['id'=>$g],true);
echo "fails=$fails\n";
