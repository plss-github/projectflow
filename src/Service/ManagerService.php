<?php

namespace GlpiPlugin\Projectflow\Service;

use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Projectflow\Config;
use Group;
use Project;
use ProjectCost;
use ProjectTask;
use ProjectTaskTeam;
use Session;
use User;

/**
 * Central do gestor: todos os projetos de um responsável num lugar só.
 *
 * Projetos = os não arquivados em que o gestor é o Responsável ou em que o grupo responsável é
 * um dos grupos dele. Para cada projeto: saúde, ritmo (concluído x prazo decorrido), previsão de
 * término pelo ritmo atual, horas e custo contra o orçamento e última atividade. Junto: as
 * pendências que pedem decisão (custos aguardando, tarefas atrasadas, sem responsável, projetos
 * parados), a agenda dos próximos 14 dias e a carga da equipe.
 */
class ManagerService
{
    private const DAY = 86400;
    private const STALE_DAYS = 14;

    public function __construct(
        private readonly ProjectService $projects = new ProjectService(),
        private readonly ReferenceService $references = new ReferenceService(),
    ) {}

    public static function canViewOthers(): bool
    {
        return Session::haveRight('project', Project::READALL) || Session::haveRight('config', UPDATE);
    }

    public function build(int $managerId): array
    {
        global $DB;
        $now = time();
        $today = strtotime('today');
        $managerName = (string) getUserName($managerId);

        // ---- projects of the manager ----
        $groups = [];
        foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $managerId]]) as $r) {
            $groups[] = (int) $r['groups_id'];
        }
        $or = [['users_id' => $managerId]];
        if ($groups) $or[] = ['groups_id' => $groups];
        $candidate = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Project::getTable(), 'WHERE' => ['is_deleted' => 0, 'is_template' => 0, 'OR' => $or]]) as $r) {
            $candidate[] = (int) $r['id'];
        }
        $visible = $candidate ? $this->projects->getAccessibleProjectIndex($candidate) : [];
        $ids = array_keys($visible);
        $cards = $ids ? $this->projects->getProjectsByIds($ids) : [];

        $states = $this->references->getStateMap();
        $finishedStates = array_keys(array_filter($states, static fn($s) => !empty($s['is_finished'])));
        $finishedStateId = (int) ($finishedStates[0] ?? 0);

        // ---- hours, costs, activity in bulk ----
        $hours = [];
        $lastLog = [];
        if ($ids && $DB->tableExists('glpi_plugin_projectflow_worklogs')) {
            foreach ($DB->request([
                'SELECT' => ['projects_id', new QueryExpression('SUM(' . $DB->quoteName('minutes') . ') AS ' . $DB->quoteName('m')), new QueryExpression('MAX(' . $DB->quoteName('work_date') . ') AS ' . $DB->quoteName('last'))],
                'FROM' => 'glpi_plugin_projectflow_worklogs',
                'WHERE' => ['projects_id' => $ids],
                'GROUPBY' => ['projects_id'],
            ]) as $r) {
                $hours[(int) $r['projects_id']] = (int) $r['m'];
                $lastLog[(int) $r['projects_id']] = (string) $r['last'];
            }
        }
        $costs = [];
        if ($ids) {
            foreach ($DB->request([
                'SELECT' => ['projects_id', new QueryExpression('SUM(' . $DB->quoteName('cost') . ') AS ' . $DB->quoteName('c'))],
                'FROM' => ProjectCost::getTable(),
                'WHERE' => ['projects_id' => $ids],
                'GROUPBY' => ['projects_id'],
            ]) as $r) {
                $costs[(int) $r['projects_id']] = (float) $r['c'];
            }
        }

        // ---- tasks of all these projects (one query) ----
        $tasks = [];
        $lastTaskMod = [];
        if ($ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'projects_id', 'projectstates_id', 'percent_done', 'plan_start_date', 'plan_end_date', 'date_mod', 'is_milestone'],
                'FROM' => ProjectTask::getTable(),
                'WHERE' => ['projects_id' => $ids, 'is_deleted' => 0, 'is_template' => 0],
            ]) as $r) {
                $pid = (int) $r['projects_id'];
                $lastTaskMod[$pid] = max($lastTaskMod[$pid] ?? '', (string) $r['date_mod']);
                $stateId = (int) $r['projectstates_id'];
                $done = (int) $r['percent_done'] >= 100 || in_array($stateId, $finishedStates, true);
                if ($done) continue;
                $tasks[(int) $r['id']] = $r + ['_state' => $states[$stateId] ?? null];
            }
        }
        $team = [];
        if ($tasks) {
            foreach (array_chunk(array_keys($tasks), 1000) as $chunk) {
                foreach ($DB->request(['SELECT' => ['projecttasks_id', 'itemtype', 'items_id'], 'FROM' => ProjectTaskTeam::getTable(), 'WHERE' => ['projecttasks_id' => $chunk]]) as $r) {
                    $team[(int) $r['projecttasks_id']][] = $r['itemtype'] === User::class ? (string) getUserName((int) $r['items_id'])
                        : ($r['itemtype'] === Group::class ? (string) \Dropdown::getDropdownName('glpi_groups', (int) $r['items_id']) : $r['itemtype']);
                }
            }
        }

        // ---- per project indicators ----
        $rows = [];
        foreach ($cards as $p) {
            $id = $p['id'];
            $start = $p['plan_start_ts'] ?: ($p['date_creation'] ? strtotime((string) $p['date_creation']) : null);
            $end = $p['plan_end_ts'];
            $elapsed = null;
            if ($start && $end && $end > $start) $elapsed = (int) max(0, min(100, round(($now - $start) * 100 / ($end - $start))));
            $progress = (int) $p['percent_done'];
            $forecast = null;
            $delta = null;
            if (!$p['is_finished'] && !$p['is_paused'] && $start && $now > $start && ($progress >= 10 || ($elapsed ?? 0) >= 25) && $progress > 0) {
                $forecast = (int) ($start + ($now - $start) * 100 / $progress);
                if ($end) $delta = (int) round(($forecast - $end) / self::DAY);
            } elseif (!$p['is_finished'] && !$p['is_paused'] && $end && $end < $now) {
                $delta = (int) round(($now - $end) / self::DAY);
            }
            $budget = (int) $p['hours_budget_minutes'];
            $used = (int) ($hours[$id] ?? 0);
            $lastActivity = max((string) ($p['date_mod'] ?? ''), (string) ($lastTaskMod[$id] ?? ''), (string) ($lastLog[$id] ?? ''));
            $idleDays = $lastActivity ? (int) floor(($now - strtotime($lastActivity)) / self::DAY) : null;
            $pace = ($elapsed !== null && !$p['is_finished']) ? $progress - $elapsed : null;
            $nextDelivery = null;
            foreach ($tasks as $t) {
                if ((int) $t['projects_id'] !== $id || empty($t['plan_end_date'])) continue;
                $ts = strtotime((string) $t['plan_end_date']);
                if ($ts >= $today && ($nextDelivery === null || $ts < $nextDelivery['ts'])) $nextDelivery = ['ts' => $ts, 'name' => (string) $t['name'], 'id' => (int) $t['id']];
            }
            $rows[] = [
                'id' => $id,
                'name' => $p['name'],
                'code' => $p['code'] ?: ('PROJ-' . $id),
                'url' => $p['plugin_url'],
                'state' => $p['state'],
                'health' => $p['health_effective'],
                'progress' => $progress,
                'elapsed' => $elapsed,
                'pace' => $pace,
                'is_finished' => $p['is_finished'],
                'is_paused' => $p['is_paused'],
                'plan_end' => $p['plan_end_date'] ? date('Y-m-d', (int) $p['plan_end_ts']) : null,
                'forecast' => $forecast ? date('Y-m-d', $forecast) : null,
                'delta_days' => $delta,
                'hours_used' => $used,
                'hours_used_label' => WorklogService::formatMinutes($used),
                'hours_budget' => $budget,
                'hours_budget_label' => $budget ? WorklogService::formatMinutes($budget) : '',
                'hours_pct' => $budget ? (int) round($used * 100 / $budget) : null,
                'has_money' => $p['has_money'],
                'cost' => (float) ($costs[$id] ?? 0) + ($p['cost_mode'] === 'both' ? round($used / 60 * (float) $p['hour_rate'], 2) : 0),
                'overdue_tasks' => (int) ($p['task_stats']['overdue'] ?? 0),
                'tasks_total' => (int) ($p['task_stats']['total'] ?? 0),
                'tasks_done' => (int) ($p['task_stats']['completed'] ?? 0),
                'attention' => (int) ($p['task_stats']['attention'] ?? 0),
                'next_delivery' => $nextDelivery ? ['date' => date('Y-m-d', $nextDelivery['ts']), 'name' => $nextDelivery['name'], 'url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $nextDelivery['id']] : null,
                'last_activity' => $lastActivity ?: null,
                'idle_days' => $idleDays,
                'is_stale' => !$p['is_finished'] && !$p['is_paused'] && $idleDays !== null && $idleDays >= self::STALE_DAYS,
                'can_update' => $p['can_update'] && !ArchiveService::isArchived($id),
                'manager_name' => $p['manager_id'] ? $p['manager_name'] : ($p['group_name'] !== '' ? $p['group_name'] : $p['manager_name']),
            ];
        }
        $rank = ['critical' => 0, 'attention' => 1, 'good' => 2];
        usort($rows, static fn($a, $b) => [(int) $a['is_finished'], $rank[$a['health']] ?? 3, -($a['delta_days'] ?? -999), $a['name']]
            <=> [(int) $b['is_finished'], $rank[$b['health']] ?? 3, -($b['delta_days'] ?? -999), $b['name']]);

        // ---- decisions inbox ----
        $projectName = [];
        foreach ($rows as $r) $projectName[$r['id']] = $r['name'];
        $overdue = [];
        $unassigned = [];
        $agenda = [];
        foreach ($tasks as $tid => $t) {
            $pid = (int) $t['projects_id'];
            $endTs = $t['plan_end_date'] ? strtotime((string) $t['plan_end_date']) : null;
            $item = [
                'id' => $tid,
                'name' => (string) $t['name'],
                'project_id' => $pid,
                'project_name' => $projectName[$pid] ?? '',
                'url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $tid,
                'end' => $endTs ? date('Y-m-d', $endTs) : null,
                'team' => array_slice($team[$tid] ?? [], 0, 3),
                'state' => (string) ($t['_state']['name'] ?? ''),
                'percent' => (int) $t['percent_done'],
            ];
            if ($endTs && $endTs < $now) $overdue[] = $item + ['late_days' => (int) floor(($today - strtotime('today', $endTs)) / self::DAY)];
            if (empty($team[$tid])) $unassigned[] = $item;
            if ($endTs && $endTs >= $today && $endTs < $today + 14 * self::DAY) {
                $agenda[date('Y-m-d', $endTs)][] = ['kind' => !empty($t['is_milestone']) ? 'milestone' : 'delivery', 'title' => (string) $t['name'], 'project' => $projectName[$pid] ?? '', 'url' => $item['url'], 'time' => date('H:i', $endTs)];
            }
        }
        usort($overdue, static fn($a, $b) => $b['late_days'] <=> $a['late_days']);
        usort($unassigned, static fn($a, $b) => strcmp((string) ($a['end'] ?? '9999'), (string) ($b['end'] ?? '9999')));
        foreach ($rows as $r) {
            if ($r['plan_end'] && !$r['is_finished'] && strtotime($r['plan_end']) >= $today && strtotime($r['plan_end']) < $today + 14 * self::DAY) {
                $agenda[$r['plan_end']][] = ['kind' => 'deadline', 'title' => 'Prazo do projeto', 'project' => $r['name'], 'url' => $r['url'], 'time' => ''];
            }
        }
        if ($ids && $DB->tableExists('glpi_plugin_projectflow_meetings')) {
            foreach ($DB->request([
                'FROM' => 'glpi_plugin_projectflow_meetings',
                'WHERE' => ['projects_id' => $ids, ['start_at' => ['>=', date('Y-m-d 00:00:00', $today)]], ['start_at' => ['<', date('Y-m-d 00:00:00', $today + 14 * self::DAY)]]],
                'ORDER' => ['start_at ASC'],
            ]) as $m) {
                $ts = strtotime((string) $m['start_at']);
                $agenda[date('Y-m-d', $ts)][] = ['kind' => 'meeting', 'title' => (string) $m['title'], 'project' => $projectName[(int) $m['projects_id']] ?? '', 'url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/project.php?id=' . (int) $m['projects_id'] . '#meetings', 'time' => date('H:i', $ts)];
            }
        }
        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $ts = $today + $i * self::DAY;
            $key = date('Y-m-d', $ts);
            $items = $agenda[$key] ?? [];
            usort($items, static fn($a, $b) => strcmp($a['time'] ?: '99', $b['time'] ?: '99'));
            $days[] = ['date' => $key, 'dow' => ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][(int) date('w', $ts)], 'day' => date('d/m', $ts), 'is_today' => $i === 0, 'is_weekend' => in_array((int) date('w', $ts), [0, 6], true), 'items' => $items];
        }

        $pendingCosts = [];
        $pendingTotal = 0.0;
        $moneyIds = array_column(array_filter($rows, static fn($r) => $r['has_money']), 'id');
        if ($moneyIds && $DB->tableExists(TaskCostService::TABLE)) {
            $canApprove = [];
            $taskNames = [];
            foreach ($DB->request(['FROM' => TaskCostService::TABLE, 'WHERE' => ['projects_id' => $moneyIds, 'status' => 'pending'], 'ORDER' => ['date_creation ASC']]) as $c) {
                $pid = (int) $c['projects_id'];
                if (!isset($canApprove[$pid])) {
                    $pr = new Project();
                    $canApprove[$pid] = $pr->getFromDB($pid) && $this->projects->canViewMoney($pr);
                }
                if (!$canApprove[$pid]) continue;
                $tid = (int) $c['projecttasks_id'];
                $taskNames[$tid] ??= (string) ($DB->request(['SELECT' => ['name'], 'FROM' => ProjectTask::getTable(), 'WHERE' => ['id' => $tid]])->current()['name'] ?? ('Tarefa #' . $tid));
                $pendingCosts[] = [
                    'id' => (int) $c['id'],
                    'name' => (string) $c['name'],
                    'comment' => (string) ($c['comment'] ?? ''),
                    'cost' => (float) $c['cost'],
                    'cost_formatted' => ProjectService::brl((float) $c['cost']),
                    'cost_date' => $c['cost_date'],
                    'user_name' => (string) getUserName((int) $c['users_id']),
                    'task_name' => $taskNames[$tid],
                    'task_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $tid,
                    'project_id' => $pid,
                    'project_name' => $projectName[$pid] ?? '',
                    'project_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/project.php?id=' . $pid,
                    'age_days' => (int) floor(($now - strtotime((string) $c['date_creation'])) / self::DAY),
                ];
                $pendingTotal += (float) $c['cost'];
            }
        }
        $stale = array_values(array_filter($rows, static fn($r) => $r['is_stale']));

        // ---- team load (this week + next) restricted to people working on these projects ----
        $teamLoad = [];
        if ($ids) {
            $wl = (new WorkloadService())->build(['weeks' => 2]);
            $set = array_flip($ids);
            foreach ($wl['rows'] ?? [] as $row) {
                if ($row['type'] !== 'user') continue;
                $mine = array_filter($row['tasks'], static fn($t) => isset($set[$t['project_id']]));
                if (!$mine) continue;
                $teamLoad[] = [
                    'name' => $row['name'], 'initials' => $row['initials'],
                    'weeks' => array_map(static fn($c, $cap) => ['total' => $c['total'], 'cap' => $cap, 'pct' => $c['pct']], $row['load'], $row['capacity']),
                    'peak' => $row['peak'], 'tasks' => count($mine),
                ];
            }
            usort($teamLoad, static fn($a, $b) => $b['peak'] <=> $a['peak']);
        }

        // ---- summary ----
        $active = array_values(array_filter($rows, static fn($r) => !$r['is_finished']));
        $healthCount = ['good' => 0, 'attention' => 0, 'critical' => 0];
        foreach ($active as $r) $healthCount[$r['health']] = ($healthCount[$r['health']] ?? 0) + 1;
        $score = $active ? (int) round(($healthCount['good'] * 100 + $healthCount['attention'] * 50) / count($active)) : 100;
        $weekEnd = $today + 7 * self::DAY;
        $deliveriesWeek = 0;
        foreach ($tasks as $t) if ($t['plan_end_date'] && strtotime((string) $t['plan_end_date']) >= $today && strtotime((string) $t['plan_end_date']) < $weekEnd) $deliveriesWeek++;
        $usedTotal = array_sum(array_column($active, 'hours_used'));
        $budgetTotal = array_sum(array_column($active, 'hours_budget'));

        return [
            'manager_id' => $managerId,
            'manager_name' => $managerName,
            'first_name' => $this->firstName($managerId, $managerName),
            'rows' => $rows,
            'summary' => [
                'projects' => count($rows),
                'active' => count($active),
                'finished' => count($rows) - count($active),
                'health' => $healthCount,
                'score' => $score,
                'at_risk' => $healthCount['critical'],
                'overdue_tasks' => count($overdue),
                'unassigned' => count($unassigned),
                'deliveries_week' => $deliveriesWeek,
                'pending_costs' => count($pendingCosts),
                'pending_total' => ProjectService::brl($pendingTotal),
                'stale' => count($stale),
                'hours_used_label' => WorklogService::formatMinutes($usedTotal),
                'hours_budget_label' => $budgetTotal ? WorklogService::formatMinutes($budgetTotal) : '',
                'hours_pct' => $budgetTotal ? (int) round($usedTotal * 100 / $budgetTotal) : null,
                'late_projects' => count(array_filter($active, static fn($r) => ($r['delta_days'] ?? 0) > 0)),
                'decisions' => count($pendingCosts) + count($overdue) + count($unassigned) + count($stale),
            ],
            'inbox' => [
                'costs' => $pendingCosts,
                'overdue' => array_slice($overdue, 0, 60),
                'unassigned' => array_slice($unassigned, 0, 60),
                'stale' => $stale,
            ],
            'agenda' => $days,
            'team' => array_slice($teamLoad, 0, 12),
            'finished_state_id' => $finishedStateId,
            'states' => array_values($states),
            'headline' => $this->headline($rows, $healthCount, count($overdue), count($pendingCosts), $deliveriesWeek),
        ];
    }

    private function firstName(int $userId, string $fallback): string
    {
        $u = new User();
        if ($u->getFromDB($userId)) {
            $first = trim((string) ($u->fields['firstname'] ?? ''));
            if ($first !== '') return $first;
            $real = trim((string) ($u->fields['realname'] ?? ''));
            if ($real !== '') return $real;
            return (string) $u->fields['name'];
        }
        return $fallback;
    }

    /** Deterministic one-line situation (shown before / without AI). */
    private function headline(array $rows, array $health, int $overdue, int $costs, int $week): string
    {
        $active = count(array_filter($rows, static fn($r) => !$r['is_finished']));
        if ($active === 0) return 'Nenhum projeto ativo sob sua responsabilidade.';
        $parts = [];
        $parts[] = $health['critical'] ? sprintf('%d de %d projetos em estado crítico', $health['critical'], $active) : sprintf('%d projetos ativos, nenhum em estado crítico', $active);
        if ($overdue) $parts[] = sprintf('%d tarefa(s) atrasada(s)', $overdue);
        if ($costs) $parts[] = sprintf('%d custo(s) esperando sua aprovação', $costs);
        if ($week) $parts[] = sprintf('%d entrega(s) nesta semana', $week);
        return implode(' · ', $parts) . '.';
    }

    /** Compact facts for the AI executive summary of the portfolio. */
    public function aiFacts(array $d): array
    {
        $proj = [];
        foreach (array_slice(array_values(array_filter($d['rows'], static fn($r) => !$r['is_finished'])), 0, 40) as $r) {
            $proj[] = array_filter([
                'projeto' => $r['name'], 'saude' => ['good' => 'saudável', 'attention' => 'atenção', 'critical' => 'crítico'][$r['health']] ?? $r['health'],
                'estado' => $r['state']['name'] ?? '', 'concluido_pct' => $r['progress'], 'prazo_decorrido_pct' => $r['elapsed'],
                'prazo' => $r['plan_end'], 'previsao_termino' => $r['forecast'], 'dias_de_atraso_previsto' => $r['delta_days'],
                'tarefas_atrasadas' => $r['overdue_tasks'], 'horas_usadas' => $r['hours_used_label'], 'horas_previstas' => $r['hours_budget_label'] ?: null,
                'dias_sem_atividade' => $r['idle_days'], 'proxima_entrega' => $r['next_delivery']['name'] ?? null,
            ], static fn($v) => $v !== null && $v !== '');
        }
        return [
            'hoje' => date('Y-m-d'),
            'gestor' => $d['manager_name'],
            'resumo' => $d['summary'],
            'projetos' => $proj,
            'tarefas_mais_atrasadas' => array_map(static fn($t) => ['tarefa' => $t['name'], 'projeto' => $t['project_name'], 'dias' => $t['late_days']], array_slice($d['inbox']['overdue'], 0, 8)),
            'custos_aguardando' => array_map(static fn($c) => ['custo' => $c['name'], 'projeto' => $c['project_name'], 'valor' => $c['cost_formatted']], array_slice($d['inbox']['costs'], 0, 8)),
            'pessoas_sobrecarregadas' => array_map(static fn($t) => ['pessoa' => $t['name'], 'pico_pct' => $t['peak']], array_slice(array_values(array_filter($d['team'], static fn($t) => $t['peak'] > 100)), 0, 6)),
        ];
    }
}
