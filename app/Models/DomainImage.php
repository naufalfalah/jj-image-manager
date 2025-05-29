<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainImage extends Model
{
    protected $fillable = [
        'domain_id',
        'name',
        'url',
        'thumbnail'
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
