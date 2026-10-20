<?php

namespace GlpiPlugin\Projectflow;

use Dropdown;
use Project;
use Rule;
use User;

/**
 * Shared behaviour of the Project Flow business rules (projects and project tasks).
 *
 * Custom value types used in criteria/actions definitions:
 *  - pf_select   : fixed list of values (`values` => [key => label])
 *  - pf_template : a native project template (Project with is_template = 1)
 *  - pf_user     : a user (without the "requester's manager" entry of the core type)
 */
trait RuleSupport
{
    public static function getConditionsArray()
    {
        return [
            static::ONADD => 'Criação',
            static::ONUPDATE => 'Atualização',
            static::ONADD | static::ONUPDATE => 'Criação / Atualização',
        ];
    }

    public function maybeRecursive()
    {
        return true;
    }

    public function isEntityAssign()
    {
        return true;
    }

    public static function getIcon()
    {
        return 'ti ti-git-branch';
    }

    /** Native project templates, for the "template" criterion/action. */
    public static function templateOptions(): array
    {
        global $DB;
        $list = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'template_name'],
            'FROM' => Project::getTable(),
            'WHERE' => ['is_template' => 1, 'is_deleted' => 0],
            'ORDER' => ['template_name ASC', 'name ASC'],
        ]) as $row) {
            $label = trim((string) ($row['template_name'] ?: $row['name']));
            $list[(int) $row['id']] = $label !== '' ? $label : ('Template #' . (int) $row['id']);
        }
        return $list;
    }

    private function pfValueLabel(array $definition, mixed $value): string|false
    {
        switch ($definition['type'] ?? '') {
            case 'pf_select':
                return (string) ($definition['values'][(string) $value] ?? $value);
            case 'pf_template':
                $list = self::templateOptions();
                return $list[(int) $value] ?? ('Template #' . (int) $value);
            case 'pf_user':
                return getUserName((int) $value);
        }
        return false;
    }

    private function pfShowValueField(array $definition, string $name, mixed $value): bool
    {
        switch ($definition['type'] ?? '') {
            case 'pf_select':
                Dropdown::showFromArray($name, $definition['values'], ['value' => (string) $value]);
                return true;
            case 'pf_template':
                Dropdown::showFromArray($name, [0 => Dropdown::EMPTY_VALUE] + self::templateOptions(), ['value' => (int) $value]);
                return true;
            case 'pf_user':
                User::dropdown(['name' => $name, 'value' => (int) $value, 'right' => 'all']);
                return true;
        }
        return false;
    }

    public function displayAdditionalRuleAction(array $action, $value = '')
    {
        return $this->pfShowValueField($action, 'value', $value);
    }

    public function displayAdditionalRuleCondition($condition, $criteria, $name, $value, $test = false)
    {
        if (!$test && !in_array((int) $condition, [Rule::PATTERN_IS, Rule::PATTERN_IS_NOT], true)) {
            return false;
        }
        return is_array($criteria) && $this->pfShowValueField($criteria, $name, $value);
    }

    public function getActionValue($ID, $type, $value)
    {
        $label = $this->pfValueLabel((array) $this->getAction($ID), $value);
        return $label !== false ? $label : parent::getActionValue($ID, $type, $value);
    }

    public function getAdditionalCriteriaDisplayPattern($ID, $condition, $pattern)
    {
        if (!in_array((int) $condition, [Rule::PATTERN_IS, Rule::PATTERN_IS_NOT], true)) {
            return false;
        }
        return $this->pfValueLabel((array) $this->getCriteria($ID), $pattern);
    }

    /** Values shared by project criteria and actions. */
    public static function riskValues(): array
    {
        return ['low' => 'Baixo', 'normal' => 'Normal', 'high' => 'Alto', 'critical' => 'Crítico'];
    }

    public static function healthValues(): array
    {
        return ['auto' => 'Automática', 'good' => 'Saudável', 'attention' => 'Atenção', 'critical' => 'Crítico'];
    }

    public static function executionValues(): array
    {
        return ['direct' => 'Tarefa direta', 'ticket' => 'Tarefa + chamado'];
    }

    public static function costValues(): array
    {
        return ['hours' => 'Horas', 'money' => 'Custo financeiro', 'both' => 'Horas + custo'];
    }
}
