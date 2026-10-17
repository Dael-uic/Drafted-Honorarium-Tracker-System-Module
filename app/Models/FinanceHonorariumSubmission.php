<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceHonorariumSubmission extends Model
{
    protected $fillable = [
        'defense_request_id', 'submitted_by', 'panel_names', 'status',
        'finance_notes', 'submitted_at', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'panel_names' => 'array',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function defenseRequest()
    {
        return $this->belongsTo(DefenseRequest::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
