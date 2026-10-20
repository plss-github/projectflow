<?php

namespace GlpiPlugin\Projectflow\Service;

use Calendar;
use Entity;
use GlpiPlugin\Projectflow\Config;
use Group;
use Project;
use ProjectTask;
use ProjectTaskTeam;
use ProjectTeam;
use Session;
use User;

/**
 * Carga de trabalho: horas planejadas das tarefas x capacidade de cada pessoa, por semana.
 *
 * - As horas da tarefa (duração planejada) são espalhadas pelos dias úteis entre o início e o
 *   fim planejados e divididas igualmente entre os responsáveis (usuários e grupos).
 * - Modo "remaining" (padrão): só o que falta (duração x (100 - % concluído)), de hoje até o fim
 *   planejado; tarefas atrasadas e não concluídas caem em hoje e são marcadas como atraso.
 *   Modo "plan": o planejado original, no período planejado, incluindo tarefas concluídas.
 * - Capacidade: dias úteis e feriados do calendário GLPI da entidade do usuário (estratégia de
 *   calendário da entidade); horas do dia = min(expediente do calendário, limite diário do plugin).
 *   Sem calendário: segunda a sexta com o limite diário.
 * - Tarefas de projetos que o usuário não pode ver entram na carga da pessoa, mas sem nome.
 */
class WorkloadService
{
    public const MAX_WEEKS = 26;
    private const DAY = 86400;

    /** @var array<int, array<int,int>> calendar id => [Y-m-d => seconds] */
    private array $dayCapacity = [];
    /** @var array<int, Calendar|null> */
    private array $calendars = [];
    /** @var array<int,int> entity id => calendar id */
    private array $entityCalendar = [];
    private int $dailyCap;
    private string $windowStart;
    private string $windowEnd;
    private string $today;

    public function __construct(
        private readonly ReferenceService $references = new ReferenceService(),
    ) {
        $this->dailyCap = max(30, min(24 * 60, Config::int('workload_daily_minutes', 480))) * 60;
    }

