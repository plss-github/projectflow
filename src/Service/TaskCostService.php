<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use ProjectCost;
use ProjectTask;
use Session;

/**
 * Custos lançados nas tarefas, com aprovação da gestão do projeto.
 *
 * - Quem pode responder na tarefa lança o custo (descrição, data, valor). Só existe em projetos
 *   com controle financeiro (modo "Custo financeiro" ou "Horas + custo").
 * - Quem aprova: a gestão financeira do projeto (gerente, grupo gerente ou administrador, a mesma
 *   regra que vê valores). Custo lançado por quem aprova já nasce aprovado.
 * - Aprovar cria um custo nativo do projeto (ProjectCost): é ele que entra no financeiro do
 *   projeto, no plugin e nas telas nativas. Pendentes e recusados não contam.
 */
class TaskCostService
{
    public const TABLE = 'glpi_plugin_projectflow_taskcosts';
    public const STATUSES = ['pending', 'approved', 'rejected', 'removed'];

    public function __construct(
        private readonly MetaService $meta = new MetaService(),
        private readonly ProjectService $projects = new ProjectService(),
    ) {}

    /** Creates the table when the plugin was updated without running the install step again. */
    public static function ensureTable(): void
    {
        global $DB;
        if ($DB->tableExists(self::TABLE)) return;
        $DB->doQuery("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projects_id` INT UNSIGNED NOT NULL,
            `projecttasks_id` INT UNSIGNED NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `name` VARCHAR(255) NOT NULL,
            `comment` TEXT NULL,
            `cost` DECIMAL(20,4) NOT NULL DEFAULT 0,
            `cost_date` DATE NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
            `approver_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `decision_date` DATETIME NULL,
            `decision_comment` TEXT NULL,
            `projectcosts_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` DATETIME NOT NULL,
            `date_mod` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_project_status` (`projects_id`,`status`),
            KEY `idx_task` (`projecttasks_id`),
            KEY `idx_projectcost` (`projectcosts_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    /** Context of a task for costs: project, money mode and the current user's rights. */
    public function context(int $taskId): ?array
    {
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canViewItem()) return null;
        $projectId = (int) $task->fields['projects_id'];
        $project = new Project();
        if (!$project->getFromDB($projectId)) return null;
        $mode = (string) ($this->meta->getForProject($projectId)['cost_mode'] ?? 'hours');
        $enabled = MetaService::costModeHasMoney($mode) && empty($project->fields['is_template']);
        return [
            'task' => $task,
            'project' => $project,
            'enabled' => $enabled,
            'can_add' => $enabled && $task->canUpdateItem(),
            'can_approve' => $enabled && $this->canApprove($project),
        ];
    }

    public function canApprove(Project $project): bool
    {
        return $this->projects->canViewMoney($project);
    }

    public function forTask(int $taskId): array
    {
        $ctx = $this->context($taskId);
        if (!$ctx || !$ctx['enabled']) return [];
        self::ensureTable();
        return $this->rows(['projecttasks_id' => $taskId], $ctx['can_approve']);
    }

    /** Approvals tab: every cost of the project (approvers only). */
    public function forProject(int $projectId): array
    {
        $project = new Project();
        $empty = ['items' => [], 'pending' => 0, 'pending_total' => 0.0, 'approved_total' => 0.0, 'rejected' => 0,
            'pending_total_formatted' => ProjectService::brl(0), 'approved_total_formatted' => ProjectService::brl(0)];
        if (!$project->getFromDB($projectId) || !$project->canViewItem() || !$this->canApprove($project)) return $empty;
        if (!MetaService::costModeHasMoney((string) ($this->meta->getForProject($projectId)['cost_mode'] ?? 'hours'))) return $empty;
        self::ensureTable();
        $items = $this->rows(['projects_id' => $projectId], true, true);
        $out = $empty;
        $out['items'] = $items;
        foreach ($items as $it) {
            if ($it['status'] === 'pending') { $out['pending']++; $out['pending_total'] += $it['cost']; }
            elseif ($it['status'] === 'approved') $out['approved_total'] += $it['cost'];
            elseif ($it['status'] === 'rejected') $out['rejected']++;
        }
        $out['pending_total_formatted'] = ProjectService::brl($out['pending_total']);
        $out['approved_total_formatted'] = ProjectService::brl($out['approved_total']);
        return $out;
    }

    public function pendingCount(int $projectId): int
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return 0;
        return (int) countElementsInTable(self::TABLE, ['projects_id' => $projectId, 'status' => 'pending']);
    }

    public function add(int $taskId, array $input): array
    {
        $ctx = $this->context($taskId);
        if (!$ctx || !$ctx['can_add']) return ['ok' => false, 'message' => 'Este projeto não tem controle financeiro ou você não pode lançar custos nesta tarefa.'];
        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 255);
        if ($name === '') return ['ok' => false, 'message' => 'Informe a descrição do custo.'];
        $value = self::money($input['cost'] ?? 0);
        if ($value <= 0) return ['ok' => false, 'message' => 'Informe um valor maior que zero.'];
        $date = self::dateOnly($input['cost_date'] ?? null) ?? date('Y-m-d');

        global $DB;
        self::ensureTable();
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $DB->insert(self::TABLE, [
            'projects_id' => (int) $ctx['project']->getID(),
            'projecttasks_id' => $taskId,
            'users_id' => (int) Session::getLoginUserID(),
            'name' => $name,
            'comment' => mb_substr(trim(strip_tags((string) ($input['comment'] ?? ''))), 0, 4000),
            'cost' => $value,
            'cost_date' => $date,
            'status' => 'pending',
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        if ($id <= 0) return ['ok' => false, 'message' => 'Não foi possível lançar o custo.'];

        // Whoever approves does not need to approve their own cost.
        if ($ctx['can_approve']) {
            $this->decide($id, 'approve', 'Lançado pela gestão do projeto: aprovado automaticamente.');
            return ['ok' => true, 'id' => $id, 'message' => 'Custo lançado e aprovado: já conta no financeiro do projeto.'];
        }
        return ['ok' => true, 'id' => $id, 'message' => 'Custo lançado. Ele entra no projeto depois da aprovação da gestão.'];
    }

    /** @param string $decision approve|reject */
    public function decide(int $costId, string $decision, string $comment = ''): array
    {
        global $DB;
        self::ensureTable();
        $row = $this->find($costId);
        if (!$row) return ['ok' => false, 'message' => 'Custo não encontrado.'];
        $project = new Project();
        if (!$project->getFromDB((int) $row['projects_id']) || !$this->canApprove($project)) {
            return ['ok' => false, 'message' => 'Só a gestão do projeto aprova custos.'];
        }
        if ($row['status'] !== 'pending') return ['ok' => false, 'message' => 'Este custo já foi decidido.'];
        $comment = mb_substr(trim(strip_tags($comment)), 0, 4000);
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $uid = (int) Session::getLoginUserID();

        if ($decision === 'reject') {
            $DB->update(self::TABLE, ['status' => 'rejected', 'approver_id' => $uid, 'decision_date' => $now,
                'decision_comment' => $comment, 'date_mod' => $now], ['id' => $costId]);
            return ['ok' => true, 'message' => 'Custo recusado. Ele não entra no financeiro do projeto.'];
        }

        $task = new ProjectTask();
        $taskName = $task->getFromDB((int) $row['projecttasks_id']) ? (string) $task->fields['name'] : ('Tarefa #' . $row['projecttasks_id']);
        $note = sprintf("Custo da tarefa #%d (%s), lançado por %s e aprovado por %s em %s.",
            (int) $row['projecttasks_id'], $taskName, getUserName((int) $row['users_id']), getUserName($uid), date('d/m/Y H:i', strtotime($now)));
        if ($row['comment']) $note .= "\n" . $row['comment'];
        if ($comment) $note .= "\nAprovação: " . $comment;
        $cost = new ProjectCost();
        $nativeId = (int) $cost->add([
            'projects_id' => (int) $row['projects_id'],
            'name' => mb_substr('#' . (int) $row['projecttasks_id'] . ' · ' . $row['name'], 0, 255),
            'begin_date' => $row['cost_date'],
            'end_date' => $row['cost_date'],
            'cost' => (float) $row['cost'],
            'comment' => $note,
            'entities_id' => (int) $project->fields['entities_id'],
            'is_recursive' => (int) ($project->fields['is_recursive'] ?? 0),
        ]);
        if ($nativeId <= 0) return ['ok' => false, 'message' => 'Não foi possível registrar o custo no projeto (verifique o direito de editar o projeto).'];
        $DB->update(self::TABLE, ['status' => 'approved', 'approver_id' => $uid, 'decision_date' => $now,
            'decision_comment' => $comment, 'projectcosts_id' => $nativeId, 'date_mod' => $now], ['id' => $costId]);
        return ['ok' => true, 'message' => 'Custo aprovado: já conta no financeiro do projeto.'];
    }

    /** Author removes a pending cost; the project management removes any (an approved one leaves the project too). */
    public function remove(int $costId): array
    {
        global $DB;
        self::ensureTable();
        $row = $this->find($costId);
        if (!$row) return ['ok' => false, 'message' => 'Custo não encontrado.'];
        $project = new Project();
        if (!$project->getFromDB((int) $row['projects_id'])) return ['ok' => false, 'message' => 'Projeto não encontrado.'];
        $approver = $this->canApprove($project);
        $own = (int) $row['users_id'] === (int) Session::getLoginUserID() && $row['status'] === 'pending';
        if (!$approver && !$own) return ['ok' => false, 'message' => 'Você só pode remover os seus custos ainda não aprovados.'];
        $DB->delete(self::TABLE, ['id' => $costId]);
        if ((int) $row['projectcosts_id'] > 0) {
            $cost = new ProjectCost();
            if ($cost->getFromDB((int) $row['projectcosts_id'])) $cost->delete(['id' => (int) $row['projectcosts_id']], true);
        }
        return ['ok' => true, 'message' => $row['status'] === 'approved' ? 'Custo removido da tarefa e do financeiro do projeto.' : 'Custo removido.'];
    }

    /** Native project cost purged elsewhere (project screen, native form): the task cost no longer counts. */
    public static function onProjectCostPurge(int $projectCostId): void
    {
        global $DB;
        if ($projectCostId <= 0 || !$DB->tableExists(self::TABLE)) return;
        $DB->update(self::TABLE, ['status' => 'removed', 'projectcosts_id' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            ['projectcosts_id' => $projectCostId]);
    }

    /** ids of native ProjectCost rows that came from task costs => task info (for the Financeiro list). */
    public static function originOf(array $projectCostIds): array
    {
        global $DB;
        $projectCostIds = array_values(array_filter(array_map('intval', $projectCostIds)));
        if (!$projectCostIds || !$DB->tableExists(self::TABLE)) return [];
        $out = [];
        foreach ($DB->request(['SELECT' => ['projectcosts_id', 'projecttasks_id', 'users_id'], 'FROM' => self::TABLE,
            'WHERE' => ['projectcosts_id' => $projectCostIds]]) as $r) {
            $out[(int) $r['projectcosts_id']] = ['task_id' => (int) $r['projecttasks_id'], 'user_name' => (string) getUserName((int) $r['users_id'])];
        }
        return $out;
    }

    private function find(int $id): ?array
    {
        global $DB;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        return $it->count() ? $it->current() : null;
    }

    private function rows(array $where, bool $canApprove, bool $withTask = false): array
    {
        global $DB;
        $uid = (int) Session::getLoginUserID();
        $taskNames = [];
        $items = [];
        $labels = ['pending' => 'Aguardando aprovação', 'approved' => 'Aprovado', 'rejected' => 'Recusado', 'removed' => 'Removido do projeto'];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDERBY' => ['date_creation DESC', 'id DESC']]) as $r) {
            $own = (int) $r['users_id'] === $uid;
            $taskId = (int) $r['projecttasks_id'];
            if ($withTask && !isset($taskNames[$taskId])) {
                $t = new ProjectTask();
                $taskNames[$taskId] = $t->getFromDB($taskId) ? (string) $t->fields['name'] : ('Tarefa #' . $taskId);
            }
            $status = in_array($r['status'], self::STATUSES, true) ? $r['status'] : 'pending';
            $showValue = $canApprove || $own;
            $items[] = [
                'id' => (int) $r['id'],
                'task_id' => $taskId,
                'task_name' => $taskNames[$taskId] ?? '',
                'task_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/front/task.php?id=' . $taskId,
                'name' => (string) $r['name'],
                'comment' => (string) ($r['comment'] ?? ''),
                'cost' => $showValue ? (float) $r['cost'] : 0.0,
                'cost_formatted' => $showValue ? ProjectService::brl((float) $r['cost']) : null,
                'cost_date' => $r['cost_date'],
                'status' => $status,
                'status_label' => $labels[$status],
                'user_id' => (int) $r['users_id'],
                'user_name' => (string) getUserName((int) $r['users_id']),
                'approver_name' => (int) $r['approver_id'] ? (string) getUserName((int) $r['approver_id']) : '',
                'decision_date' => $r['decision_date'],
                'decision_comment' => (string) ($r['decision_comment'] ?? ''),
                'date_creation' => (string) $r['date_creation'],
                'can_decide' => $canApprove && $status === 'pending',
                'can_delete' => $canApprove || ($own && $status === 'pending'),
            ];
        }
        return $items;
    }

    private static function money(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') return 0.0;
        $raw = preg_replace('/[^0-9,.-]/', '', $raw) ?? '0';
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        return max(0.0, min(999999999.99, round((float) $raw, 2)));
    }

    private static function dateOnly(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
