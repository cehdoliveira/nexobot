<?php

namespace App\Models;

class Grid extends LegacyModel
{
    protected $table = 'grids';

    protected function casts(): array
    {
        return [
            'lower_price' => 'decimal:8',
            'upper_price' => 'decimal:8',
            'grid_spacing_percent' => 'decimal:2',
            'capital_allocated_usdc' => 'decimal:2',
            'capital_per_level' => 'decimal:2',
            'accumulated_profit_usdc' => 'decimal:2',
            'current_price' => 'decimal:8',
            'initial_capital_usdc' => 'decimal:8',
            'peak_capital_usdc' => 'decimal:8',
            'current_capital_usdc' => 'decimal:8',
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
            'removed_at' => 'datetime',
            'last_monitor_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
