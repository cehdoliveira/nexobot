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
                "`key` = '" . self::esc($key) . "'"
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
                "`key` = '" . self::esc($key) . "'"
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
                'key' => $key,
                'value' => $value,
                'description' => null
            ]);
            return (bool)$m2->save();
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
