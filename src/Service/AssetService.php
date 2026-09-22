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
        return $DB->delete(self::TABLE,['id'=>$relationId,'projecttasks_id'=>$taskId]);
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
        $table = $type::getTable();
        $where = ['name' => ['LIKE', '%' . $query . '%']];
        if (ctype_digit($query)) {
            $where = ['OR' => [['id' => (int) $query], ['name' => ['LIKE', '%' . $query . '%']]]];
        }
        $items = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => $table,
            'WHERE' => $where,
            'ORDERBY' => ['name ASC', 'id ASC'],
            'LIMIT' => max(1, min(50, $limit)),
        ]) as $row) {
            $item = new $type();
            if (!$item->getFromDB((int) $row['id']) || !$item->canViewItem()) continue;
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($item->getName() ?: (self::TYPES[$type] . ' #' . $row['id'])),
                'label' => (string) ($item->getName() ?: (self::TYPES[$type] . ' #' . $row['id'])) . ' · #' . (int) $row['id'],
                'url' => $type::getFormURLWithID((int) $row['id']),
            ];
        }
        return $items;
    }

    public function getTypes(): array { $out=[]; foreach(self::TYPES as $id=>$name) if(class_exists($id))$out[]=['id'=>$id,'name'=>$name]; return $out; }
}
