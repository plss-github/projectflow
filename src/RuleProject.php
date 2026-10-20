<?php

namespace GlpiPlugin\Projectflow;

use Rule;

/**
 * Business rules for projects (Administração > Regras > Regras de negócios para projetos).
 * Evaluated when a project is created and/or updated, from Project Flow or GLPI's native form.
 */
class RuleProject extends Rule
{
    use RuleSupport;

    public const ONADD = 1;
    public const ONUPDATE = 2;

    public static $rightname = 'config';
    public $can_sort = true;

    public function getTitle()
    {
        return 'Regras de negócios para projetos';
    }

    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Regras de negócios para projetos' : 'Regra de negócio para projetos';
    }

    public function maxActionsCount()
    {
        return count($this->getActions());
    }

    public function getCriterias()
    {
        $t = 'glpi_projects';
        return [
            'name' => ['name' => 'Nome', 'table' => $t, 'field' => 'name'],
            'code' => ['name' => 'Código', 'table' => $t, 'field' => 'code'],
            'content' => ['name' => 'Descrição', 'table' => $t, 'field' => 'content'],
            'entities_id' => ['name' => 'Entidade', 'table' => 'glpi_entities', 'field' => 'name', 'type' => 'dropdown'],
            'projecttypes_id' => ['name' => 'Tipo', 'table' => 'glpi_projecttypes', 'field' => 'name', 'type' => 'dropdown'],
            'projectstates_id' => ['name' => 'Estado', 'table' => 'glpi_projectstates', 'field' => 'name', 'type' => 'dropdown'],
            'priority' => ['name' => 'Prioridade', 'table' => $t, 'field' => 'priority', 'type' => 'dropdown_priority'],
            'users_id' => ['name' => 'Gerente', 'table' => 'glpi_users', 'field' => 'name', 'type' => 'dropdown_users'],
            'groups_id' => ['name' => 'Grupo gerente', 'table' => 'glpi_groups', 'field' => 'name', 'type' => 'dropdown', 'condition' => ['is_manager' => 1]],
            '_pf_template' => ['name' => 'Template de origem', 'table' => $t, 'field' => 'template_name', 'type' => 'pf_template'],
            '_pf_portfolio' => ['name' => 'Portfólio', 'table' => $t, 'field' => 'portfolio'],
            '_pf_risk_level' => ['name' => 'Risco', 'table' => $t, 'field' => 'risk_level', 'type' => 'pf_select', 'values' => self::riskValues()],
            '_pf_health' => ['name' => 'Saúde', 'table' => $t, 'field' => 'health', 'type' => 'pf_select', 'values' => self::healthValues()],
            '_pf_execution_mode' => ['name' => 'Modo de execução', 'table' => $t, 'field' => 'execution_mode', 'type' => 'pf_select', 'values' => self::executionValues()],
            '_pf_cost_mode' => ['name' => 'Contabilização', 'table' => $t, 'field' => 'cost_mode', 'type' => 'pf_select', 'values' => self::costValues()],
        ];
    }

    public function getActions()
    {
        $actions = [
            'projectstates_id' => ['name' => 'Estado', 'table' => 'glpi_projectstates', 'type' => 'dropdown'],
            'priority' => ['name' => 'Prioridade', 'type' => 'dropdown_priority'],
            'projecttypes_id' => ['name' => 'Tipo', 'table' => 'glpi_projecttypes', 'type' => 'dropdown'],
            'users_id' => ['name' => 'Gerente', 'type' => 'pf_user'],
            'groups_id' => ['name' => 'Grupo gerente', 'table' => 'glpi_groups', 'type' => 'dropdown', 'condition' => ['is_manager' => 1]],
            '_pf_team_user' => [
                'name' => 'Equipe - adicionar usuário', 'type' => 'pf_user',
                'force_actions' => ['append'], 'permitseveral' => ['append'], 'appendto' => '_pf_team_users',
            ],
            '_pf_team_group' => [
                'name' => 'Equipe - adicionar grupo', 'table' => 'glpi_groups', 'type' => 'dropdown',
                'force_actions' => ['append'], 'permitseveral' => ['append'], 'appendto' => '_pf_team_groups',
            ],
            '_pf_portfolio' => ['name' => 'Portfólio', 'type' => 'text'],
            '_pf_sponsor' => ['name' => 'Patrocinador', 'type' => 'text'],
            '_pf_risk_level' => ['name' => 'Risco', 'type' => 'pf_select', 'values' => self::riskValues()],
            '_pf_health' => ['name' => 'Saúde', 'type' => 'pf_select', 'values' => self::healthValues()],
            '_pf_execution_mode' => ['name' => 'Modo de execução', 'type' => 'pf_select', 'values' => self::executionValues()],
            '_pf_cost_mode' => ['name' => 'Contabilização', 'type' => 'pf_select', 'values' => self::costValues()],
            '_pf_apply_template' => ['name' => 'Aplicar template (cria as tarefas do template)', 'type' => 'pf_template'],
        ];
        return $actions + parent::getActions();
    }
}
