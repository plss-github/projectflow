<?php

namespace GlpiPlugin\Projectflow\Service;

use Budget;
use CommonITILObject;
use Contact;
use Contract;
use Dropdown;
use Group;
use ITILCategory;
use Project;
use ProjectState;
use ProjectTaskType;
use ProjectType;
use Session;
use Supplier;
use User;

class ReferenceService
{
    /** Per-request caches: states and task types are read many times while rendering one page. */
    private static ?array $statesCache = null;
    private static ?array $taskTypesCache = null;

    public static function resetCache(): void { self::$statesCache = null; self::$taskTypesCache = null; }

    public function getProjectStates(): array
    {
        global $DB;
        if (self::$statesCache !== null) return self::$statesCache;
        $rows=[];
        foreach($DB->request(['FROM'=>ProjectState::getTable(),'ORDERBY'=>['is_finished ASC','id ASC']]) as $row){
            $rows[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'color'=>$row['color']?:'#94a3b8','is_finished'=>(bool)$row['is_finished']];
        }
        return self::$statesCache = $rows;
    }
    public function getStateMap(): array { $map=[]; foreach($this->getProjectStates() as $s)$map[$s['id']]=$s; return $map; }

    public function getProjectTypes(): array { return $this->simpleDropdown(ProjectType::getTable()); }
    public function getTaskTypes(): array { return self::$taskTypesCache ??= $this->simpleDropdown(ProjectTaskType::getTable()); }

    public function getPriorities(): array
    { $rows=[]; for($i=1;$i<=6;$i++)$rows[]=['id'=>$i,'name'=>CommonITILObject::getPriorityName($i)]; return $rows; }