    /**
     * @param array{start?:string,weeks?:int,mode?:string,project_id?:int,group_id?:int,focus_project_id?:int} $opts
     */
    public function build(array $opts = []): array
    {
        global $DB;
        if (!Project::canView() && !ProjectTask::canView()) return ['ok' => false, 'message' => 'Sem permissão.'];

        $mode = ($opts['mode'] ?? 'remaining') === 'plan' ? 'plan' : 'remaining';
        $weeks = max(1, min(self::MAX_WEEKS, (int) ($opts['weeks'] ?? 8)));
        $this->today = date('Y-m-d', strtotime((string) ($_SESSION['glpi_currenttime'] ?? 'now')));
        $startTs = strtotime((string) ($opts['start'] ?? '')) ?: strtotime($this->today);
        $startTs = strtotime('monday this week', $startTs);
        $this->windowStart = date('Y-m-d', $startTs);
        $this->windowEnd = date('Y-m-d', strtotime('+' . ($weeks * 7 - 1) . ' days', $startTs));
        $focusProject = (int) ($opts['focus_project_id'] ?? 0);
        $projectFilter = $focusProject ?: (int) ($opts['project_id'] ?? 0);
        $groupFilter = (int) ($opts['group_id'] ?? 0);

        $weekList = [];
        for ($i = 0; $i < $weeks; $i++) {
            $ws = strtotime('+' . ($i * 7) . ' days', $startTs);
            $weekList[] = [
                'start' => date('Y-m-d', $ws),
                'end' => date('Y-m-d', strtotime('+6 days', $ws)),
                'label' => date('d/m', $ws),
                'is_current' => $this->today >= date('Y-m-d', $ws) && $this->today <= date('Y-m-d', strtotime('+6 days', $ws)),
                'is_past' => date('Y-m-d', strtotime('+6 days', $ws)) < $this->today,
            ];
        }

        $visible = (new ProjectService())->getAccessibleProjectIndex(null);
        if ($projectFilter && !isset($visible[$projectFilter])) return ['ok' => false, 'message' => 'Projeto não encontrado ou sem acesso.'];

        $states = $this->references->getStateMap();
        $defaultState = Config::int('default_task_state_id', 0);

        // ---- tasks touching the window (any project: hidden ones count, anonymously) ----
        $tt = ProjectTask::getTable();
        $pt = Project::getTable();
        $endBound = $this->windowEnd . ' 23:59:59';
        $where = [
            "$pt.is_deleted" => 0,
            "$pt.is_template" => 0,
            "$tt.is_template" => 0,
            'OR' => [
                ['NOT' => ["$tt.plan_start_date" => null]],
                ['NOT' => ["$tt.plan_end_date" => null]],
            ],
        ];
        // Period overlaps the window. In "remaining" mode overdue unfinished tasks also count
        // (they land on today) as long as today is inside the window.
        $overlap = [
            'AND' => [
                ['OR' => [["$tt.plan_start_date" => null], ["$tt.plan_start_date" => ['<=', $endBound]]]],
                ['OR' => [["$tt.plan_end_date" => null], ["$tt.plan_end_date" => ['>=', $this->windowStart . ' 00:00:00']]]],
            ],
        ];
        $todayInWindow = $this->today >= $this->windowStart && $this->today <= $this->windowEnd;
        if ($mode === 'remaining' && $todayInWindow) {
            $where[] = ['OR' => [$overlap, ["$tt.plan_end_date" => ['<', $this->today . ' 00:00:00']]]];
        } else {
            $where[] = $overlap;
        }

        $tasks = [];
        foreach ($DB->request([
            'SELECT' => ["$tt.id", "$tt.name", "$tt.projects_id", "$tt.entities_id", "$tt.plan_start_date", "$tt.plan_end_date",
                "$tt.planned_duration", "$tt.percent_done", "$tt.projectstates_id", "$pt.name AS project_name", "$pt.entities_id AS project_entity"],
            'FROM' => $tt,
            'INNER JOIN' => [$pt => ['ON' => [$tt => 'projects_id', $pt => 'id']]],
            'WHERE' => $where,
            'LIMIT' => 20000,
        ]) as $row) {
            $stateId = (int) $row['projectstates_id'] ?: $defaultState;
            $finished = !empty($states[$stateId]['is_finished']) || (int) $row['percent_done'] >= 100;
            if ($mode === 'remaining' && $finished) continue;
            $tasks[(int) $row['id']] = $row + ['_finished' => $finished];
        }

        // ---- assignees ----
        $team = [];
        if ($tasks) {
            foreach (array_chunk(array_keys($tasks), 1000) as $chunk) {
                foreach ($DB->request(['SELECT' => ['projecttasks_id', 'itemtype', 'items_id'], 'FROM' => ProjectTaskTeam::getTable(),
                    'WHERE' => ['projecttasks_id' => $chunk, 'itemtype' => [User::class, Group::class]]]) as $r) {
                    $team[(int) $r['projecttasks_id']][] = ($r['itemtype'] === User::class ? 'user' : 'group') . ':' . (int) $r['items_id'];
                }
            }
        }

        // ---- which rows (people/groups) to show ----
        $rowKeys = [];
        $groupMembers = [];
        if ($groupFilter) {
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groupFilter]]) as $r) {
                $groupMembers['user:' . (int) $r['users_id']] = true;
            }
            $groupMembers['group:' . $groupFilter] = true;
        }
        if ($projectFilter) {
            foreach ($DB->request(['SELECT' => ['itemtype', 'items_id'], 'FROM' => ProjectTeam::getTable(),
                'WHERE' => ['projects_id' => $projectFilter, 'itemtype' => [User::class, Group::class]]]) as $r) {
                $rowKeys[($r['itemtype'] === User::class ? 'user' : 'group') . ':' . (int) $r['items_id']] = true;
            }
        }
        foreach ($tasks as $id => $task) {
            $pid = (int) $task['projects_id'];
            if (!isset($visible[$pid])) continue;
            if ($projectFilter && $pid !== $projectFilter) continue;
            $keys = $team[$id] ?? ['none:0'];
            foreach ($keys as $k) $rowKeys[$k] = true;
        }
        if ($groupFilter) {
            // Group filter: only the group and its members; in the global view members with no
            // load are listed too (free capacity is the point).
            if (!$projectFilter) $rowKeys += array_filter($groupMembers, static fn($k) => str_starts_with($k, 'user:'), ARRAY_FILTER_USE_KEY);
            $rowKeys = array_intersect_key($rowKeys, $groupMembers);
        }

        $rows = [];
        foreach (array_keys($rowKeys) as $key) $rows[$key] = $this->emptyRow($key, $weeks);

        // ---- distribute task hours ----
        $unscheduled = 0;
        $noEstimate = [];
        foreach ($tasks as $id => $task) {
            $keys = $team[$id] ?? ['none:0'];
            $mine = array_values(array_filter($keys, static fn($k) => isset($rows[$k])));
            if (!$mine) continue;
            $pid = (int) $task['projects_id'];
            $isVisible = isset($visible[$pid]);
            // "Sem responsável" only gathers unassigned tasks the user can see (and, in a project, of that project).
            if (!isset($team[$id]) && (!$isVisible || ($projectFilter && $pid !== $projectFilter))) continue;
            $planned = (int) $task['planned_duration'];
            if ($planned <= 0) {
                if ($isVisible && (!$projectFilter || $pid === $projectFilter)) {
                    foreach ($mine as $k) $rows[$k]['no_estimate']++;
                    $noEstimate[$id] = true;
                }
                continue;
            }
            $seconds = $mode === 'remaining' ? (int) round($planned * max(0, 100 - (int) $task['percent_done']) / 100) : $planned;
            if ($seconds <= 0) continue;

            [$from, $to, $overdue] = $this->period($task, $mode);
            if ($from === null) { $unscheduled++; continue; }
            $calendarId = $this->calendarForEntity((int) $task['project_entity']);
            $days = $this->workingDays($calendarId, $from, $to);
            // Nothing workable in the period (weekend, holiday, overdue on a Sunday): next working day.
            if (!$days) $days = [$this->nextWorkingDay($calendarId, $from) => 1];
            $share = $seconds / count($keys);
            $weights = array_sum($days);

            $taskInfo = null;
            foreach ($mine as $k) {
                $byWeek = [];
                foreach ($days as $day => $w) {
                    if ($day < $this->windowStart || $day > $this->windowEnd) continue;
                    $idx = intdiv((int) ((strtotime($day) - $startTs) / self::DAY), 7);
                    $byWeek[$idx] = ($byWeek[$idx] ?? 0) + $share * $w / $weights;
                }
                if (!$byWeek) continue;
                $bucket = !$isVisible ? 'hidden' : (($projectFilter && $pid !== $projectFilter) ? 'other' : 'main');
                foreach ($byWeek as $idx => $sec) {
                    $min = $sec / 60;
                    $rows[$k]['load'][$idx]['total'] += $min;
                    $rows[$k]['load'][$idx][$bucket] += $min;
                    if ($overdue) $rows[$k]['load'][$idx]['overdue'] += $min;
                }
                if ($isVisible) {
                    $taskInfo ??= [
                        'id' => $id,
                        'name' => (string) $task['name'],
                        'project_id' => $pid,
                        'project_name' => (string) $task['project_name'],
                        'url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $id,
                        'from' => $from,
                        'to' => $to,
                        'percent' => (int) $task['percent_done'],
                        'overdue' => $overdue,
                        'other_project' => $bucket === 'other',
                        'assignees' => count($keys),
                    ];
                    $rows[$k]['tasks'][] = $taskInfo + ['weeks' => array_map(static fn($s) => (int) round($s / 60), $byWeek)];
                }
            }
        }

        // ---- names, capacity, totals ----
        $out = [];
        $overloaded = 0;
        $sumLoad = 0;
        $sumCap = 0;
        foreach ($rows as $key => $row) {
            [$type, $id] = explode(':', $key);
            $id = (int) $id;
            $row['type'] = $type;
            $row['id'] = $id;
            if ($type === 'user') {
                $row['name'] = (string) getUserName($id);
                $calendarId = $this->calendarForEntity($this->userEntity($id));
                foreach ($weekList as $i => $w) {
                    // "Restante": days already gone are not capacity any more.
                    $from = $mode === 'remaining' && $w['start'] < $this->today ? $this->today : $w['start'];
                    $row['capacity'][$i] = $from > $w['end'] ? 0 : (int) round(array_sum($this->workingDays($calendarId, $from, $w['end'], true)) / 60);
                }
            } elseif ($type === 'group') {
                $row['name'] = (string) \Dropdown::getDropdownName(Group::getTable(), $id);
                $row['capacity'] = array_fill(0, $weeks, 0);
            } else {
                $row['name'] = 'Sem responsável';
                $row['capacity'] = array_fill(0, $weeks, 0);
            }
            $row['initials'] = $this->initials($row['name']);
            $peak = 0;
            foreach ($row['load'] as $i => &$cell) {
                foreach (['total', 'main', 'other', 'hidden', 'overdue'] as $f) $cell[$f] = (int) round($cell[$f]);
                $cap = (int) $row['capacity'][$i];
                $cell['pct'] = $cap > 0 ? (int) round($cell['total'] * 100 / $cap) : null;
                $cell['no_capacity'] = $type === 'user' && $cap <= 0 && $cell['total'] > 0;
                if ($cell['pct'] !== null) $peak = max($peak, $cell['pct']);
            }
            unset($cell);
            $row['total'] = array_sum(array_column($row['load'], 'total'));
            $row['capacity_total'] = array_sum($row['capacity']);
            $row['pct'] = $row['capacity_total'] > 0 ? (int) round($row['total'] * 100 / $row['capacity_total']) : null;
            $row['peak'] = $peak;
            $row['overloaded_weeks'] = count(array_filter($row['load'], static fn($c) => ($c['pct'] !== null && $c['pct'] > 100) || $c['no_capacity']));
            if ($type === 'user') {
                $sumLoad += $row['total'];
                $sumCap += $row['capacity_total'];
                if ($row['overloaded_weeks']) $overloaded++;
            }
            usort($row['tasks'], static fn($a, $b) => strcmp($a['to'], $b['to']) ?: ($a['id'] <=> $b['id']));
            $out[] = $row;
        }
        $order = ['user' => 0, 'group' => 1, 'none' => 2];
        usort($out, static fn($a, $b) => ($order[$a['type']] <=> $order[$b['type']])
            ?: ($b['peak'] <=> $a['peak']) ?: ($b['total'] <=> $a['total']) ?: strnatcasecmp($a['name'], $b['name']));

        return [
            'ok' => true,
            'mode' => $mode,
            'start' => $this->windowStart,
            'end' => $this->windowEnd,
            'today' => $this->today,
            'weeks' => $weekList,
            'rows' => $out,
            'project_id' => $projectFilter,
            'focus' => $focusProject > 0,
            'group_id' => $groupFilter,
            'daily_minutes' => (int) ($this->dailyCap / 60),
            'summary' => [
                'people' => count(array_filter($out, static fn($r) => $r['type'] === 'user')),
                'overloaded' => $overloaded,
                'load' => $sumLoad,
                'capacity' => $sumCap,
                'pct' => $sumCap > 0 ? (int) round($sumLoad * 100 / $sumCap) : null,
                'no_estimate' => count($noEstimate),
                'unscheduled' => $unscheduled,
            ],
        ];
    }

    private function emptyRow(string $key, int $weeks): array
    {
        return [
            'key' => $key,
            'load' => array_fill(0, $weeks, ['total' => 0, 'main' => 0, 'other' => 0, 'hidden' => 0, 'overdue' => 0]),
            'capacity' => array_fill(0, $weeks, 0),
            'tasks' => [],
            'no_estimate' => 0,
        ];
    }

    /** @return array{0:?string,1:?string,2:bool} first day, last day, is overdue */
    private function period(array $task, string $mode): array
    {
        $start = $task['plan_start_date'] ? substr((string) $task['plan_start_date'], 0, 10) : null;
        $end = $task['plan_end_date'] ? substr((string) $task['plan_end_date'], 0, 10) : null;
        $start ??= $end;
        $end ??= $start;
        if ($start === null) return [null, null, false];
        if ($end < $start) [$start, $end] = [$end, $start];
        if ($mode === 'remaining') {
            if ($end < $this->today) return [$this->today, $this->today, true];
            if ($start < $this->today) $start = $this->today;
        }
        return [$start, $end, false];
    }

    /**
     * Working days between two dates (inclusive) with their weight (seconds of capacity).
     * With $capacity=false the weights are used only to spread task hours.
     * @return array<string,int>
     */
    private function workingDays(int $calendarId, string $from, string $to, bool $capacity = false): array
    {
        $out = [];
        $ts = strtotime($from);
        $end = strtotime($to);
        $guard = 0;
        while ($ts <= $end && $guard++ < 800) {
            $day = date('Y-m-d', $ts);
            $sec = $this->capacityOf($calendarId, $day);
            if ($sec > 0) $out[$day] = $sec;
            $ts = strtotime('+1 day', $ts);
        }
        return $out;
    }

    private function nextWorkingDay(int $calendarId, string $from): string
    {
        $ts = strtotime($from);
        for ($i = 0; $i < 31; $i++) {
            $day = date('Y-m-d', strtotime("+$i days", $ts));
            if ($this->capacityOf($calendarId, $day) > 0) return $day;
        }
        return $from;
    }

    private function capacityOf(int $calendarId, string $day): int
    {
        if (isset($this->dayCapacity[$calendarId][$day])) return $this->dayCapacity[$calendarId][$day];
        $ts = strtotime($day);
        $cal = $this->calendar($calendarId);
        if ($cal) {
            $durations = $cal->getDurationsCache();
            $dow = (int) date('w', $ts);
            $sec = (int) ($durations[$dow] ?? 0);
            if ($sec > 0 && $cal->isHoliday($day)) $sec = 0;
            $sec = min($sec, $this->dailyCap);
        } else {
            $dow = (int) date('N', $ts);
            $sec = $dow <= 5 ? $this->dailyCap : 0;
        }
        return $this->dayCapacity[$calendarId][$day] = $sec;
    }

    private function calendar(int $id): ?Calendar
    {
        if ($id <= 0) return null;
        if (!array_key_exists($id, $this->calendars)) {
            $cal = new Calendar();
            $this->calendars[$id] = $cal->getFromDB($id) && $cal->hasAWorkingDay() ? $cal : null;
        }
        return $this->calendars[$id];
    }

    private function calendarForEntity(int $entityId): int
    {
        if (!isset($this->entityCalendar[$entityId])) {
            $id = (int) Entity::getUsedConfig('calendars_strategy', $entityId, 'calendars_id', 0);
            $this->entityCalendar[$entityId] = max(0, $id);
        }
        return $this->entityCalendar[$entityId];
    }

    private function userEntity(int $userId): int
    {
        static $cache = [];
        if (!isset($cache[$userId])) {
            $user = new User();
            $cache[$userId] = $user->getFromDB($userId) ? (int) $user->fields['entities_id'] : (int) Session::getActiveEntity();
        }
        return $cache[$userId];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}
