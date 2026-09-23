<?php

namespace GlpiPlugin\Projectflow\Service;

use CommonDropdown;
use ProjectState;
use ProjectTaskType;
use ProjectType;
use Session;

/**
 * Maintenance of the native GLPI dropdowns used by Project Flow: project/task states
 * (ProjectState, shared by projects and tasks), project types and task types.
 * Everything goes through the native dropdown classes (rights, history, cache).
 */
class CatalogService
{
    public const KINDS = [
        'state' => ProjectState::class,
        'project_type' => ProjectType::class,
        'task_type' => ProjectTaskType::class,
    ];

    public function __construct(private readonly MetaService $meta = new MetaService()) {}

    public static function canManage(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** Rows of one kind, with usage counts (projects + tasks referencing it). */
    public function list(string $kind): array
    {
        global $DB;
        $class = self::KINDS[$kind] ?? null;
        if ($class === null) return [];
        $table = $class::getTable();
        $rows = [];
        foreach ($DB->request(['FROM' => $table, 'ORDERBY' => $kind === 'state' ? ['is_finished ASC', 'id ASC'] : ['name ASC']]) as $row) {
            $id = (int) $row['id'];
            $rows[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'comment' => (string) ($row['comment'] ?? ''),
                'color' => (string) (($row['color'] ?? '') ?: '#94a3b8'),
                'is_finished' => !empty($row['is_finished']),
                'usage' => $this->usage($kind, $id),
            ];
        }
        return $rows;
    }

    public function save(string $kind, array $input): int|false
    {
        $class = self::KINDS[$kind] ?? null;
        if ($class === null || !self::canManage()) return false;
        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 255);
        if ($name === '') return false;

        $data = ['name' => $name, 'comment' => mb_substr(trim(strip_tags((string) ($input['comment'] ?? ''))), 0, 2000)];
        if ($kind === 'state') {
            $color = (string) ($input['color'] ?? '');
            $data['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#94a3b8';
            $data['is_finished'] = !empty($input['is_finished']) ? 1 : 0;
        }

        /** @var CommonDropdown $item */
        $item = new $class();
        $table = $class::getTable();
        global $DB;
        if ($DB->fieldExists($table, 'entities_id')) {
            $data['entities_id'] = 0;
            if ($DB->fieldExists($table, 'is_recursive')) $data['is_recursive'] = 1;
        }

        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            if (!$item->getFromDB($id)) return false;
            unset($data['entities_id'], $data['is_recursive']);
            if (!$item->update(['id' => $id] + $data)) return false;
        } else {
            $id = (int) $item->add($data);
            if ($id <= 0) return false;
        }

        if ($kind === 'state') {
            $percent = !empty($data['is_finished']) ? 100 : max(0, min(100, (int) ($input['percent'] ?? 0)));
            $this->meta->setStateProgress($id, $percent);
            ReferenceService::resetCache();
        }
        return $id;
    }

    /** Delete an unused entry. Entries still referenced by projects/tasks are refused. */
    public function delete(string $kind, int $id): string|true
    {
        global $DB;
        $class = self::KINDS[$kind] ?? null;
        if ($class === null || !self::canManage() || $id <= 0) return 'Operação não permitida.';
        $usage = $this->usage($kind, $id);
        if ($usage > 0) return "Em uso por {$usage} projeto(s)/tarefa(s). Troque o valor nesses itens antes de excluir.";
        if ($kind === 'state') {
            foreach (['default_project_state_id', 'default_task_state_id'] as $key) {
                if (\GlpiPlugin\Projectflow\Config::int($key, 0) === $id) return 'Este é o estado inicial configurado. Escolha outro estado inicial antes de excluir.';
            }
            if ($DB->tableExists('glpi_plugin_projectflow_progressrules') && countElementsInTable('glpi_plugin_projectflow_progressrules', ['projectstates_id' => $id]) > 0) {
                return 'Há regras por andamento que levam a este estado. Ajuste as regras antes de excluir.';
            }
        }
        $item = new $class();
        if (!$item->getFromDB($id) || !$item->delete(['id' => $id], true)) return 'Não foi possível excluir.';
        if ($kind === 'state' && $DB->tableExists('glpi_plugin_projectflow_stateprogress')) {
            $DB->delete('glpi_plugin_projectflow_stateprogress', ['projectstates_id' => $id]);
            ReferenceService::resetCache();
        }
        return true;
    }

    private function usage(string $kind, int $id): int
    {
        return match ($kind) {
            'state' => countElementsInTable('glpi_projects', ['projectstates_id' => $id])
                + countElementsInTable('glpi_projecttasks', ['projectstates_id' => $id]),
            'project_type' => countElementsInTable('glpi_projects', ['projecttypes_id' => $id]),
            'task_type' => countElementsInTable('glpi_projecttasks', ['projecttasktypes_id' => $id]),
            default => 0,
        };
    }
}
