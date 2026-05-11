<?php

class AppSettings
{
    public static function get(string $namespace, string $key, ?string $default = null): ?string
    {
        try {
            $m = new settings_model();
            $m->set_filter([
                "active = 'yes'",
                "namespace = '" . self::esc($namespace) . "'",
                "cfg_key = '" . self::esc($key) . "'"
            ]);
            $m->set_paginate([1]);
            $m->load_data();
            if (!empty($m->data) && isset($m->data[0]['value'])) {
                return $m->data[0]['value'];
            }
        } catch (Exception $e) {
            error_log('AppSettings::get error: ' . $e->getMessage());
        }
        return $default;
    }

    public static function set(string $namespace, string $key, string $value): bool
    {
        try {
            $m = new settings_model();
            $m->set_filter([
                "active = 'yes'",
                "namespace = '" . self::esc($namespace) . "'",
                "cfg_key = '" . self::esc($key) . "'"
            ]);
            $m->set_paginate([1]);
            $m->load_data();

            if (!empty($m->data)) {
                // Update
                $m->set_filter(["idx = '" . (int)$m->data[0]['idx'] . "'"]); 
                $m->populate([
                    'value' => $value
                ]);
                return (bool)$m->save();
            }

            // Insert
            $m2 = new settings_model();
            $m2->populate([
                'namespace' => $namespace,
                'cfg_key' => $key,
                'value' => $value,
                'description' => null
            ]);
            if ($m2->save()) {
                return true;
            }

            // Race condition ou registro soft-deleted: INSERT falhou por duplicate key.
            // Busca sem filtro active para reativar e atualizar o registro existente.
            $m3 = new settings_model();
            $m3->set_filter([
                "namespace = '" . self::esc($namespace) . "'",
                "cfg_key = '" . self::esc($key) . "'"
            ]);
            $m3->set_paginate([1]);
            $m3->load_data();
            if (!empty($m3->data)) {
                $m3->set_filter(["idx = '" . (int)$m3->data[0]['idx'] . "'"]);
                $m3->populate(['value' => $value, 'active' => 'yes']);
                return (bool)$m3->save();
            }
            return false;
        } catch (Exception $e) {
            error_log('AppSettings::set error: ' . $e->getMessage());
            return false;
        }
    }

    private static function esc(string $s): string
    {
        return addslashes($s);
    }
}
