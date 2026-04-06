<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'modified_at';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'mail',
        'login',
        'password',
        'cpf',
        'phone',
        'genre',
        'enabled',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function getAuthIdentifierName(): string
    {
        return 'idx';
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->mail;
    }
}
