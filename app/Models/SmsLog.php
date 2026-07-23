<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SmsLog extends Model
{
    use HasFactory;

    protected $table = 'sms_logs';

    protected $fillable = [
        'sms_template_id',
        'content',
        'total_clients',
        'successful_count',
        'failed_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_clients'    => 'integer',
            'successful_count' => 'integer',
            'failed_count'     => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }
}
