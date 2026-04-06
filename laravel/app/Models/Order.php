<?php

namespace App\Models;

class Order extends LegacyModel
{
    protected $table = 'orders';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'quantity' => 'decimal:8',
            'executed_qty' => 'decimal:8',
            'cumulative_quote_qty' => 'decimal:8',
            'api_response' => 'array',
            'created_at' => 'datetime',
            'modified_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
