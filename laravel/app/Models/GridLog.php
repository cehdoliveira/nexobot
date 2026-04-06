<?php

namespace App\Models;

class GridLog extends LegacyModel
{
    protected $table = 'grid_logs';

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
