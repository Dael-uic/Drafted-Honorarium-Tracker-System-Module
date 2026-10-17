<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumActivity;
use App\Models\Notification as AppNotification;
use App\Models\Panelist;
use App\Services\FinanceDocumentWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FinanceDocumentWorkflowController extends Controller
{
    private function role(): string { return strtolower(trim((string) Auth::user()?->role)); }
    private function isAa(): bool { return in_array($this->role(), ['administrative assistant', 'assistant', 'aa', 'super admin', 'dean'], true); }
    private function isFinance(): bool { return in_array($this->role(), ['finance', 'finance office', 'finance officer', 'super admin', 'dean'], true); }

    public function index(FinanceDocumentWorkflowService $workflow)
    {
        abort_unless(Auth::check(), 401);
        // Also checks due dates on every page load, so missed scheduler runs do not strand a document.
        $workflow->unlockDueDocuments();
        $user = Auth::user();
        $aa = $this->isAa();
        $finance = $this->isFinance();

        $requests = DefenseRequest::query()
            ->with(['student:id,first_name,middle_name,last_name,student_number,program'])
            ->whereNotNull('proof_of_payment')
            ->latest('updated_at')->limit(500)->get();

        $panelIds = $requests->flatMap(fn ($r) => [
            $r->defense_chairperson, $r->defense_panelist1, $r->defense_panelist2,
            $r->defense_panelist3, $r->defense_panelist4,
        ])->filter()->unique()->values();
        $panelists = Panelist::whereIn('id', $panelIds)->get()->keyBy('id');
        $docs = FinanceDocumentSubmission::whereIn('defense_request_id', $requests->pluck('id'))
            ->get()->groupBy('defense_request_id');

        $items = $requests->map(function ($r) use ($panelists, $docs) {
            $panelNames = collect([
                $r->defense_chairperson, $r->defense_panelist1, $r->defense_panelist2,
                $r->defense_panelist3, $r->defense_panelist4,
            ])->filter()->unique()->map(fn ($id) => $panelists->get($id)?->name ?? (string) $id)->values()->all();
            $student = $r->student;
            $byType = ($docs->get($r->id) ?? collect())->keyBy('document_type');
            $receipt = (string) $r->proof_of_payment;
            $receiptUrl = str_starts_with($receipt, 'http://') || str_starts_with($receipt, 'https://')
                ? $receipt : '/storage/' . ltrim(preg_replace('#^storage/#', '', $receipt), '/');
            $first = $byType->get('payment_receipt');
            $second = $byType->get('panel_breakdown');
            return [
                'id' => $r->id,
                'student_name' => $student ? trim($student->first_name . ' ' . ($student->middle_name ? $student->middle_name . ' ' : '') . $student->last_name) : ($r->student_name ?? 'Student'),
                'student_number' => $student?->student_number,
                'program' => $student?->program,
                'defense_type' => $r->defense_type ?? $r->type ?? 'Defense',
                'workflow_state' => $r->workflow_state ?? $r->status ?? 'Pending',
                'receipt_url' => $receiptUrl,
                'panel_names' => $panelNames,
                'document1' => $first ? $this->documentData($first) : ['id' => null, 'status' => 'ready', 'ready_on' => now()->toDateString(), 'submitted_at' => null, 'finance_notes' => null],
                'document2' => $second ? $this->documentData($second) : ['id' => null, 'status' => 'not_created', 'ready_on' => null, 'submitted_at' => null, 'finance_notes' => null],
            ];
        });

        if (!$aa && !$finance) {
            $name = mb_strtolower((string) $user->name);
            $email = mb_strtolower((string) $user->email);
            $items = $items->filter(fn ($item) => collect($item['panel_names'])->contains(fn ($panelName) => mb_strtolower((string) $panelName) === $name || mb_strtolower((string) $panelName) === $email))->values();
        }

        $activities = HonorariumActivity::with(['user:id,first_name,last_name', 'defenseRequest.student'])
            ->latest('occurred_at')->limit(500)->get()->map(fn ($a) => [
                'id' => $a->id,
                'date' => $a->occurred_at?->toDateString(),
                'occurred_at' => $a->occurred_at?->toDateTimeString(),
                'title' => $a->title,
                'description' => $a->description,
                'activity_type' => $a->activity_type,
                'actor' => $a->user ? trim($a->user->first_name . ' ' . $a->user->last_name) : 'System',
                'defense_request_id' => $a->defense_request_id,
            ]);

        return Inertia::render('honorarium/documents/Index', [
            'role' => $this->role(), 'canSubmit' => $aa, 'canReview' => $finance,
            'items' => $items, 'activities' => $activities,
            'today' => now()->toDateString(),
        ]);
    }

    private function documentData(FinanceDocumentSubmission $doc): array
    {
        return [
            'id' => $doc->id, 'status' => $doc->status,
            'ready_on' => $doc->ready_on?->toDateString(),
            'submitted_at' => $doc->submitted_at?->toDateTimeString(),
            'finance_notes' => $doc->finance_notes,
            'payload' => $doc->payload,
        ];
    }

    public function submitFirst(Request $request, DefenseRequest $defenseRequest)
    {
        abort_unless($this->isAa(), 403);
        abort_unless($defenseRequest->proof_of_payment, 422, 'The student must upload proof of payment first.');
        $existing = FinanceDocumentSubmission::where('defense_request_id', $defenseRequest->id)->where('document_type', 'payment_receipt')->first();
        abort_if($existing && in_array($existing->status, ['submitted', 'verified'], true), 422, 'Document 1 has already been submitted to Finance.');

        DB::transaction(function () use ($defenseRequest) {
            $panelNames = $this->panelNames($defenseRequest);
            $first = FinanceDocumentSubmission::updateOrCreate(
                ['defense_request_id' => $defenseRequest->id, 'document_type' => 'payment_receipt'],
                ['status' => 'submitted', 'submitted_by' => Auth::id(), 'payload' => ['receipt_path' => $defenseRequest->proof_of_payment], 'finance_notes' => null, 'ready_on' => now()->toDateString(), 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null]
            );
            FinanceDocumentSubmission::updateOrCreate(
                ['defense_request_id' => $defenseRequest->id, 'document_type' => 'panel_breakdown'],
                ['status' => 'locked', 'submitted_by' => null, 'payload' => ['panel_names' => $panelNames], 'finance_notes' => null, 'ready_on' => now()->addDay()->toDateString(), 'submitted_at' => null, 'reviewed_by' => null, 'reviewed_at' => null]
            );
            HonorariumActivity::create([
                'defense_request_id' => $defenseRequest->id, 'user_id' => Auth::id(), 'finance_document_submission_id' => $first->id,
                'activity_type' => 'document_submitted', 'title' => 'Payment receipt sent to Finance',
                'description' => 'The AA submitted Document 1 (payment receipt). Document 2 is scheduled to become ready on ' . now()->addDay()->toDateString() . '.', 'occurred_at' => now(),
            ]);
        });
        return back()->with('success', 'Document 1 sent to Finance. Document 2 becomes ready on the next calendar day.');
    }

    public function submitSecond(Request $request, DefenseRequest $defenseRequest, FinanceDocumentWorkflowService $workflow)
    {
        abort_unless($this->isAa(), 403);
        $workflow->unlockDueDocuments();
        $doc = FinanceDocumentSubmission::where('defense_request_id', $defenseRequest->id)->where('document_type', 'panel_breakdown')->firstOrFail();
        abort_unless(in_array($doc->status, ['ready', 'rejected'], true), 422, 'Document 2 is not ready yet. It becomes ready on ' . $doc->ready_on?->toDateString() . '.');
        $doc->update(['status' => 'submitted', 'submitted_by' => Auth::id(), 'submitted_at' => now(), 'finance_notes' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
        HonorariumActivity::create([
            'defense_request_id' => $defenseRequest->id, 'user_id' => Auth::id(), 'finance_document_submission_id' => $doc->id,
            'activity_type' => 'document_submitted', 'title' => 'Panel breakdown sent to Finance',
            'description' => 'The AA submitted Document 2 (panel names / honorarium breakdown).', 'occurred_at' => now(),
        ]);
        return back()->with('success', 'Document 2 sent to Finance.');
    }

    public function review(Request $request, FinanceDocumentSubmission $submission)
    {
        abort_unless($this->isFinance(), 403);
        $data = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'finance_notes' => ['nullable', 'string', 'max:2000', 'required_if:status,rejected'],
        ]);
        abort_unless($submission->status === 'submitted', 422, 'Only submitted documents can be reviewed.');
        $submission->update(['status' => $data['status'], 'finance_notes' => $data['finance_notes'] ?? null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
        HonorariumActivity::create([
            'defense_request_id' => $submission->defense_request_id, 'user_id' => Auth::id(), 'finance_document_submission_id' => $submission->id,
            'activity_type' => 'finance_review', 'title' => $submission->document_type === 'payment_receipt' ? 'Finance reviewed payment receipt' : 'Finance reviewed panel breakdown',
            'description' => 'Finance marked this document ' . $data['status'] . (($data['finance_notes'] ?? '') !== '' ? ': ' . $data['finance_notes'] : '.'), 'occurred_at' => now(),
        ]);
        return back()->with('success', 'Finance review saved.');
    }

    private function panelNames(DefenseRequest $r): array
    {
        $ids = collect([$r->defense_chairperson, $r->defense_panelist1, $r->defense_panelist2, $r->defense_panelist3, $r->defense_panelist4])->filter()->unique();
        return Panelist::whereIn('id', $ids)->pluck('name')->values()->all();
    }
}
