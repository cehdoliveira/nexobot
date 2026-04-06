<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class LegacyModel extends Model
{
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'modified_at';

    protected $connection = 'mysql';

    protected $guarded = [];
}
