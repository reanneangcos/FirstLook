<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScreeningRequest;
use App\Models\ScreeningSession;
use App\Services\Screening\ScreeningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScreeningController extends Controller
{
    public function dashboard(): Response
    {
        return Inertia::render('Dashboard', [
            'counts' => [
                'total' => ScreeningSession::count(),
                'classified' => ScreeningSession::where('method_a_status', 'classified')->count(),
                'needs_review' => ScreeningSession::where('method_a_status', 'needs_review')->count(),
                'technical_failure' => ScreeningSession::where('processing_status', 'technical_failure')->count(),
                'processing' => ScreeningSession::where('processing_status', 'processing')->count(),
            ],
            'sessions' => ScreeningSession::latest()->limit(6)->get($this->summaryColumns()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Screenings/Create', ['languages' => config('triage.languages')]);
    }

    public function store(StoreScreeningRequest $request, ScreeningService $service): RedirectResponse
    {
        $data = $request->validated() + ['dataset_case_id' => null, 'variant_id' => null];
        $session = $service->screen($data, $request->user()->id);

        return to_route('screenings.show', $session);
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:classified,needs_review,technical_failure,processing']]);
        $query = ScreeningSession::query();
        if ($search = $filters['q'] ?? null) {
            $query->where(function ($query) use ($search) {
                $query->where('id', 'like', '%'.$search.'%')
                    ->orWhere('dataset_case_id', 'like', '%'.$search.'%')
                    ->orWhere('variant_id', 'like', '%'.$search.'%')
                    ->orWhere('patient_input->main_complaint', 'like', '%'.$search.'%');
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where(in_array($status, ['classified', 'needs_review']) ? 'method_a_status' : 'processing_status', $status);
        }

        return Inertia::render('Screenings/Index', [
            'sessions' => $query->latest()->paginate(12, $this->summaryColumns())->withQueryString(),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    public function show(ScreeningSession $screening): Response
    {
        return Inertia::render('Screenings/Show', ['screening' => $screening->load(['attempts', 'patientVisit:id,stub_number'])]);
    }

    private function summaryColumns(): array
    {
        return ['id', 'dataset_case_id', 'variant_id', 'language', 'patient_input', 'processing_status',
            'method_a_status', 'method_a_priority', 'method_b_status', 'is_fixture', 'created_at'];
    }
}
