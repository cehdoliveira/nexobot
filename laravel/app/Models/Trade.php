<?php

namespace App\Models;

class Trade extends LegacyModel
{
    protected $table = 'trades';

    protected function casts(): array
    {
        return [
            'entry_price' => 'decimal:8',
            'quantity' => 'decimal:8',
            'investment' => 'decimal:8',
            'bb_upper' => 'decimal:8',
            'bb_middle' => 'decimal:8',
            'bb_lower' => 'decimal:8',
            'take_profit_price' => 'decimal:8',
            'take_profit_1_price' => 'decimal:8',
            'take_profit_2_price' => 'decimal:8',
            'tp1_executed_qty' => 'decimal:8',
            'tp2_executed_qty' => 'decimal:8',
            'exit_price' => 'decimal:8',
            'profit_loss' => 'decimal:8',
            'profit_loss_percent' => 'decimal:4',
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
            'removed_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
