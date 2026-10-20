<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use Session;

/**
 * Project status report (weekly or whole project).
 *
 * build() computes every fact and number deterministically (status, KPIs, deliveries, work in
 * progress, risks, meetings, next steps). The screen renders it as a document; the AI only adds
 * the narrative on top of these facts (AiReportService), so the numbers are always the plugin's.
 */
class WeeklyReportService
{
    private const TABLE = 'glpi_plugin_projectflow_weeklyreports';
    public const FORMAT_VERSION = 2;

    public function __construct(
        private readonly TaskService $tasks = new TaskService(),
        private readonly ActivityService $activities = new ActivityService(),
        private readonly WorklogService $worklogs = new WorklogService(),
        private readonly MeetingService $meetings = new MeetingService(),
        private readonly ProjectService $projects = new ProjectService(),
    ) {}

    /** Backwards compatible entry point: structured report + its plain-text version. */
    public function generate(int $projectId, mixed $weekStart = null, string $scope = 'week'): ?array
    {
        $data = $this->build($projectId, $scope, $weekStart);
        if ($data === null) return null;
        $data['text'] = $this->renderText($data);
        return $data;
    }

    /**
     * @param string $scope 'week' (week of $weekStart) or 'project' (whole history)
     */
    public function build(int $projectId, string $scope = 'week', mixed $weekStart = null): ?array
    {
        $p = $this->projects->getProjectCore($projectId);
        if ($p === null) return null;
        $scope = $scope === 'project' ? 'project' : 'week';
        [$start, $end] = $scope === 'week' ? $this->weekRange($weekStart) : ['2000-01-01', '2100-12-31'];
        $inPeriod = static fn(?string $d): bool => ($d = substr((string) $d, 0, 10)) !== '' && $d >= $start && $d <= $end;
        $today = date('Y-m-d');
        $day = 86400;

        $tasks = $this->tasks->getProjectTasks($projectId);
        $activities = $this->activities->getForProjectRange($projectId, $start, $end);
        $worklogs = $this->worklogs->getForProject($projectId, $start, $end, 1000);
        $meetingsAll = $this->meetings->getForProject($projectId, 500);
        $hoursPeriod = $this->worklogs->getSummary($projectId, $scope === 'week' ? $start : null, $scope === 'week' ? $end : null);
        $hoursTotal = $this->worklogs->getSummary($projectId);

        // Notes per task in the period (comments + execution descriptions), newest first.
        $notes = [];
        foreach ($activities as $a) $notes[(int) $a['task_id']][] = ['at' => (string) $a['date_creation'], 'who' => $a['user_name'], 'text' => $this->cut($a['content'], 220), 'kind' => 'comentário'];
        $taskMinutes = [];
        foreach ($worklogs as $w) {
            $tid = (int) $w['task_id'];
            if ($tid <= 0) continue;
            $taskMinutes[$tid] = ($taskMinutes[$tid] ?? 0) + (int) $w['minutes'];
            if (($w['work_type'] ?? 'execution') === 'execution' && trim((string) $w['comment']) !== '') {
                $notes[$tid][] = ['at' => (string) ($w['date_creation'] ?: $w['work_date']), 'who' => $w['user_name'], 'text' => $this->cut($w['comment'], 220), 'kind' => 'execução ' . $w['duration_label']];
            }
        }
        foreach ($notes as &$list) { usort($list, static fn($a, $b) => strcmp($b['at'], $a['at'])); } unset($list);

        $meetings = [];
        $meetingTasks = [];
        foreach ($meetingsAll as $m) {
            if (!$inPeriod($m['start_at'] ?? null)) continue;
            if ((int) $m['task_id'] > 0) $meetingTasks[(int) $m['task_id']] = true;
            $meetings[] = [
                'date' => substr((string) $m['start_at'], 0, 16), 'title' => (string) $m['title'], 'duration' => $m['duration_label'],
                'summary' => $this->cut($m['summary'], 400), 'decisions' => $this->cut($m['decisions'], 400), 'actions' => $this->cut($m['actions'], 400),
            ];
        }
        usort($meetings, static fn($a, $b) => strcmp($b['date'], $a['date']));

        $owner = static fn(array $t): string => (string) (($t['team_summary'][0]['name'] ?? '') ?: '');
        $pctSum = 0;
        $delivered = []; $progress = []; $overdue = []; $attention = []; $open = [];
        $doneTotal = 0;
        foreach ($tasks as $t) {
            $id = (int) $t['id'];
            // A real end date means the work is done (same rule as the schedule).
            $finished = !empty($t['state']['is_finished']) || (int) $t['percent_done'] >= 100 || !empty($t['real_end_date']);
            $planEnd = $t['plan_end_date'] ? substr((string) $t['plan_end_date'], 0, 10) : null;
            $pctSum += $finished ? 100 : max(0, min(100, (int) $t['percent_done']));
            if ($finished) {
                $doneTotal++;
                $doneAt = $t['real_end_date'] ? substr((string) $t['real_end_date'], 0, 10) : substr((string) $t['date_mod'], 0, 10);
                if ($scope === 'project' || $inPeriod($doneAt)) {
                    $delivered[] = [
                        'name' => $t['name'], 'date' => $doneAt, 'owner' => $owner($t), 'milestone' => (bool) $t['is_milestone'],
                        'variance_days' => ($planEnd && $doneAt) ? (int) round((strtotime($doneAt) - strtotime($planEnd)) / $day) : null,
                    ];
                }
                continue;
            }
            $open[] = $t;
            $touched = !empty($notes[$id]) || !empty($taskMinutes[$id]) || !empty($meetingTasks[$id]) || $inPeriod($t['date_mod'] ?? null);
            if ($touched || ($scope === 'project' && (int) $t['percent_done'] > 0)) {
                $progress[] = [
                    'name' => $t['name'], 'percent' => (int) $t['percent_done'], 'state' => $t['state']['name'] ?? '', 'state_color' => $t['state']['color'] ?? '',
                    'deadline' => $planEnd, 'late' => (bool) $t['is_overdue'], 'owner' => $owner($t),
                    'hours' => !empty($taskMinutes[$id]) ? WorklogService::formatMinutes($taskMinutes[$id]) : null,
                    'notes' => array_slice($notes[$id] ?? [], 0, 3),
                ];
            }
            if ($t['is_overdue'] && $planEnd) {
                $overdue[] = ['name' => $t['name'], 'deadline' => $planEnd, 'days' => max(1, (int) round((strtotime($today) - strtotime($planEnd)) / $day)), 'owner' => $owner($t), 'percent' => (int) $t['percent_done']];
            }
            if (!empty($t['attention'])) $attention[] = ['name' => $t['name'], 'note' => (string) $t['attention_note'], 'owner' => $owner($t)];
        }
        usort($delivered, static fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        usort($overdue, static fn($a, $b) => $b['days'] <=> $a['days']);
        usort($progress, static fn($a, $b) => ($b['late'] <=> $a['late']) ?: ($b['percent'] <=> $a['percent']));
        usort($open, static fn($a, $b) => (($a['plan_end_ts'] ?? PHP_INT_MAX) <=> ($b['plan_end_ts'] ?? PHP_INT_MAX)) ?: ($a['id'] <=> $b['id']));
        $next = [];
        foreach (array_slice($open, 0, 8) as $t) {
            $next[] = ['name' => $t['name'], 'owner' => $owner($t), 'deadline' => $t['plan_end_date'] ? substr((string) $t['plan_end_date'], 0, 10) : null, 'late' => (bool) $t['is_overdue'], 'state' => $t['state']['name'] ?? ''];
        }

        // Hours vs budget
        $budget = max(0, (int) ($p['hours_budget_minutes'] ?? 0));
        $used = (int) $hoursTotal['total_minutes'];
        $budgetPct = $budget > 0 ? (int) round($used / $budget * 100) : null;

        // Risks found in the data (the AI may rewrite them, never invent numbers).
        $risks = [];
        if (!empty($p['is_overdue'])) $risks[] = ['title' => 'Projeto com prazo vencido', 'detail' => 'A data limite do projeto (' . $this->br($p['plan_end_date']) . ') já passou.', 'severity' => 'alta', 'action' => ''];
        foreach (array_slice($overdue, 0, 4) as $o) {
            $risks[] = ['title' => 'Atraso: ' . $o['name'], 'detail' => $o['days'] . ' dia(s) após o prazo de ' . $this->br($o['deadline']) . ', com ' . $o['percent'] . '% concluído' . ($o['owner'] ? ' (' . $o['owner'] . ')' : '') . '.', 'severity' => $o['days'] > 7 ? 'alta' : 'media', 'action' => ''];
        }
        if (count($overdue) > 4) $risks[] = ['title' => 'Outras ' . (count($overdue) - 4) . ' tarefa(s) em atraso', 'detail' => 'Ver lista de tarefas do projeto.', 'severity' => 'media', 'action' => ''];
        foreach (array_slice($attention, 0, 4) as $a) {
            $risks[] = ['title' => 'Ponto de atenção: ' . $a['name'], 'detail' => $a['note'] ?: 'Marcado como ponto de atenção.', 'severity' => 'media', 'action' => ''];
        }
        if ($budgetPct !== null && $budgetPct >= 100) $risks[] = ['title' => 'Horas acima do previsto', 'detail' => 'Consumidas ' . WorklogService::formatMinutes($used) . ' de ' . WorklogService::formatMinutes($budget) . ' (' . $budgetPct . '%).', 'severity' => 'alta', 'action' => ''];
        elseif ($budgetPct !== null && $budgetPct >= 85) $risks[] = ['title' => 'Horas próximas do limite', 'detail' => $budgetPct . '% das horas previstas já consumidas.', 'severity' => 'media', 'action' => ''];
        if (!empty($p['is_paused'])) $risks[] = ['title' => 'Projeto pausado', 'detail' => 'Estado atual: ' . ($p['state']['name'] ?? '') . '.', 'severity' => 'media', 'action' => ''];

        // Status: the health set by hand in the project wins; otherwise it follows this report's facts.
        $reasons = ['critico' => [], 'atencao' => []];
        if (!empty($p['is_overdue'])) $reasons['critico'][] = 'prazo do projeto vencido';
        if (count($overdue) >= 3) $reasons['critico'][] = count($overdue) . ' tarefas em atraso';
        elseif ($overdue) $reasons['atencao'][] = count($overdue) . ' tarefa(s) em atraso';
        if ($budgetPct !== null && $budgetPct >= 100) $reasons['critico'][] = 'horas acima do previsto';
        elseif ($budgetPct !== null && $budgetPct >= 85) $reasons['atencao'][] = $budgetPct . '% das horas consumidas';
        if ($attention) $reasons['atencao'][] = count($attention) . ' ponto(s) de atenção';
        if (!empty($p['is_paused'])) $reasons['atencao'][] = 'projeto pausado';
        $manual = (string) ($p['health_configured'] ?? 'auto');
        $status = match (true) {
            $manual === 'critical' => 'critico', $manual === 'attention' => 'atencao', $manual === 'good' => 'no_prazo',
            (bool) $reasons['critico'] => 'critico', (bool) $reasons['atencao'] => 'atencao', default => 'no_prazo',
        };
        $periodLabel = $scope === 'week' ? $this->br($start) . ' a ' . $this->br($end) : 'Todo o projeto (até ' . $this->br($today) . ')';

        $data = [
            'version' => self::FORMAT_VERSION,
            'project_id' => $projectId,
            'scope' => $scope,
            'week_start' => $scope === 'week' ? $start : null,
            'week_end' => $scope === 'week' ? $end : null,
            'period_label' => $periodLabel,
            'generated_at' => date('Y-m-d H:i'),
            'source' => 'auto',
            'project' => [
                'name' => (string) $p['name'], 'code' => (string) $p['code'], 'state' => $p['state']['name'] ?? '', 'state_color' => $p['state']['color'] ?? '',
                'manager' => (string) ($p['manager_name'] ?? ''), 'deadline' => $p['plan_end_date'] ? substr((string) $p['plan_end_date'], 0, 10) : null,
                'objective' => $this->cut($p['objective'] ?? '', 500), 'risk_level' => (string) ($p['risk_level'] ?? 'normal'),
            ],
            'status' => $status,
            'headline' => '',
            'kpis' => [
                'progress' => $tasks ? (int) round($pctSum / count($tasks)) : (int) ($p['percent_done'] ?? 0),
                'tasks_total' => count($tasks), 'tasks_done' => $doneTotal,
                'delivered_period' => count($delivered), 'in_progress' => count($progress),
                'overdue' => count($overdue), 'attention' => count($attention),
                'hours_period' => $hoursPeriod['total_label'], 'hours_period_exec' => $hoursPeriod['execution_label'], 'hours_period_meet' => $hoursPeriod['meeting_label'],
                'hours_total' => $hoursTotal['total_label'],
                'hours_budget' => $budget > 0 ? WorklogService::formatMinutes($budget) : null,
                'hours_budget_pct' => $budgetPct,
                'hours_balance' => $budget > 0 ? ($used <= $budget ? WorklogService::formatMinutes($budget - $used) . ' de saldo' : WorklogService::formatMinutes($used - $budget) . ' acima') : null,
            ],
            'summary' => '',
            'highlights' => [],
            'delivered' => array_slice($delivered, 0, 15),
            'progress' => array_slice($progress, 0, 12),
            'risks' => $risks,
            'meetings' => array_slice($meetings, 0, 8),
            'next_steps' => $next,
            'client_asks' => [],
        ];
        $data['summary'] = $this->autoSummary($data, $overdue);
        $data['headline'] = $this->autoHeadline($data, $reasons);
        return $data;
    }

    /** Plain text version (e-mail / copy), from the structured report. */
    public function renderText(array $d): string
    {
        $k = $d['kpis'];
        $statusLabel = ['no_prazo' => 'No prazo', 'atencao' => 'Atenção', 'critico' => 'Crítico'][$d['status']] ?? $d['status'];
        $L = [];
        $L[] = 'RELATÓRIO DE STATUS - ' . $d['project']['name'];
        $L[] = 'Período: ' . $d['period_label'] . ' · Status: ' . $statusLabel . ' · Andamento: ' . $k['progress'] . '%';
        if ($d['headline']) { $L[] = ''; $L[] = $d['headline']; }
        $L[] = ''; $L[] = 'RESUMO EXECUTIVO'; $L[] = $d['summary'];
        $L[] = ''; $L[] = 'INDICADORES';
        $L[] = '• Tarefas concluídas: ' . $k['tasks_done'] . ' de ' . $k['tasks_total'] . ($d['scope'] === 'week' ? ' (' . $k['delivered_period'] . ' no período)' : '');
        $L[] = '• Em atraso: ' . $k['overdue'] . ' · Pontos de atenção: ' . $k['attention'];
        $L[] = '• Horas ' . ($d['scope'] === 'week' ? 'no período: ' . $k['hours_period'] . ' (' . $k['hours_period_exec'] . ' execução, ' . $k['hours_period_meet'] . ' reuniões) · acumulado: ' : 'acumuladas: ') . $k['hours_total'] . ($k['hours_budget'] ? ' de ' . $k['hours_budget'] . ' (' . $k['hours_balance'] . ')' : '');
        if ($d['highlights']) { $L[] = ''; $L[] = 'DESTAQUES'; foreach ($d['highlights'] as $h) $L[] = '• ' . $h; }
        $L[] = ''; $L[] = 'ENTREGAS CONCLUÍDAS';
        if (!$d['delivered']) $L[] = '• Nenhuma entrega concluída no período.';
        foreach ($d['delivered'] as $x) $L[] = '• ' . $x['name'] . ' — ' . $this->br($x['date']) . ($x['variance_days'] > 0 ? ' (' . $x['variance_days'] . 'd após o prazo)' : ($x['variance_days'] !== null && $x['variance_days'] < 0 ? ' (' . abs($x['variance_days']) . 'd antes do prazo)' : ''));
        $L[] = ''; $L[] = 'EM ANDAMENTO';
        if (!$d['progress']) $L[] = '• Nenhuma tarefa com movimentação no período.';
        foreach ($d['progress'] as $x) $L[] = '• ' . $x['name'] . ' — ' . $x['percent'] . '% · ' . $x['state'] . ($x['deadline'] ? ' · prazo ' . $this->br($x['deadline']) : '') . ($x['late'] ? ' (atrasada)' : '') . ($x['notes'] ? ' · ' . $x['notes'][0]['text'] : '');
        $L[] = ''; $L[] = 'RISCOS E PONTOS DE ATENÇÃO';
        if (!$d['risks']) $L[] = '• Nenhum risco identificado nos dados do projeto.';
        foreach ($d['risks'] as $r) $L[] = '• [' . (['alta' => 'ALTA', 'media' => 'MÉDIA', 'baixa' => 'BAIXA'][$r['severity']] ?? mb_strtoupper($r['severity'])) . '] ' . $r['title'] . ($r['detail'] ? ' — ' . $r['detail'] : '') . ($r['action'] ? ' Ação: ' . $r['action'] : '');
        if ($d['meetings']) { $L[] = ''; $L[] = 'REUNIÕES E DECISÕES'; foreach ($d['meetings'] as $m) $L[] = '• ' . $this->br($m['date']) . ' — ' . $m['title'] . ($m['decisions'] ? ' · Decisões: ' . $m['decisions'] : '') . ($m['actions'] ? ' · Pendências: ' . $m['actions'] : ''); }
        $L[] = ''; $L[] = 'PRÓXIMOS PASSOS';
        if (!$d['next_steps']) $L[] = '• Nenhuma tarefa em aberto.';
        foreach ($d['next_steps'] as $n) $L[] = '• ' . ($n['action'] ?? $n['name']) . (!empty($n['owner']) ? ' — ' . $n['owner'] : '') . (!empty($n['deadline']) ? ' · até ' . $this->br($n['deadline']) : '');
        if ($d['client_asks']) { $L[] = ''; $L[] = 'DECISÕES / APOIO NECESSÁRIO DO CLIENTE'; foreach ($d['client_asks'] as $a) $L[] = '• ' . $a; }
        $L[] = ''; $L[] = 'Gerado pelo Pellissari Project em ' . $this->br($d['generated_at']) . ' ' . substr($d['generated_at'], 11, 5) . '.';
        return implode("\n", $L);
    }

    private function autoSummary(array $d, array $overdue): string
    {
        $k = $d['kpis'];
        $s = [];
        $s[] = sprintf('O projeto está em "%s", com %d%% de andamento (%d de %d tarefas concluídas).', $d['project']['state'] ?: 'andamento', $k['progress'], $k['tasks_done'], $k['tasks_total']);
        if ($d['scope'] === 'week') {
            $s[] = $k['delivered_period'] || $k['in_progress']
                ? sprintf('No período, %d entrega(s) foram concluídas e %d tarefa(s) tiveram avanço, com %s lançadas (%s em execução e %s em reuniões).', $k['delivered_period'], $k['in_progress'], $k['hours_period'], $k['hours_period_exec'], $k['hours_period_meet'])
                : 'Não houve movimentação registrada nas tarefas durante o período.';
        }
        if ($overdue) {
            $s[] = sprintf('Há %d tarefa(s) em atraso; a mais crítica é "%s", com %d dia(s) após o prazo.', count($overdue), $overdue[0]['name'], $overdue[0]['days']);
        } else {
            $s[] = 'Não há tarefas em atraso.';
        }
        if ($k['hours_budget']) $s[] = sprintf('Foram consumidas %s das %s previstas (%d%%).', $k['hours_total'], $k['hours_budget'], (int) $k['hours_budget_pct']);
        return implode(' ', $s);
    }

    private function autoHeadline(array $d, array $reasons): string
    {
        $k = $d['kpis'];
        $why = array_merge($reasons['critico'], $reasons['atencao']);
        $list = static fn(array $a): string => count($a) > 1 ? implode(', ', array_slice($a, 0, -1)) . ' e ' . end($a) : (string) ($a[0] ?? '');
        return match ($d['status']) {
            'critico' => 'Situação crítica' . ($why ? ': ' . $list($why) : '') . sprintf(' (%d%% concluído).', $k['progress']),
            'atencao' => 'Requer atenção' . ($why ? ': ' . $list($why) : '') . sprintf(' (%d%% concluído).', $k['progress']),
            default => sprintf('Dentro do previsto: %d%% concluído', $k['progress']) . ($d['scope'] === 'week' && $k['delivered_period'] ? sprintf(', %d entrega(s) no período.', $k['delivered_period']) : '.'),
        };
    }

    public function save(int $projectId, string $weekStart, string $content): int|false
    {
        global $DB;
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE) || !$DB->tableExists(self::TABLE)) return false;
        [$start, $end] = $this->weekRange($weekStart);
        $content = mb_substr(trim($content), 0, 400000);
        if ($content === '') return false;
        try {
            $DB->doQuery('START TRANSACTION');
            $DB->delete(self::TABLE, ['projects_id' => $projectId, 'week_start' => $start]);
            $ok = $DB->insert(self::TABLE, ['projects_id' => $projectId, 'week_start' => $start, 'week_end' => $end, 'content' => $content, 'users_id' => (int) Session::getLoginUserID(), 'date_generation' => date('Y-m-d H:i:s')]);
            if (!$ok) { $DB->doQuery('ROLLBACK'); return false; }
            $id = (int) $DB->insertId();
            $DB->doQuery('COMMIT');
            return $id;
        } catch (\Throwable $e) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) {}
            \Toolbox::logError('[Project Flow] weekly report transaction: ' . $e->getMessage());
            return false;
        }
    }

    public function getSaved(int $projectId, int $limit = 20): array
    {
        global $DB;
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->canViewItem() || !$DB->tableExists(self::TABLE)) return [];
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['projects_id' => $projectId], 'ORDERBY' => ['week_start DESC'], 'LIMIT' => max(1, min(100, $limit))]) as $r) {
            $content = (string) $r['content'];
            $json = str_starts_with(ltrim($content), '{') ? json_decode($content, true) : null;
            $out[] = [
                'id' => (int) $r['id'], 'week_start' => $r['week_start'], 'week_end' => $r['week_end'], 'content' => $content,
                'is_structured' => is_array($json), 'source' => is_array($json) ? (string) ($json['source'] ?? 'auto') : 'text',
                'status' => is_array($json) ? (string) ($json['status'] ?? '') : '',
                'user_name' => (int) $r['users_id'] ? getUserName((int) $r['users_id']) : 'Sistema', 'date_generation' => $r['date_generation'],
            ];
        }
        return $out;
    }

    public function weeksForProject(array $project, int $count = 16): array
    {
        $start = (new \DateTimeImmutable('today'))->modify('monday this week');
        $weeks = [];
        for ($i = 0; $i < $count; $i++) {
            $s = $start->modify('-' . $i . ' week');
            $e = $s->modify('+6 days');
            $weeks[] = ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d'), 'label' => $s->format('d/m/Y') . ' - ' . $e->format('d/m/Y'), 'current' => $i === 0];
        }
        return $weeks;
    }

    public function weekRange(mixed $value): array
    {
        $raw = trim((string) $value);
        try {
            $d = $raw !== '' ? new \DateTimeImmutable($raw) : new \DateTimeImmutable('today');
        } catch (\Throwable) {
            $d = new \DateTimeImmutable('today');
        }
        $monday = $d->modify('monday this week');
        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    private function br(?string $date): string
    {
        $ts = $date ? strtotime($date) : false;
        return $ts ? date('d/m/Y', $ts) : '—';
    }

    private function cut(mixed $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