    public function getEntities(): array
    {
        $ids=$_SESSION['glpiactiveentities']??[Session::getActiveEntity()]; $ids=array_values(array_unique(array_map('intval',is_array($ids)?$ids:[$ids]))); $rows=[];
        foreach($ids as $id)$rows[]=['id'=>$id,'name'=>$id?Dropdown::getDropdownName('glpi_entities',$id):'Entidade raiz'];
        usort($rows,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name'])); return $rows;
    }

    public function getUsers(int $limit=500): array
    {
        global $DB; $rows=[];
        if(!User::canView()){ $id=(int)Session::getLoginUserID(); return $id?[['id'=>$id,'name'=>getUserName($id)]]:[]; }
        foreach($DB->request(['SELECT'=>['id','name','realname','firstname'],'FROM'=>User::getTable(),'WHERE'=>['is_active'=>1],'ORDERBY'=>['realname ASC','firstname ASC','name ASC'],'LIMIT'=>max(1,min($limit,1000))]) as $r){
            $rows[]=['id'=>(int)$r['id'],'name'=>formatUserName((int)$r['id'],(string)$r['name'],(string)($r['realname']??''),(string)($r['firstname']??''))];
        } return $rows;
    }

    public function getGroups(int $limit=500): array
    {
        global $DB;$rows=[];$where=getEntitiesRestrictCriteria(Group::getTable(),'entities_id','',true);
        foreach($DB->request(['SELECT'=>['id','completename','name'],'FROM'=>Group::getTable(),'WHERE'=>$where,'ORDERBY'=>['completename ASC','name ASC'],'LIMIT'=>max(1,min($limit,1000))]) as $r)$rows[]=['id'=>(int)$r['id'],'name'=>(string)($r['completename']?:$r['name'])];
        return $rows;
    }
    public function getSuppliers(int $limit=500): array
    {
        global $DB;if(!Supplier::canView())return[];$rows=[];$where=getEntitiesRestrictCriteria(Supplier::getTable(),'entities_id','',true);
        foreach($DB->request(['SELECT'=>['id','name'],'FROM'=>Supplier::getTable(),'WHERE'=>$where,'ORDERBY'=>['name ASC'],'LIMIT'=>max(1,min($limit,1000))]) as $r)$rows[]=['id'=>(int)$r['id'],'name'=>(string)($r['name']?:('Fornecedor #'.$r['id']))]; return $rows;
    }
    public function getContacts(int $limit=500): array
    {
        global $DB;if(!Contact::canView())return[];$rows=[];$where=getEntitiesRestrictCriteria(Contact::getTable(),'entities_id','',true);
        foreach($DB->request(['SELECT'=>['id','name','firstname'],'FROM'=>Contact::getTable(),'WHERE'=>$where,'ORDERBY'=>['name ASC','firstname ASC'],'LIMIT'=>max(1,min($limit,1000))]) as $r){$d=trim((string)($r['firstname']??'').' '.(string)($r['name']??''));$rows[]=['id'=>(int)$r['id'],'name'=>$d?:('Contato #'.$r['id'])];} return $rows;
    }
    public function getBudgets(int $limit=500): array
    {
        global $DB;if(!class_exists(Budget::class)||!Budget::canView())return[];$rows=[];$where=getEntitiesRestrictCriteria(Budget::getTable(),'entities_id','',true);
        foreach($DB->request(['SELECT'=>['id','name'],'FROM'=>Budget::getTable(),'WHERE'=>$where,'ORDERBY'=>['name ASC'],'LIMIT'=>max(1,min($limit,1000))]) as $r)$rows[]=['id'=>(int)$r['id'],'name'=>(string)($r['name']?:('Orçamento #'.$r['id']))]; return $rows;
    }
    public function getTicketCategories(int $entityId,int $limit=1000): array
    {
        global $DB;if(!class_exists(ITILCategory::class)||!ITILCategory::canView())return[];$rows=[];$where=getEntitiesRestrictCriteria(ITILCategory::getTable(),'entities_id',$entityId,true);
        foreach($DB->request(['SELECT'=>['id','completename','name'],'FROM'=>ITILCategory::getTable(),'WHERE'=>$where,'ORDERBY'=>['completename ASC','name ASC'],'LIMIT'=>max(1,min($limit,2000))]) as $r)$rows[]=['id'=>(int)$r['id'],'name'=>(string)(($r['completename']??'')?:($r['name']??('Categoria #'.$r['id'])))]; return $rows;
    }

    public function getTemplates(): array
    {
        global $DB;$rows=[];$table=Project::getTable();$criteria=['FROM'=>$table,'WHERE'=>array_merge(["$table.is_template"=>1,"$table.is_deleted"=>0],getEntitiesRestrictCriteria($table,'','',true)),'ORDERBY'=>["$table.template_name ASC","$table.name ASC"]];
        foreach($DB->request($criteria) as $r){$p=new Project();$p->getFromResultSet($r);if(!$p->canViewItem())continue;$rows[]=['id'=>(int)$r['id'],'name'=>(string)(($r['template_name']??'')?:($r['name']??('Template #'.$r['id']))),'description'=>trim(strip_tags((string)($r['content']??''))),'native_url'=>Project::getFormURLWithID((int)$r['id']),'plugin_url'=>PLUGIN_PROJECTFLOW_WEBDIR.'/front/project.php?id='.(int)$r['id'],'tasks_count'=>countElementsInTable('glpi_projecttasks',['projects_id'=>(int)$r['id'],'is_deleted'=>0]),'can_update'=>$p->canUpdateItem()];} return $rows;
    }

    public function getContracts(int $entityId=0,int $limit=500): array
    {
        global $DB;if(!class_exists(Contract::class)||!Contract::canView())return[];$rows=[];$where=getEntitiesRestrictCriteria(Contract::getTable(),'entities_id',$entityId?:'',true);
        foreach($DB->request(['SELECT'=>['id','name','num','begin_date'],'FROM'=>Contract::getTable(),'WHERE'=>$where,'ORDERBY'=>['name ASC','id DESC'],'LIMIT'=>max(1,min($limit,1000))]) as $r){$c=new Contract();$c->getFromResultSet($r);if(!$c->canViewItem())continue;$rows[]=['id'=>(int)$r['id'],'name'=>(string)($r['name']?:('Contrato #'.$r['id'])),'number'=>(string)($r['num']??''),'begin_date'=>$r['begin_date']??null];} return $rows;
    }

    public function getAssetTypes(): array { return (new AssetService())->getTypes(); }

    private function simpleDropdown(string $table): array
    {
        global $DB;$rows=[];foreach($DB->request(['FROM'=>$table,'ORDERBY'=>['name ASC','id ASC']]) as $r)$rows[]=['id'=>(int)$r['id'],'name'=>(string)$r['name']];return $rows;
    }
}
