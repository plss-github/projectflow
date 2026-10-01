<?php

namespace GlpiPlugin\Projectflow\Service;

use Project;
use ProjectTeam;

/**
 * Funções da equipe do projeto (Gerente, Analista, Ponto focal do cliente...).
 * Cada projeto (e cada template) tem as suas próprias funções; cada membro (uma linha de
 * glpi_projectteams) recebe uma delas. Projetos criados de um template recebem cópias.
 */
class TeamRoleService
{
    private const ROLES = 'glpi_plugin_projectflow_roles';
    private const ASSIGN = 'glpi_plugin_projectflow_teamroles';

    /** Created on install/update and lazily, so copied files work without reinstalling. */
    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        global $DB;
        if (!$DB->tableExists(self::ROLES)) {
            $DB->doQuery("CREATE TABLE `" . self::ROLES . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projects_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(120) NOT NULL DEFAULT '',
                `color` VARCHAR(7) NOT NULL DEFAULT '#4263eb',
                `comment` VARCHAR(500) NOT NULL DEFAULT '',
                `date_mod` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_project_name` (`projects_id`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
        }
        if (!$DB->fieldExists(self::ROLES, 'projects_id')) {
            // 3.5.0 early builds had one global catalog: functions become per project.
            $DB->doQuery('ALTER TABLE `' . self::ROLES . '` ADD `projects_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`');
            if (self::hasIndex($DB, self::ROLES, 'uniq_name')) $DB->doQuery('ALTER TABLE `' . self::ROLES . '` DROP INDEX `uniq_name`');
            $DB->doQuery('ALTER TABLE `' . self::ROLES . '` ADD UNIQUE KEY `uniq_project_name` (`projects_id`, `name`)');
            self::$migrateGlobal = true;
        }
        if (!$DB->tableExists(self::ASSIGN)) {
            $DB->doQuery("CREATE TABLE `" . self::ASSIGN . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projectteams_id` INT UNSIGNED NOT NULL,
                `roles_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_member` (`projectteams_id`),
                KEY `idx_role` (`roles_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");
        }
    }

    private static bool $migrateGlobal = false;

    private static function hasIndex($DB, string $table, string $index): bool
    {
        foreach ($DB->doQuery("SHOW INDEX FROM `$table`") as $row) if (($row['Key_name'] ?? '') === $index) return true;
        return false;
    }

    /** Old global functions in use are copied into each project that uses them. */
    private static function migrateGlobalRoles(): void
    {
        global $DB;
        if (!self::$migrateGlobal || !$DB->tableExists(self::ASSIGN)) return;
        self::$migrateGlobal = false;
        foreach ($DB->request([
            'SELECT' => [self::ASSIGN . '.id AS aid', self::ASSIGN . '.roles_id', ProjectTeam::getTable() . '.projects_id', self::ROLES . '.name', self::ROLES . '.color', self::ROLES . '.comment'],
            'FROM' => self::ASSIGN,
            'INNER JOIN' => [
                self::ROLES => ['ON' => [self::ASSIGN => 'roles_id', self::ROLES => 'id']],
                ProjectTeam::getTable() => ['ON' => [self::ASSIGN => 'projectteams_id', ProjectTeam::getTable() => 'id']],
            ],
            'WHERE' => [self::ROLES . '.projects_id' => 0],
        ]) as $r) {
            $pid = (int) $r['projects_id'];
            $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::ROLES, 'WHERE' => ['projects_id' => $pid, 'name' => $r['name']], 'LIMIT' => 1])->current();
            $newId = $existing ? (int) $existing['id'] : ($DB->insert(self::ROLES, ['projects_id' => $pid, 'name' => $r['name'], 'color' => $r['color'], 'comment' => $r['comment'], 'date_mod' => date('Y-m-d H:i:s')]) ? (int) $DB->insertId() : 0);
            if ($newId) $DB->update(self::ASSIGN, ['roles_id' => $newId], ['id' => (int) $r['aid']]);
        }
        $DB->delete(self::ROLES, ['projects_id' => 0]);
    }

    public function listRoles(int $projectId): array
    {
        global $DB;
        self::ensureTables();
        self::migrateGlobalRoles();
        $usage = [];
        foreach ($DB->request(['SELECT' => ['roles_id'], 'FROM' => self::ASSIGN]) as $r) $usage[(int) $r['roles_id']] = ($usage[(int) $r['roles_id']] ?? 0) + 1;
        $out = [];
        foreach ($DB->request(['FROM' => self::ROLES, 'WHERE' => ['projects_id' => $projectId], 'ORDERBY' => ['name ASC']]) as $r) {
            $id = (int) $r['id'];
            $out[] = ['id' => $id, 'name' => (string) $r['name'], 'color' => (string) ($r['color'] ?: '#4263eb'), 'comment' => (string) $r['comment'], 'usage' => $usage[$id] ?? 0];
        }
        return $out;
    }

    /** Who can update the project (or template) maintains its functions. */
    public static function canManage(int $projectId): bool
    {
        $project = new Project();
        return $projectId > 0 && $project->getFromDB($projectId) && $project->can($projectId, UPDATE);
    }

    public function saveRole(int $projectId, array $input): int|false
    {
        global $DB;
        self::ensureTables();
        if (!self::canManage($projectId)) return false;
        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 120);
        if ($name === '') return false;
        $color = (string) ($input['color'] ?? '');
        $data = [
            'name' => $name,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4263eb',
            'comment' => mb_substr(trim(strip_tags((string) ($input['comment'] ?? ''))), 0, 500),
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        $id = (int) ($input['role_id'] ?? 0);
        $dup = $DB->request(['SELECT' => ['id'], 'FROM' => self::ROLES, 'WHERE' => ['projects_id' => $projectId, 'name' => $name], 'LIMIT' => 1])->current();
        if ($dup && (int) $dup['id'] !== $id) return false;
        if ($id > 0) {
            if (!countElementsInTable(self::ROLES, ['id' => $id, 'projects_id' => $projectId])) return false;
            return $DB->update(self::ROLES, $data, ['id' => $id]) ? $id : false;
        }
        return $DB->insert(self::ROLES, $data + ['projects_id' => $projectId]) ? (int) $DB->insertId() : false;
    }

    /** Deleting a function also removes it from the members that had it. */
    public function deleteRole(int $projectId, int $roleId): bool
    {
        global $DB;
        self::ensureTables();
        if (!self::canManage($projectId) || $roleId <= 0) return false;
        if (!countElementsInTable(self::ROLES, ['id' => $roleId, 'projects_id' => $projectId])) return false;
        $DB->delete(self::ASSIGN, ['roles_id' => $roleId]);
        return (bool) $DB->delete(self::ROLES, ['id' => $roleId]);
    }

    /** roleId 0 = sem função. */
    public function assign(int $projectId, int $teamRelationId, int $roleId): bool
    {
        global $DB;
        self::ensureTables();
        $project = new Project();
        if (!$project->getFromDB($projectId) || !$project->can($projectId, UPDATE)) return false;
        $rel = new ProjectTeam();
        if (!$rel->getFromDB($teamRelationId) || (int) $rel->fields['projects_id'] !== $projectId) return false;
        $DB->delete(self::ASSIGN, ['projectteams_id' => $teamRelationId]);
        if ($roleId <= 0) return true;
        if (!countElementsInTable(self::ROLES, ['id' => $roleId, 'projects_id' => $projectId])) return false;
        return (bool) $DB->insert(self::ASSIGN, ['projectteams_id' => $teamRelationId, 'roles_id' => $roleId]);
    }

    /** [projectteams_id => role] for the given team relations. */
    public function rolesForRelations(array $relationIds): array
    {
        global $DB;
        self::ensureTables();
        $relationIds = array_values(array_filter(array_map('intval', $relationIds)));
        if (!$relationIds) return [];
        self::migrateGlobalRoles();
        $roles = [];
        foreach ($DB->request(['FROM' => self::ROLES]) as $r) $roles[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'color' => (string) ($r['color'] ?: '#4263eb'), 'comment' => (string) $r['comment']];
        $out = [];
        foreach ($DB->request(['FROM' => self::ASSIGN, 'WHERE' => ['projectteams_id' => $relationIds]]) as $a) {
            if (isset($roles[(int) $a['roles_id']])) $out[(int) $a['projectteams_id']] = $roles[(int) $a['roles_id']];
        }
        return $out;
    }

    public function forgetRelation(int $teamRelationId): void
    {
        global $DB;
        if ($DB->tableExists(self::ASSIGN)) $DB->delete(self::ASSIGN, ['projectteams_id' => $teamRelationId]);
    }

    /**
     * After a project/template is cloned: the source's functions are copied into the new project
     * (same name, color, description) and members keep the function they had there.
     */
    public function copyFromProject(int $sourceProjectId, int $targetProjectId): void
    {
        global $DB;
        self::ensureTables();
        self::migrateGlobalRoles();
        $roleMap = [];
        foreach ($DB->request(['FROM' => self::ROLES, 'WHERE' => ['projects_id' => $sourceProjectId]]) as $r) {
            $existing = $DB->request(['SELECT' => ['id'], 'FROM' => self::ROLES, 'WHERE' => ['projects_id' => $targetProjectId, 'name' => $r['name']], 'LIMIT' => 1])->current();
            $newId = $existing ? (int) $existing['id'] : ($DB->insert(self::ROLES, ['projects_id' => $targetProjectId, 'name' => $r['name'], 'color' => $r['color'], 'comment' => $r['comment'], 'date_mod' => date('Y-m-d H:i:s')]) ? (int) $DB->insertId() : 0);
            if ($newId) $roleMap[(int) $r['id']] = $newId;
        }
        if (!$roleMap) return;
        $srcMember = [];
        foreach ($DB->request(['FROM' => ProjectTeam::getTable(), 'WHERE' => ['projects_id' => $sourceProjectId]]) as $r) $srcMember[$r['itemtype'] . '#' . $r['items_id']] = (int) $r['id'];
        $assigned = [];
        if ($srcMember) foreach ($DB->request(['FROM' => self::ASSIGN, 'WHERE' => ['projectteams_id' => array_values($srcMember)]]) as $a) $assigned[(int) $a['projectteams_id']] = (int) $a['roles_id'];
        foreach ($DB->request(['FROM' => ProjectTeam::getTable(), 'WHERE' => ['projects_id' => $targetProjectId]]) as $r) {
            $src = $srcMember[$r['itemtype'] . '#' . $r['items_id']] ?? 0;
            $role = $roleMap[$assigned[$src] ?? 0] ?? 0;
            if ($role && !countElementsInTable(self::ASSIGN, ['projectteams_id' => (int) $r['id']])) {
                $DB->insert(self::ASSIGN, ['projectteams_id' => (int) $r['id'], 'roles_id' => $role]);
            }
        }
    }

    /** Functions typed in the create form (`roles[n][name|color]`). */
    public function createFromInput(int $projectId, array $rows): void
    {
        global $DB;
        self::ensureTables();
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) continue;
            $name = mb_substr(trim(strip_tags((string) ($row['name'] ?? ''))), 0, 120);
            if ($name === '' || countElementsInTable(self::ROLES, ['projects_id' => $projectId, 'name' => $name])) continue;
            $color = (string) ($row['color'] ?? '');
            $DB->insert(self::ROLES, ['projects_id' => $projectId, 'name' => $name, 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4263eb', 'comment' => '', 'date_mod' => date('Y-m-d H:i:s')]);
        }
    }

    /** Purge of a project: its functions and assignments go with it. */
    public function forgetProject(int $projectId): void
    {
        global $DB;
        if (!$DB->tableExists(self::ROLES)) return;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::ROLES, 'WHERE' => ['projects_id' => $projectId]]) as $r) $ids[] = (int) $r['id'];
        if ($ids) $DB->delete(self::ASSIGN, ['roles_id' => $ids]);
        $DB->delete(self::ROLES, ['projects_id' => $projectId]);
    }
}
