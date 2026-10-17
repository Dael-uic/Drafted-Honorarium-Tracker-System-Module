<?php

namespace App\Services;

use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumActivity;
use App\Models\Notification as AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinanceDocumentWorkflowService
{
    /** Unlock next-day documents and create one notification/activity per unlock. */
    public function unlockDueDocuments(): int
    {
        $count = 0;
        $today = now()->toDateString();

        FinanceDocumentSubmission::query()
            ->where('document_type', 'panel_breakdown')
            ->where('status', 'locked')
            ->whereDate('ready_on', '<=', $today)
            ->with('defenseRequest')
            ->chunkById(100, function ($documents) use (&$count) {
                foreach ($documents as $document) {
                    DB::transaction(function () use ($document, &$count) {
                        $locked = FinanceDocumentSubmission::whereKey($document->id)
                            ->where('status', 'locked')->lockForUpdate()->first();
                        if (!$locked) return;

                        $locked->update(['status' => 'ready']);
                        $requestId = $locked->defense_request_id;
                        $studentName = $locked->defenseRequest?->student?->name
                            ?? $locked->defenseRequest?->student_name
                            ?? ('Defense request #' . $requestId);

                        HonorariumActivity::create([
                            'defense_request_id' => $requestId,
                            'finance_document_submission_id' => $locked->id,
                            'activity_type' => 'document_ready',
                            'title' => 'Panel breakdown ready',
                            'description' => "Document 2 is ready for the AA to send to Finance for {$studentName}.",
                            'occurred_at' => now(),
                        ]);

                        // Role is stored as a legacy string in this application.
                        $assistants = User::query()->whereIn('role', [
                            'Administrative Assistant', 'Assistant', 'AA', 'Super Admin', 'Dean',
                        ])->get(['id']);
                        foreach ($assistants as $assistant) {
                            AppNotification::create([
                                'user_id' => $assistant->id,
                                'type' => 'honorarium_document_ready',
                                'title' => 'Document 2 is ready to send',
                                'message' => "The panel-name/honorarium breakdown for {$studentName} is ready for Finance.",
                                'link' => '/honorarium-documents',
                                'read' => false,
                            ]);
                        }
                        $count++;
                    });
                }
            });

        return $count;
    }
}
