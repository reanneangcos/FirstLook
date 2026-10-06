<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScreenPatientChatRequest;
use App\Http\Requests\StartPatientChatRequest;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\PatientVisit;
use App\Services\Screening\PatientChatService;
use App\Services\Screening\PatientInterview;
use App\Services\Screening\ScreeningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientChatController extends Controller
{
    public function show(Request $request, PatientChatService $chat): Response
    {
        $visit = PatientVisit::find($request->session()->get('patient_visit_id'));

        return Inertia::render('Patient/Chat', [
            'visit' => $visit ? ['stub_number' => $visit->stub_number, 'language' => $visit->language,
                'status' => $visit->status, 'question_index' => $visit->question_index,
                'conversational' => $visit->interview_state !== null,
                'messages' => $visit->messages()->get(['id', 'role', 'source', 'content', 'created_at'])] : null,
            'review' => $visit?->status === 'ready' && $visit->interview_state !== null ? $visit->answers : null,
            'question' => $visit ? $chat->currentQuestion($visit) : null,
            'questionCount' => count(PatientInterview::questions()), 'languages' => config('triage.languages'),
        ]);
    }

    public function start(StartPatientChatRequest $request, PatientChatService $chat): RedirectResponse
    {
        if (! PatientVisit::whereKey($request->session()->get('patient_visit_id'))->exists()) {
            $visit = $chat->start($request->validated('language'));
            $request->session()->put('patient_visit_id', $visit->id);
        }

        return to_route('patient.chat');
    }

    public function store(StoreChatMessageRequest $request, PatientChatService $chat): RedirectResponse
    {
        $chat->answer($this->currentVisit($request), $request->validated());

        return to_route('patient.chat');
    }

    public function screen(ScreenPatientChatRequest $request, PatientChatService $chat, ScreeningService $service): RedirectResponse
    {
        $visit = $this->currentVisit($request);
        $chat->screen($visit, $service, $request->validated('patient'));

        return to_route('patient.chat');
    }

    public function end(Request $request): RedirectResponse
    {
        $request->session()->forget('patient_visit_id');

        return to_route('patient.chat');
    }

    private function currentVisit(Request $request): PatientVisit
    {
        return PatientVisit::findOrFail($request->session()->get('patient_visit_id'));
    }
}
