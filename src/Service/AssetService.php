<?php

namespace GlpiPlugin\Projectflow\Service;

use ProjectTask;
use Session;

class AssetService
{
    private const TABLE = 'glpi_plugin_projectflow_taskassets';
    public const TYPES = [
        'Computer' => 'Computador', 'NetworkEquipment' => 'Equipamento de rede', 'Printer' => 'Impressora',
        'Monitor' => 'Monitor', 'Phone' => 'Telefone', 'Peripheral' => 'Periférico', 'Software' => 'Software',
    ];

    public function getForTask(int $taskId): array
    {
        global $DB;
        $task = new ProjectTask(); if (!$task->getFromDB($taskId) || !$task->canViewItem() || !$DB->tableExists(self::TABLE)) return [];
        $items=[];
        foreach($DB->request(['FROM'=>self::TABLE,'WHERE'=>['projecttasks_id'=>$taskId],'ORDERBY'=>['id DESC']]) as $row){
            $type=(string)$row['itemtype']; if(!isset(self::TYPES[$type])||!class_exists($type))continue;
            $item=new $type(); if(!$item->getFromDB((int)$row['items_id'])||!$item->canViewItem())continue;
            $items[]=['relation_id'=>(int)$row['id'],'itemtype'=>$type,'type_label'=>self::TYPES[$type],'id'=>(int)$row['items_id'],'name'=>$item->getName(),'url'=>$type::getFormURLWithID((int)$row['items_id'])];
        }
        return $items;
    }

    public function add(int $taskId,string $type,int $itemId): bool
    {
        global $DB;
        $task=new ProjectTask(); if(!$task->getFromDB($taskId)||!$task->canUpdateItem()||!isset(self::TYPES[$type])||!class_exists($type)||$itemId<=0)return false;
        $item=new $type(); if(!$item->getFromDB($itemId)||!$item->canViewItem())return false;
        $where=['projecttasks_id'=>$taskId,'itemtype'=>$type,'items_id'=>$itemId]; if(countElementsInTable(self::TABLE,$where))return true;
        return (bool)$DB->insert(self::TABLE,$where+['users_id'=>(int)Session::getLoginUserID(),'date_creation'=>date('Y-m-d H:i:s')]);
    }

    public function remove(int $taskId,int $relationId): bool
    {
        global $DB; $task=new ProjectTask(); if(!$task->getFromDB($taskId)||!$task->canUpdateItem())return false;
        if($relationId<=0||!countElementsInTable(self::TABLE,['id'=>$relationId,'projecttasks_id'=>$taskId]))return false;
        return (bool)$DB->delete(self::TABLE,['id'=>$relationId,'projecttasks_id'=>$taskId]);
    }


    public function searchForTask(int $taskId, string $type, string $query, int $limit = 20): array
    {
        global $DB;
        $task = new ProjectTask();
        if (!$task->getFromDB($taskId) || !$task->canViewItem() || !isset(self::TYPES[$type]) || !class_exists($type) || !$type::canView()) {
            return [];
        }
        $query = trim($query);
        if ($query === '') return [];
        $limit = max(1, min(50, $limit));
        $table = $type::getTable();

        $match = ["$table.name" => ['LIKE', '%' . $query . '%']];
        if (ctype_digit($query)) {
            $match = ['OR' => [["$table.id" => (int) $query], ["$table.name" => ['LIKE', '%' . $query . '%']]]];
        }
        // Push the cheap visibility filters into SQL (entity scope, trash, templates) so the
        // LIMIT is not consumed by rows the user could never see.
        $where = [$match];
        if ($DB->fieldExists($table, 'is_deleted')) $where["$table.is_deleted"] = 0;
        if ($DB->fieldExists($table, 'is_template')) $where["$table.is_template"] = 0;
        if ($DB->fieldExists($table, 'entities_id')) {
            $where = array_merge($where, getEntitiesRestrictCriteria($table, '', '', $DB->fieldExists($table, 'is_recursive')));
        }

        $items = [];
        $offset = 0;
        $chunk = 50;
        do {
            $batch = 0;
            foreach ($DB->request([
                'FROM' => $table,
                'WHERE' => $where,
                'ORDERBY' => ["$table.name ASC", "$table.id ASC"],
                'START' => $offset,
                'LIMIT' => $chunk,
            ]) as $row) {
                $batch++;
                $item = new $type();
                $item->getFromResultSet($row);
                if (!$item->canViewItem()) continue;
                $name = (string) ($item->getName() ?: (self::TYPES[$type] . ' #' . $row['id']));
                $items[] = [
                    'id' => (int) $row['id'],
                    'name' => $name,
                    'label' => $name . ' · #' . (int) $row['id'],
                    'url' => $type::getFormURLWithID((int) $row['id']),
                ];
                if (count($items) >= $limit) break 2;
            }
            $offset += $chunk;
        } while ($batch === $chunk && $offset < 1000);
        return $items;
    }

    public function getTypes(): array { $out=[]; foreach(self::TYPES as $id=>$name) if(class_exists($id))$out[]=['id'=>$id,'name'=>$name]; return $out; }
}
