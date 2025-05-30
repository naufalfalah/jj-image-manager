<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $fillable = [
        'name',
        'status',
    ];

    public function images()
    {
        return $this->hasMany(DomainImage::class);
    }
}
