<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\FinanceHonorariumSubmission;
use App\Models\Panelist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HonorariumWorkspaceController extends Controller
{
    private function role(): string
    {
        return strtolower(trim((string) Auth::user()->role));
    }

    private function isAa(): bool
    {
        return in_array($this->role(), ['administrative assistant', 'assistant', 'aa', 'dean', 'super admin'], true);
    }

    private function isFinance(): bool
    {
        return in_array($this->role(), ['finance', 'finance office', 'finance officer', 'dean', 'super admin'], true);
    }

    public function index()
    {
        abort_unless(Auth::check(), 401);
        $user = Auth::user();
        $aa = $this->isAa();
        $finance = $this->isFinance();
        $role = $this->role();

        $requests = DefenseRequest::query()
            ->with(['student:id,first_name,middle_name,last_name,student_number,program'])
            ->where(function ($q) {
                $q->whereNotNull('proof_of_payment')->orWhereHas('honorariumPayments');
            })
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        $panelIds = $requests->flatMap(fn ($r) => [
            $r->defense_chairperson, $r->defense_panelist1, $r->defense_panelist2,
            $r->defense_panelist3, $r->defense_panelist4,
        ])->filter()->unique()->values();
        $panelists = Panelist::whereIn('id', $panelIds)->get()->keyBy('id');
        $financeRows = FinanceHonorariumSubmission::with(['defenseRequest.student', 'submitter', 'verifier'])
            ->latest('submitted_at')->get()->keyBy('defense_request_id');

        $items = $requests->map(function ($request) use ($panelists, $financeRows) {
            $panelNames = collect([
                $request->defense_chairperson, $request->defense_panelist1, $request->defense_panelist2,
                $request->defense_panelist3, $request->defense_panelist4,
            ])->filter()->map(fn ($id) => $panelists->get($id)?->name ?? (string) $id)->values();
            $student = $request->student;
            $financeRow = $financeRows->get($request->id);
            return [
                'id' => $request->id,
                'student_name' => $student ? trim($student->first_name.' '.($student->middle_name ? $student->middle_name.' ' : '').$student->last_name) : ($request->student_name ?? 'Student'),
                'student_number' => $student?->student_number,
                'program' => $student?->program,
                'defense_type' => $request->defense_type ?? $request->type ?? 'Defense',
                'workflow_state' => $request->workflow_state ?? $request->status ?? 'Pending',
                'scheduled_date' => optional($request->scheduled_date)->format('Y-m-d'),
                'scheduled_time' => $request->scheduled_time,
                'receipt_url' => $request->proof_of_payment ? (str_starts_with($request->proof_of_payment, 'http://') || str_starts_with($request->proof_of_payment, 'https://') ? $request->proof_of_payment : '/storage/'.ltrim(preg_replace('#^storage/#', '', $request->proof_of_payment), '/')) : null,
                'panel_names' => $financeRow?->panel_names ?? $panelNames,
                'finance_status' => $financeRow?->status ?? 'not_submitted',
                'finance_notes' => $financeRow?->finance_notes,
                'submitted_at' => optional($financeRow?->submitted_at)->toDateTimeString(),
                'finance_submission_id' => $financeRow?->id,
            ];
        });

        if (!$aa && !$finance) {
            // Faculty can see only requests where their registered email/name matches a panelist.
            $name = mb_strtolower((string) $user->name);
            $email = mb_strtolower((string) $user->email);
            $items = $items->filter(fn ($item) => collect($item['panel_names'])->contains(fn ($panelName) => mb_strtolower((string) $panelName) === $name || mb_strtolower((string) $panelName) === $email))->values();
        }

        return Inertia::render('honorarium/workspace/Index', [
            'role' => $role,
            'canSubmitToFinance' => $aa,
            'canVerifyFinance' => $finance,
            'items' => $items,
            'rates' => $aa || $finance ? DB::table('payment_rates')->orderBy('program_level')->orderBy('type')->get(['id', 'program_level', 'type', 'defense_type', 'amount']) : [],
        ]);
    }

    public function submitToFinance(Request $request, DefenseRequest $defenseRequest)
    {
        abort_unless($this->isAa(), 403);
        abort_unless($defenseRequest->proof_of_payment, 422, 'The student must upload proof of payment first.');
        $panelIds = collect([
            $defenseRequest->defense_chairperson, $defenseRequest->defense_panelist1,
            $defenseRequest->defense_panelist2, $defenseRequest->defense_panelist3,
            $defenseRequest->defense_panelist4,
        ])->filter()->unique();
        $panelNames = Panelist::whereIn('id', $panelIds)->pluck('name')->values()->all();

        FinanceHonorariumSubmission::updateOrCreate(
            ['defense_request_id' => $defenseRequest->id],
            [
                'submitted_by' => Auth::id(),
                'panel_names' => $panelNames,
                'status' => 'submitted',
                'finance_notes' => null,
                'submitted_at' => now(),
                'verified_by' => null,
                'verified_at' => null,
            ]
        );

        return back()->with('success', 'Receipt and panel names sent to Finance.');
    }

    public function updateFinanceStatus(Request $request, FinanceHonorariumSubmission $submission)
    {
        abort_unless($this->isFinance(), 403);
        $data = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'finance_notes' => ['nullable', 'string', 'max:2000', 'required_if:status,rejected'],
        ]);
        $submission->update([
            'status' => $data['status'],
            'finance_notes' => $data['finance_notes'] ?? null,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
        ]);
        return back()->with('success', 'Finance review saved.');
    }
}
