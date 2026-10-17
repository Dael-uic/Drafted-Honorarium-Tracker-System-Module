<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceDocumentSubmission extends Model
{
    protected $fillable = [
        'defense_request_id', 'document_type', 'status', 'submitted_by', 'reviewed_by',
        'payload', 'finance_notes', 'ready_on', 'submitted_at', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'ready_on' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function defenseRequest() { return $this->belongsTo(DefenseRequest::class); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
