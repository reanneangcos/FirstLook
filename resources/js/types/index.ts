export type Patient = Record<string, string | number | null>;
export type Fact = {
    field: string;
    state: 'reported' | 'absent' | 'unknown' | 'not_applicable';
    value: string | null;
    evidence: string | null;
};
export type Output = {
    status: 'classified' | 'needs_review';
    priority: number | null;
    extracted_facts: Fact[];
    missing_information: string[];
    explanation: string;
};
export type Session = {
    patient_visit?: { id: number; stub_number: string } | null;
    id: string;
    dataset_case_id: string | null;
    variant_id: string | null;
    language: string;
    patient_input: Patient;
    original_input: Patient;
    processing_status: 'processing' | 'completed' | 'technical_failure';
    method_a_status: 'classified' | 'needs_review' | null;
    method_a_priority: number | null;
    method_b_status: 'not_implemented';
    is_fixture: boolean;
    created_at: string;
    completed_at: string | null;
    parsed_output: Output | null;
    original_response: string | null;
    requested_model: string;
    returned_model: string | null;
    prompt_version: string;
    prompt_text: string;
    request_settings: Record<string, unknown>;
    token_usage: Record<string, unknown> | null;
    latency_ms: number | null;
    failure_code: string | null;
    failure_message: string | null;
    attempts: {
        id: number;
        number: number;
        status: string;
        http_status: number | null;
        original_response: string | null;
        parsed_output: Output | null;
        failure_code: string | null;
        latency_ms: number;
        started_at: string;
        completed_at: string;
    }[];
};
export type SharedProps = {
    auth: { user: { name: string; email: string } | null };
    integration: { configured: boolean; model: string; prompt_version: string };
    [key: string]: unknown;
};
export type Pagination = {
    data: Session[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type ChatMessage = {
    id: number;
    role: 'patient' | 'assistant';
    source: 'patient' | 'guide' | 'model' | 'system';
    content: string;
    created_at: string;
};
export type PatientChatVisit = {
    stub_number: string;
    language: string;
    status: 'collecting' | 'ready' | 'screening' | 'completed';
    question_index: number;
    messages: ChatMessage[];
};
export type PatientVisit = Omit<PatientChatVisit, 'messages'> & {
    id: number;
    created_at: string;
    answers?: Patient;
    messages?: ChatMessage[];
    screening: Session | null;
};
export type Paginated<T> = Omit<Pagination, 'data'> & { data: T[] };
