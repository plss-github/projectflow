<?php
// Cenário das regras de negócio (GLPI 11). Uso: docker compose cp tests/lab/rules_test.php glpi:/tmp/ && docker compose exec glpi php /tmp/rules_test.php
// Cria regras, grupos, estados e projetos de teste no banco: rode só em laboratório.
if (PHP_SAPI !== 'cli') {
    exit; // lab script: command line only
}
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Projectflow\RuleProject;
use GlpiPlugin\Projectflow\RuleProjectTask;
use GlpiPlugin\Projectflow\RuleProjectCollection;
use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\TaskService;
use GlpiPlugin\Projectflow\Service\MetaService;

$auth = new Auth();
$auth->user = new User();
$auth->user->getFromDBbyName('glpi');
$auth->auth_succeded = true;
Session::init($auth);
Session::changeActiveEntities(0, true);

$fails = 0;
function check(string $label, bool $ok, $extra = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . json_encode($extra, JSON_UNESCAPED_UNICODE)), "\n"; $fails += $ok ? 0 : 1; }
function uid(string $n): int { $u = new User(); $u->getFromDBbyName($n); return (int) $u->getID(); }

global $DB;
// clean previous runs
$DB->delete('glpi_rules', ['sub_type' => [RuleProject::class, RuleProjectTask::class]]);

function makeRule(string $class, string $name, int $condition, array $criteria, array $actions, string $match = 'AND'): int {
    $rule = new $class();
    $id = (int) $rule->add(['name' => $name, 'sub_type' => $class, 'match' => $match, 'is_active' => 1, 'condition' => $condition, 'entities_id' => 0, 'is_recursive' => 1]);
    foreach ($criteria as [$c, $cond, $pattern]) (new RuleCriteria())->add(['rules_id' => $id, 'criteria' => $c, 'condition' => $cond, 'pattern' => $pattern]);
    foreach ($actions as [$type, $field, $value]) (new RuleAction())->add(['rules_id' => $id, 'action_type' => $type, 'field' => $field, 'value' => $value]);
    return $id;
}

$tech = uid('tech'); $normal = uid('normal');
$group = (int) (new Group())->add(['name' => 'Infra PF ' . time(), 'entities_id' => 0, 'is_recursive' => 1, 'is_manager' => 1]);
$state = (int) (new ProjectState())->add(['name' => 'Em análise ' . time(), 'color' => '#ff0000']);
$ttype = (int) (new ProjectTaskType())->add(['name' => 'Entrega ' . time()]);

// template with a parent + child task
$tpl = (int) (new Project())->add(['name' => 'TPL Implantação', 'template_name' => 'TPL Implantação', 'is_template' => 1, 'entities_id' => 0, 'plan_start_date' => '2026-01-01 08:00:00']);
$t1 = (int) (new ProjectTask())->add(['projects_id' => $tpl, 'name' => 'Levantamento', 'entities_id' => 0, 'plan_start_date' => '2026-01-02 08:00:00', 'plan_end_date' => '2026-01-03 18:00:00']);
$t2 = (int) (new ProjectTask())->add(['projects_id' => $tpl, 'projecttasks_id' => $t1, 'name' => 'Entrevistas', 'entities_id' => 0]);
(new MetaService())->saveTaskMeta($t2, ['priority' => 5]);

makeRule(RuleProject::class, 'Implantações', RuleProject::ONADD, [
    ['name', Rule::PATTERN_CONTAIN, 'Implantação'],
], [
    ['assign', 'priority', 5],
    ['assign', '_pf_risk_level', 'high'],
    ['assign', '_pf_portfolio', 'Serviços'],
    ['append', '_pf_team_user', $tech],
    ['append', '_pf_team_group', $group],
    ['assign', '_pf_apply_template', $tpl],
]);
makeRule(RuleProject::class, 'Risco crítico', RuleProject::ONUPDATE, [
    ['_pf_risk_level', Rule::PATTERN_IS, 'critical'],
], [
    ['assign', '_pf_health', 'critical'],
    ['assign', 'projectstates_id', $state],
]);
makeRule(RuleProjectTask::class, 'Homologação', RuleProjectTask::ONADD | RuleProjectTask::ONUPDATE, [
    ['name', Rule::PATTERN_CONTAIN, 'Homologação'],
], [
    ['assign', '_pf_priority', 6],
    ['assign', '_pf_attention', 1],
    ['assign', '_pf_attention_note', 'Validar com o cliente'],
    ['append', '_pf_team_user', $normal],
]);
makeRule(RuleProjectTask::class, 'Marco vira entrega', RuleProjectTask::ONUPDATE, [
    ['is_milestone', Rule::PATTERN_IS, 1],
], [
    ['assign', 'projecttasktypes_id', $ttype],
]);

// collections listed in Administração > Regras
$labels = array_column(RuleCollection::getRules(), 'label');
check('lista de regras tem projetos', (bool) array_filter($labels, fn($l) => str_contains($l, 'Regras de negócios para projetos')), $labels);
check('lista de regras tem tarefas', (bool) array_filter($labels, fn($l) => str_contains($l, 'tarefas de projeto')), $labels);
$links = array_column(RuleCollection::getRules(), 'link');
check('link da regra', in_array('/plugins/projectflow/front/ruleproject.php', $links, true), $links);

$svc = new ProjectService(); $tasks = new TaskService(); $meta = new MetaService();
$team = function (int $pid): array { global $DB; $r = []; foreach ($DB->request(['FROM' => 'glpi_projectteams', 'WHERE' => ['projects_id' => $pid]]) as $row) $r[] = $row['itemtype'] . ':' . $row['items_id']; return $r; };

