<?php

namespace GlpiPlugin\Projectflow;

class Config
{
    private const TABLE = 'glpi_plugin_projectflow_configs';

    public static function get(string $name, mixed $default = null): mixed
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return $default;
        }
        $it = $DB->request(['SELECT' => ['value'], 'FROM' => self::TABLE, 'WHERE' => ['name' => $name], 'LIMIT' => 1]);
        return $it->count() ? $it->current()['value'] : $default;
    }

    public static function set(string $name, string $value): void
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return;
        }
        $it = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['name' => $name], 'LIMIT' => 1]);
        if ($it->count()) {
            $DB->update(self::TABLE, ['value' => $value], ['id' => $it->current()['id']]);
        } else {
            $DB->insert(self::TABLE, ['name' => $name, 'value' => $value]);
        }
    }

    public static function bool(string $name, bool $default = false): bool
    {
        return (int) self::get($name, $default ? '1' : '0') === 1;
    }

    public static function int(string $name, int $default = 0): int
    {
        return (int) self::get($name, (string) $default);
    }
}
