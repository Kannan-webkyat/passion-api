<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceChecklistItem extends Model
{
    public const SERVICE_DAILY_ROOM_CLEANING = 'daily_room_cleaning';

    public const SERVICE_CHECKOUT_INSPECTION = 'checkout_inspection';

    public const SERVICE_HOUSEKEEPING_JOB = 'housekeeping_job';

    protected $fillable = [
        'service_type',
        'service_id',
        'task_key',
        'task_name',
        'section',
        'display_order',
        'required',
        'completed',
        'estimated_minutes',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'required' => 'boolean',
        'completed' => 'boolean',
        'estimated_minutes' => 'integer',
        'service_id' => 'integer',
    ];
}
