<?php

namespace App\Http\Controllers;

use App\Models\PatientVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:collecting,ready,screening,classified,needs_review,technical_failure']]);
        $query = PatientVisit::query()->select(['id', 'stub_number', 'language', 'status', 'question_index', 'created_at'])->with('screening:id,patient_visit_id,processing_status,method_a_status,method_a_priority');
        if ($search = $filters['q'] ?? null) {
            $query->where('stub_number', 'like', '%'.$search.'%');
        }
        if ($status = $filters['status'] ?? null) {
            if (in_array($status, ['classified', 'needs_review', 'technical_failure'])) {
                $query->whereHas('screening', fn (Builder $query) => $query->where(
                    $status === 'technical_failure' ? 'processing_status' : 'method_a_status', $status));
            } else {
                $query->where('status', $status);
            }
        }

        return Inertia::render('Admin/Patients/Index', [
            'patients' => $query->latest()->paginate(12)->withQueryString(),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    public function show(PatientVisit $patient): Response
    {
        return Inertia::render('Admin/Patients/Show', ['patient' => $patient->load(['messages', 'screening'])]);
    }
}
