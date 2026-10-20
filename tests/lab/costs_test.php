<?php
// Cenário dos custos de tarefa com aprovação (GLPI 11).
// Uso: docker compose cp tests/lab/costs_test.php glpi:/tmp/ && docker compose exec glpi php /tmp/costs_test.php <usuario_executor> <usuario_gestor>
// O executor NÃO pode ter direito de configuração (ex.: perfil Technician); o gestor vira o responsável do projeto de teste.
// Cria um projeto de teste com uma tarefa e apaga tudo no fim: rode só em laboratório.
if (PHP_SAPI !== 'cli') {
    exit; // lab script: command line only
}
ob_start();
chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Projectflow\Service\ProjectService;
use GlpiPlugin\Projectflow\Service\TaskCostService;

global $DB;
if ($argc < 3) { echo "uso: php costs_test.php <usuario_executor> <usuario_gestor>\n"; exit(1); }
[$exec, $mgr] = [$argv[1], $argv[2]];
$fails = 0;
function check(string $l, bool $ok, $x = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $l . ($ok ? '' : '  -> ' . json_encode($x, JSON_UNESCAPED_UNICODE)) . "\n"; $fails += $ok ? 0 : 1; }
function login(string $name): void { $_SESSION = []; $a = new Auth(); $a->user = new User(); if (!$a->user->getFromDBbyName($name)) { echo "usuário $name não existe\n"; exit(1); } $a->auth_succeded = true; Session::init($a); Session::changeActiveEntities(0, true); }
$total = static fn(int $p): float => (float) (new ProjectService())->getCosts($p)['total'];

login($mgr);
TaskCostService::ensureTable();
$execId = (new User())->getIdByName($exec);
$pid = (int) (new Project())->add(['name' => 'PF custos teste ' . time(), 'entities_id' => 0, 'users_id' => (int) Session::getLoginUserID()]);
$DB->updateOrInsert('glpi_plugin_projectflow_projectmeta', ['cost_mode' => 'both', 'execution_mode' => 'direct'], ['projects_id' => $pid]);
$task = (int) (new ProjectTask())->add(['projects_id' => $pid, 'name' => 'Tarefa com custos', 'entities_id' => 0]);
(new ProjectTaskTeam())->add(['projecttasks_id' => $task, 'itemtype' => 'User', 'items_id' => $execId]);

login($exec);
$s = new TaskCostService();
$ctx = $s->context($task);
check('executor: pode lançar e não aprova', $ctx['can_add'] && !$ctx['can_approve'], [$ctx['can_add'], $ctx['can_approve']]);
$r = $s->add($task, ['name' => 'Uber até o cliente', 'cost' => '150,50', 'cost_date' => date('Y-m-d'), 'comment' => 'ida e volta']);
check('executor: custo fica aguardando', $r['ok'] && str_contains($r['message'], 'aprovação'), $r);
$c1 = (int) $r['id'];
check('executor: não aprova', !$s->decide($c1, 'approve')['ok']);
$c2 = (int) $s->add($task, ['name' => 'Licença', 'cost' => '1.234,56'])['id'];
check('valor zero recusado', !$s->add($task, ['name' => 'Zero', 'cost' => '0'])['ok']);

login($mgr);
$s = new TaskCostService();
check('pendente não conta no financeiro', $total($pid) == 0.0);
$ap = $s->forProject($pid);
check('aprovações: 2 aguardando, R$ 1.385,06', $ap['pending'] === 2 && $ap['pending_total_formatted'] === '1.385,06', $ap);
check('gestor aprova', $s->decide($c1, 'approve', 'ok')['ok']);
$costs = (new ProjectService())->getCosts($pid);
check('aprovado conta, com origem na tarefa', $costs['total'] == 150.5 && $costs['items'][0]['task_id'] === $task && $costs['items'][0]['name'] === 'Uber até o cliente', $costs);
check('não decide duas vezes', !$s->decide($c1, 'reject')['ok']);
check('recusa', $s->decide($c2, 'reject', 'sem nota fiscal')['ok']);
check('recusado não conta', $total($pid) == 150.5);
$r = $s->add($task, ['name' => 'Hotel', 'cost' => '400']);
check('custo do gestor entra aprovado', $r['ok'] && $total($pid) == 550.5, $r);
$ws = (new ProjectService())->getProject($pid);
check('financeiro do projeto = 550,50', $ws['finance']['extra_total'] == 550.5 && $ws['cost_approvals']['pending'] === 0);
check('remover aprovado tira do financeiro', $s->remove((int) $r['id'])['ok'] && $total($pid) == 150.5);
$pc = (int) $DB->request(['FROM' => TaskCostService::TABLE, 'WHERE' => ['id' => $c1]])->current()['projectcosts_id'];
(new ProjectCost())->delete(['id' => $pc], true);
check('custo nativo apagado -> "removido"', $DB->request(['FROM' => TaskCostService::TABLE, 'WHERE' => ['id' => $c1]])->current()['status'] === 'removed');

login($exec);
$s = new TaskCostService();
check('executor não remove recusado', !$s->remove($c2)['ok']);
$r = $s->add($task, ['name' => 'Estacionamento', 'cost' => '30']);
check('executor remove o próprio pendente', $s->remove((int) $r['id'])['ok']);
$DB->update('glpi_plugin_projectflow_projectmeta', ['cost_mode' => 'hours'], ['projects_id' => $pid]);
check('projeto só de horas: sem custos', !(new TaskCostService())->context($task)['enabled']);

login($mgr);
(new Project())->delete(['id' => $pid], true);
check('limpeza: custos do projeto apagados', countElementsInTable(TaskCostService::TABLE, ['projects_id' => $pid]) === 0);
echo "fails=$fails\n";
