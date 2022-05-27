<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;
    protected $guarded = ['id', 'created_at', 'updated_at'];
    protected $casts = [
        'fp_data'   => 'array',
        'sync'      => 'boolean'
    ];

    public static function findByEmail(string $email): Model|Builder
    {
        return Contact::firstOrCreate([
            'email' => $email
        ]);
    }
    public function sync(){
        $this->sync = true;
        $this->save();
    }

    public static function getUnsynced(){
        return (new Contact)->newQuery()->whereNotNull('ghl_id')->whereNotNull('assign_to')->where('sync', false)->get();
    }
}
