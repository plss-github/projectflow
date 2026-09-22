<?php

namespace GlpiPlugin\Projectflow\Service;

use Contract;
use Contract_Item;
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
            $out[]=['relation_id'=>(int)$rel['id'],'id'=>(int)$contract->fields['id'],'name'=>(string)($contract->fields['name']??('Contrato #'.$contract->fields['id'])),'number'=>(string)($contract->fields['num']??''),'begin_date'=>$contract->fields['begin_date']??null,'duration'=>(int)($contract->fields['duration']??0),'url'=>Contract::getFormURLWithID((int)$contract->fields['id'])];
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
}
