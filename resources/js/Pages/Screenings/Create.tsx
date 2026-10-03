import { Link, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CircleHelp,
    FileText,
    LoaderCircle,
    LockKeyhole,
    MessageSquareText,
} from 'lucide-react';
import Layout, { IntegrationNotice, PageHeading } from '../../Components/Layout';
import Field from '../../Components/Field';

const emptyPatient = {
    age: '',
    reported_sex: '',
    main_complaint: '',
    symptom_description: '',
    other_symptoms: '',
    known_conditions: '',
    allergies: '',
    maintenance_medications: '',
    onset: '',
    duration: '',
    reported_severity: '',
    worsening: '',
    tests_completed: '',
    tests_requested: '',
    medical_devices: '',
};
type PatientKey = keyof typeof emptyPatient;
export default function Create({ languages }: { languages: string[] }) {
    const form = useForm({
        patient: emptyPatient,
        language: 'English',
        dataset_case_id: '',
        variant_id: '',
        synthetic_confirmed: false,
        adult_confirmed: false,
        scope_confirmed: false,
    });
    const errors = form.errors as Record<string, string>;
    const field = (key: PatientKey, label: string, hint?: string, multiline = false) => (
        <Field
            key={key}
            label={label}
            hint={hint}
            multiline={multiline}
            value={form.data.patient[key]}
            error={errors[`patient.${key}`]}
            type={key === 'age' ? 'number' : 'text'}
            min={key === 'age' ? 18 : undefined}
            maxLength={3000}
            placeholder={key === 'age' ? 'Unknown' : 'Unknown if left blank'}
            onChange={(e) =>
                form.setData('patient', { ...form.data.patient, [key]: e.target.value })
            }
        />
    );
    return (
        <Layout title="New screening">
            <PageHeading
                eyebrow="SYNTHETIC CASE INTAKE"
                title="Start with the reported facts."
                description="Enter one fictional adult case. Keep its original wording and language."
            />
            <div className="intake-grid">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/screenings');
                    }}
                    className="intake-form"
                >
                    {Object.keys(errors).length > 0 && (
                        <div className="error-notice" role="alert">
                            Check the highlighted fields before submitting.
                            {errors.patient && <p>{errors.patient}</p>}
                        </div>
                    )}
                    <section className="panel form-section">
                        <div className="form-section-heading">
                            <span>01</span>
                            <div>
                                <h2>Case context</h2>
                                <p>
                                    Identifiers track synthetic cases only and stay out of the model
                                    prompt.
                                </p>
                            </div>
                        </div>
                        <div className="form-grid">
                            <Field
                                label="Dataset case ID"
                                hint="Optional · for example, DEV-001"
                                value={form.data.dataset_case_id}
                                maxLength={80}
                                error={errors.dataset_case_id}
                                onChange={(e) => form.setData('dataset_case_id', e.target.value)}
                            />
                            <Field
                                label="Variant ID"
                                hint="Optional · for example, DEV-001-EN"
                                value={form.data.variant_id}
                                maxLength={80}
                                error={errors.variant_id}
                                onChange={(e) => form.setData('variant_id', e.target.value)}
                            />
                            <div className="field">
                                <label htmlFor="language">Input language</label>
                                <select
                                    id="language"
                                    value={form.data.language}
                                    onChange={(e) => form.setData('language', e.target.value)}
                                >
                                    {languages.map((l) => (
                                        <option key={l}>{l}</option>
                                    ))}
                                </select>
                            </div>
                            {field(
                                'age',
                                'Age in years',
                                '18 or above; leave blank if the adult’s exact age is unknown.',
                            )}
                            {field(
                                'reported_sex',
                                'Reported sex',
                                'Use the reported wording, or leave unknown.',
                            )}
                        </div>
                    </section>
                    <section className="panel form-section">
                        <div className="form-section-heading">
                            <span>02</span>
                            <div>
                                <h2>In the patient’s words</h2>
                                <p>
                                    Reported symptoms only. Do not add interpretations or results.
                                </p>
                            </div>
                        </div>
                        {field('main_complaint', 'Main complaint')}
                        {field(
                            'symptom_description',
                            'Patient description',
                            'English, Tagalog, Bisaya or code-switched text. No names or contact details.',
                            true,
                        )}
                        {field(
                            'other_symptoms',
                            'Other reported symptoms',
                            'Record negative findings only when explicitly reported.',
                            true,
                        )}
                        <div className="form-grid">
                            {field('onset', 'Onset')}
                            {field('duration', 'Duration')}
                            {field(
                                'reported_severity',
                                'Reported severity',
                                'Use the patient’s own description.',
                            )}
                            {field(
                                'worsening',
                                'Worsening',
                                'Reported change, explicit absence of change, or unknown.',
                            )}
                        </div>
                    </section>
                    <section className="panel form-section">
                        <div className="form-section-heading">
                            <span>03</span>
                            <div>
                                <h2>Relevant reported history</h2>
                                <p>Missing information remains unknown.</p>
                            </div>
                        </div>
                        <div className="form-grid">
                            {field('known_conditions', 'Known conditions')}
                            {field('allergies', 'Known allergies')}
                            {field('maintenance_medications', 'Maintenance medications')}
                            {field('medical_devices', 'Medical devices or catheters')}
                            {field(
                                'tests_completed',
                                'Tests already completed',
                                'Test names only. Exclude all result values.',
                            )}
                            {field(
                                'tests_requested',
                                'Tests requested by a clinician',
                                'Existing requests only. This prototype does not order tests.',
                            )}
                        </div>
                    </section>
                    <section className="panel form-section confirmation">
                        <h2>Confirm the study boundaries</h2>
                        {(
                            [
                                [
                                    'synthetic_confirmed',
                                    'This case is fictional and contains no personal identifiers.',
                                ],
                                ['adult_confirmed', 'The fictional patient is aged 18 or above.'],
                                [
                                    'scope_confirmed',
                                    'I excluded measured vital signs, examination findings, test results and answer-key information.',
                                ],
                            ] as const
                        ).map(([key, text]) => (
                            <div key={key}>
                                <label className="checkbox-label">
                                    <input
                                        type="checkbox"
                                        checked={form.data[key]}
                                        onChange={(e) => form.setData(key, e.target.checked)}
                                    />
                                    <span>{text}</span>
                                </label>
                                {errors[key] && (
                                    <p className="field-error" role="alert">
                                        {errors[key]}
                                    </p>
                                )}
                            </div>
                        ))}
                        <div className="form-actions">
                            <Link href="/" className="button secondary">
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                className="button primary"
                                disabled={form.processing}
                            >
                                {form.processing ? (
                                    <LoaderCircle size={17} className="animate-spin" />
                                ) : (
                                    <ArrowRight size={17} />
                                )}
                                {form.processing ? 'Processing screening…' : 'Save & screen case'}
                            </button>
                        </div>
                        <p className="processing-help" role="status" aria-live="polite">
                            {form.processing
                                ? 'Saving your case and waiting for the model. Up to three bounded attempts may take about 95 seconds. Keep this page open.'
                                : 'The submitted case and the original response are saved together.'}
                        </p>
                    </section>
                </form>
                <aside className="intake-aside">
                    <section className="panel guidance-card">
                        <span className="section-icon">
                            <MessageSquareText size={21} />
                        </span>
                        <h2>A screening, with a record.</h2>
                        <p>
                            The next screen presents the submitted case and Method A’s response,
                            followed by the facts and request details.
                        </p>
                        <ol className="steps">
                            <li>
                                <span>
                                    <FileText size={15} />
                                </span>
                                <div>
                                    <strong>Save the input</strong>
                                    <p>Preserve wording and unknown values.</p>
                                </div>
                            </li>
                            <li>
                                <span>
                                    <MessageSquareText size={15} />
                                </span>
                                <div>
                                    <strong>Capture Method A</strong>
                                    <p>Keep the original model response.</p>
                                </div>
                            </li>
                            <li>
                                <span>
                                    <LockKeyhole size={15} />
                                </span>
                                <div>
                                    <strong>Reserve Method B</strong>
                                    <p>Await approved rules for the same response.</p>
                                </div>
                            </li>
                        </ol>
                        <div className="inline-note">
                            <CircleHelp size={18} />
                            <p>
                                The current provisional prompt requests Needs review until approved
                                clinical criteria are available.
                            </p>
                        </div>
                    </section>
                    <IntegrationNotice />
                    <div className="aside-footnote">
                        <Check size={16} />
                        <p>No automatic ESI 5 fallback. No inferred negative findings.</p>
                    </div>
                </aside>
            </div>
        </Layout>
    );
}
