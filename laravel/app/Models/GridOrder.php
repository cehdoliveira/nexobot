<?php

namespace App\Models;

class GridOrder extends LegacyModel
{
    protected $table = 'grids_orders';

    protected function casts(): array
    {
        return [
            'profit_usdc' => 'decimal:8',
            'original_cost_price' => 'decimal:8',
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