// 1. project via Project Flow
$pid = (int) $svc->create(['name' => 'Implantação Service Desk', 'risk_level' => 'normal', 'priority' => 3]);
$p = new Project(); $p->getFromDB($pid);
check('PF: prioridade 5 (campo nativo)', (int) $p->fields['priority'] === 5, $p->fields['priority']);
$m = $meta->getForProject($pid);
check('PF: risco alto (sobrepõe o formulário)', $m['risk_level'] === 'high', $m);
check('PF: portfólio', $m['portfolio'] === 'Serviços', $m);
check('PF: equipe com tech e grupo', in_array("User:$tech", $team($pid)) && in_array("Group:$group", $team($pid)), $team($pid));
$rows = iterator_to_array($DB->request(['FROM' => 'glpi_projecttasks', 'WHERE' => ['projects_id' => $pid], 'ORDER' => 'id']));
$byName = array_column($rows, null, 'name');
check('PF: tarefas do template criadas', isset($byName['Levantamento'], $byName['Entrevistas']), array_keys($byName));
check('PF: hierarquia mantida', (int) ($byName['Entrevistas']['projecttasks_id'] ?? 0) === (int) ($byName['Levantamento']['id'] ?? -1));
check('PF: meta da tarefa copiada', (int) $meta->getTaskMeta((int) ($byName['Entrevistas']['id'] ?? 0))['priority'] === 5);

// 2. update rule
$svc->update($pid, ['risk_level' => 'critical']);
$p->getFromDB($pid); $m = $meta->getForProject($pid);
check('PF update: saúde crítica', $m['health'] === 'critical', $m);
check('PF update: estado', (int) $p->fields['projectstates_id'] === $state, $p->fields['projectstates_id']);
$before = count($rows);
$svc->update($pid, ['name' => 'Implantação Service Desk 2']);
$after = countElementsInTable('glpi_projecttasks', ['projects_id' => $pid]);
check('PF update: template não reaplicado', $after === $before, [$before, $after]);

// 3. native add
$nid = (int) (new Project())->add(['name' => 'Implantação nativa', 'entities_id' => 0]);
$p->getFromDB($nid);
check('Nativo: prioridade 5', (int) $p->fields['priority'] === 5, $p->fields['priority']);
check('Nativo: risco alto', $meta->getForProject($nid)['risk_level'] === 'high');
check('Nativo: tarefas do template', countElementsInTable('glpi_projecttasks', ['projects_id' => $nid]) === 2);

// 4. no match
$oid = (int) $svc->create(['name' => 'Outro projeto', 'priority' => 3]);
$p->getFromDB($oid);
check('Sem regra: prioridade 3', (int) $p->fields['priority'] === 3);
check('Sem regra: sem tarefas', countElementsInTable('glpi_projecttasks', ['projects_id' => $oid]) === 0);

// 5. created from the template itself: template not applied twice
$fid = (int) $svc->create(['name' => 'Implantação do template', 'template_id' => $tpl]);
check('Do template: tarefas não duplicadas', countElementsInTable('glpi_projecttasks', ['projects_id' => $fid]) === 2, countElementsInTable('glpi_projecttasks', ['projects_id' => $fid]));
check('Do template: risco alto', $meta->getForProject($fid)['risk_level'] === 'high');

// 6. task rules
$tid = (int) $tasks->create($oid, ['name' => 'Homologação final', 'priority' => 2]);
$tm = $meta->getTaskMeta($tid);
check('Tarefa: prioridade 6', (int) $tm['priority'] === 6, $tm);
check('Tarefa: ponto de atenção', (int) $tm['attention'] === 1 && $tm['attention_note'] === 'Validar com o cliente', $tm);
check('Tarefa: equipe com normal', countElementsInTable('glpi_projecttaskteams', ['projecttasks_id' => $tid, 'itemtype' => 'User', 'items_id' => $normal]) === 1);
check('Tarefa: normal na equipe do projeto', in_array("User:$normal", $team($oid)), $team($oid));
$tid2 = (int) $tasks->create($oid, ['name' => 'Kickoff']);
$tasks->update($tid2, ['is_milestone' => 1]);
$t = new ProjectTask(); $t->getFromDB($tid2);
check('Tarefa update: marco vira tipo Entrega', (int) $t->fields['projecttasktypes_id'] === $ttype, $t->fields['projecttasktypes_id']);
$tasks->update($tid2, ['name' => 'Homologação do kickoff']);
check('Tarefa update: renomeada casa a regra', (int) $meta->getTaskMeta($tid2)['priority'] === 6, $meta->getTaskMeta($tid2));
// native task form
$tid3 = (int) (new ProjectTask())->add(['projects_id' => $oid, 'name' => 'Homologação nativa', 'entities_id' => 0]);
check('Tarefa nativa: prioridade 6', (int) $meta->getTaskMeta($tid3)['priority'] === 6, $meta->getTaskMeta($tid3));

// 7. display helpers
$r = new RuleProject();
check('rótulo da ação risco', $r->getActionValue('_pf_risk_level', 'assign', 'high') === 'Alto');
check('rótulo do template', $r->getActionValue('_pf_apply_template', 'assign', $tpl) === 'TPL Implantação');
ob_start(); $r->displayAdditionalRuleAction($r->getAction('_pf_risk_level'), 'high'); $html = ob_get_clean();
check('select do risco', str_contains($html, 'Crítico') && str_contains($html, '<select'), substr($html, 0, 200));
check('padrão do critério', $r->getCriteriaDisplayPattern('_pf_risk_level', Rule::PATTERN_IS, 'critical') === 'Crítico');

echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
