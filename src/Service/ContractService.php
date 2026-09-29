<?php

namespace GlpiPlugin\Projectflow\Service;

use Contract;
use Contract_Item;
use Document;
use Document_Item;
use Project;

class ContractService
{
    public function getForProject(int $projectId): array
    {
        global $DB;
        $project=new Project(); if(!$project->getFromDB($projectId)||!$project->canViewItem())return [];
        $out=[];
        foreach($DB->request(['FROM'=>Contract_Item::getTable(),'WHERE'=>['itemtype'=>Project::class,'items_id'=>$projectId],'ORDERBY'=>['id DESC']]) as $rel){
            $contract=new Contract(); if(!$contract->getFromDB((int)$rel['contracts_id'])||!$contract->canViewItem())continue;
            $out[]=['relation_id'=>(int)$rel['id'],'id'=>(int)$contract->fields['id'],'name'=>(string)($contract->fields['name']??('Contrato #'.$contract->fields['id'])),'number'=>(string)($contract->fields['num']??''),'begin_date'=>$contract->fields['begin_date']??null,'duration'=>(int)($contract->fields['duration']??0),'url'=>Contract::getFormURLWithID((int)$contract->fields['id']),'documents_url'=>Contract::getFormURLWithID((int)$contract->fields['id']).'&forcetab=Document_Item$1','documents'=>$this->getContractDocuments($projectId,(int)$contract->fields['id'])];
        }
        return $out;
    }

    public function link(int $projectId,int $contractId): bool
    {
        $project=new Project();$contract=new Contract(); if(!$project->getFromDB($projectId)||!$project->can($projectId,UPDATE)||!$contract->getFromDB($contractId)||!$contract->canViewItem())return false;
        $data=['contracts_id'=>$contractId,'itemtype'=>Project::class,'items_id'=>$projectId]; if(countElementsInTable(Contract_Item::getTable(),$data))return true;
        return (bool)(new Contract_Item())->add($data);
    }
    public function unlink(int $projectId,int $relationId): bool
    {
        $project=new Project(); if(!$project->getFromDB($projectId)||!$project->can($projectId,UPDATE))return false;
        $rel=new Contract_Item(); if(!$rel->getFromDB($relationId)||(string)$rel->fields['itemtype']!==Project::class||(int)$rel->fields['items_id']!==$projectId)return false;
        return (bool)$rel->delete(['id'=>$relationId]);
    }

    /** Files attached to a contract (Documents tab), with the plugin viewer URL. */
    public function getContractDocuments(int $projectId, int $contractId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => Document_Item::getTable(), 'WHERE' => ['itemtype' => Contract::class, 'items_id' => $contractId], 'ORDERBY' => ['id DESC']]) as $rel) {
            $doc = new Document();
            if (!$doc->getFromDB((int) $rel['documents_id']) || !empty($doc->fields['is_deleted'])) continue;
            $file = (string) ($doc->fields['filename'] ?? '');
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $out[] = [
                'id' => (int) $doc->fields['id'],
                'name' => (string) (($doc->fields['name'] ?? '') ?: $file ?: ('Documento #' . $doc->fields['id'])),
                'filename' => $file,
                'ext' => $ext,
                'mime' => (string) ($doc->fields['mime'] ?? ''),
                'has_file' => (string) ($doc->fields['filepath'] ?? '') !== '',
                'link' => (string) ($doc->fields['link'] ?? ''),
                'view_url' => PLUGIN_PROJECTFLOW_WEBDIR . '/ajax/contract_file.php?project_id=' . $projectId . '&document_id=' . (int) $doc->fields['id'],
            ];
        }
        return $out;
    }

    /**
     * Returns the Document when it is attached to a contract that is linked to a project the
     * current user can see. This is the only access rule of the contract file viewer.
     */
    public function findViewableDocument(int $projectId, int $documentId): ?Document
    {
        global $DB;
        $project = new Project();
        if ($projectId <= 0 || $documentId <= 0 || !$project->getFromDB($projectId) || !$project->canViewItem()) return null;
        $contractIds = [];
        foreach ($DB->request(['SELECT' => ['contracts_id'], 'FROM' => Contract_Item::getTable(), 'WHERE' => ['itemtype' => Project::class, 'items_id' => $projectId]]) as $row) $contractIds[] = (int) $row['contracts_id'];
        if (!$contractIds) return null;
        if (!countElementsInTable(Document_Item::getTable(), ['documents_id' => $documentId, 'itemtype' => Contract::class, 'items_id' => $contractIds])) return null;
        $doc = new Document();
        return $doc->getFromDB($documentId) && empty($doc->fields['is_deleted']) ? $doc : null;
    }
}
