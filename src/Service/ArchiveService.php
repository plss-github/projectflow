<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use Session;

/**
 * Arquivamento de projetos e templates.
 *
 * Um projeto arquivado sai do portfólio, do Kanban global, de Minhas tarefas e da Carga de
 * trabalho, e abre só para leitura no Project Flow, com o botão Desarquivar. Um template
 * arquivado deixa de aparecer na escolha de template. Quem arquiva/desarquiva: quem pode
 * editar o projeto (direito UPDATE no item).
 */
class ArchiveService
{
    public const TABLE = 'glpi_plugin_projectflow_archives';

    /** @var array<int,bool>|null */
    private static ?array $ids = null;

    public static function ensureTable(): void
    {
        global $DB;
        if ($DB->tableExists(self::TABLE)) return;
        $DB->doQuery("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `projects_id` INT UNSIGNED NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_archived` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_project` (`projects_id`),
            KEY `idx_user` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
    }

    /** @return array<int,bool> archived project/template ids */
    public static function archivedIds(): array
    {
        global $DB;
        if (self::$ids !== null) return self::$ids;
        self::$ids = [];
        if (!$DB->tableExists(self::TABLE)) return self::$ids;
        foreach ($DB->request(['SELECT' => ['projects_id'], 'FROM' => self::TABLE]) as $r) {
            self::$ids[(int) $r['projects_id']] = true;
        }
        return self::$ids;
    }

    public static function isArchived(int $projectId): bool
    {
        return isset(self::archivedIds()[$projectId]);
    }

    /** @return array{user_name:string,date:string}|null */
    public static function info(int $projectId): ?array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) return null;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['projects_id' => $projectId], 'LIMIT' => 1]);
        if (!$it->count()) return null;
        $row = $it->current();
        return ['user_name' => (int) $row['users_id'] ? (string) getUserName((int) $row['users_id']) : '', 'date' => (string) $row['date_archived']];
    }

    public static function canArchive(int $projectId): bool
    {
        $project = new Project();
        return $project->getFromDB($projectId) && $project->canViewItem() && $project->can($projectId, UPDATE);
    }

    public function archive(int $projectId): array
    {
        if (!self::canArchive($projectId)) return ['ok' => false, 'message' => 'Você não pode arquivar este projeto.'];
        global $DB;
        self::ensureTable();
        if (!self::isArchived($projectId)) {
            $DB->insert(self::TABLE, [
                'projects_id' => $projectId,
                'users_id' => (int) Session::getLoginUserID(),
                'date_archived' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ]);
        }
        self::$ids = null;
        $project = new Project();
        $project->getFromDB($projectId);
        return ['ok' => true, 'message' => !empty($project->fields['is_template']) ? 'Template arquivado: ele não aparece mais na escolha de template.' : 'Projeto arquivado. Ele fica na tela Arquivados.'];
    }

    public function unarchive(int $projectId): array
    {
        if (!self::canArchive($projectId)) return ['ok' => false, 'message' => 'Você não pode desarquivar este projeto.'];
        global $DB;
        if ($DB->tableExists(self::TABLE)) $DB->delete(self::TABLE, ['projects_id' => $projectId]);
        self::$ids = null;
        return ['ok' => true, 'message' => 'Desarquivado: voltou para as listas.'];
    }

    public static function forget(int $projectId): void
    {
        global $DB;
        if ($DB->tableExists(self::TABLE)) $DB->delete(self::TABLE, ['projects_id' => $projectId]);
        self::$ids = null;
    }

    /** Project of a write request (project or task endpoint), when it is archived. */
    public static function blocksWrite(string $endpoint, string $action, array $input): bool
    {
        if (!self::archivedIds()) return false;
        if ($endpoint === 'project') {
            if (in_array($action, ['archive', 'unarchive', 'favorite', 'report_generate', 'report_ai', 'template_info', 'create', 'template_create'], true)) return false;
            $pid = (int) ($input['project_id'] ?? $input['id'] ?? 0);
            return $pid > 0 && self::isArchived($pid);
        }
        if ($action === 'get' || str_starts_with($action, 'asset_search')) return false;
        if ($action === 'create') return self::isArchived((int) ($input['project_id'] ?? 0));
        $taskId = (int) ($input['task_id'] ?? $input['id'] ?? 0);
        if ($taskId <= 0) {
            $ids = array_filter(array_map('intval', (array) ($input['ids'] ?? $input['task_ids'] ?? [])));
            $taskId = (int) (reset($ids) ?: 0);
        }
        if ($taskId <= 0) return isset($input['project_id']) && self::isArchived((int) $input['project_id']);
        $task = new \ProjectTask();
        return $task->getFromDB($taskId) && self::isArchived((int) $task->fields['projects_id']);
    }
}
