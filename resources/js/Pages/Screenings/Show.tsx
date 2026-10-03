import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleAlert,
    FileJson,
    LockKeyhole,
    MessageSquareText,
    UserRound,
} from 'lucide-react';
import Layout, { PageHeading } from '../../Components/Layout';
import { formatDate, label, StatusBadge } from '../../Components/SessionList';
import type { Session } from '../../types';

function JsonBlock({ value }: { value: unknown }) {
    return (
        <pre className="json-block">
            {typeof value === 'string' ? value : JSON.stringify(value, null, 2)}
        </pre>
    );
}
export default function Show({ screening: s }: { screening: Session }) {
    return (
        <Layout title="Session details">
            <Link href="/screenings" className="text-link back-link">
                <ArrowLeft size={15} /> Screening history
            </Link>
            <PageHeading
                eyebrow="SAVED SCREENING RECORD"
                title={String(s.patient_input.main_complaint || 'Screening session')}
                description={`${s.dataset_case_id || s.id.slice(0, 8)}${s.variant_id ? ` / ${s.variant_id}` : ''} · ${s.language} · ${formatDate(s.created_at)}`}
                action={<StatusBadge session={s} />}
            />
            {s.is_fixture && (
                <div className="notice">
                    <CircleAlert size={21} />
                    <div>
                        <strong>Temporary fictional fixture · mocked response</strong>
                        <p>
                            This record tests the interface. It is not a live API result or a
                            clinically reviewed case.
                        </p>
                    </div>
                </div>
            )}
            <div className="detail-grid">
                <div className="detail-main">
                    <section className="panel conversation">
                        <div className="section-heading">
                            <h2>Screening exchange</h2>
                            <span className="small-tag">Fixed case</span>
                        </div>
                        <div className="chat-entry">
                            <span className="chat-avatar">
                                <UserRound size={19} />
                            </span>
                            <div>
                                <div className="chat-label">Submitted patient description</div>
                                <div className="chat-bubble patient-bubble">
                                    {s.patient_input.symptom_description ||
                                        'No narrative supplied. See the submitted fields below.'}
                                </div>
                            </div>
                        </div>
                        <div className="chat-entry">
                            <span className="chat-avatar assistant">
                                <MessageSquareText size={18} />
                            </span>
                            <div>
                                <div className="chat-label">
                                    Method A <span>Pretrained LLM · provisional</span>
                                </div>
                                <div className="chat-bubble">
                                    {s.parsed_output ? (
                                        <>
                                            <StatusBadge session={s} />
                                            <p>{s.parsed_output.explanation}</p>
                                            <small>
                                                {s.method_a_priority !== null
                                                    ? 'ESI 1 is most urgent; ESI 5 is least urgent. This is an unvalidated model output.'
                                                    : 'No priority assigned. Needs review is a processing status.'}
                                            </small>
                                        </>
                                    ) : (
                                        <>
                                            <strong>
                                                {s.processing_status === 'technical_failure'
                                                    ? 'Screening could not be completed'
                                                    : 'Processing has not completed'}
                                            </strong>
                                            <p>
                                                {s.failure_message ||
                                                    'Refresh to check the saved record. An interrupted request may remain in this state; it has no accepted prediction.'}
                                            </p>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    </section>
                    <section className="panel details-section">
                        <h2>Submitted information</h2>
                        <p className="section-description">
                            Permitted patient fields. A blank answer remains unknown.
                        </p>
                        <dl className="fact-grid">
                            {Object.entries(s.patient_input).map(([key, value]) => (
                                <div key={key}>
                                    <dt>{label(key)}</dt>
                                    <dd className={value === null ? 'unknown' : ''}>
                                        {value ?? 'Unknown'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <details>
                            <summary>Original submitted input</summary>
                            <JsonBlock value={s.original_input} />
                        </details>
                    </section>
                    <section className="panel details-section">
                        <h2>Extracted facts</h2>
                        <p className="section-description">
                            Verbatim excerpts linked to their source fields. Extraction is not
                            clinical validation.
                        </p>
                        {s.parsed_output?.extracted_facts.length ? (
                            <div className="extracted-list">
                                {s.parsed_output.extracted_facts.map((f) => (
                                    <div key={f.field}>
                                        <div>
                                            <strong>{label(f.field)}</strong>
                                            <span className="small-tag">{label(f.state)}</span>
                                        </div>
                                        <p>{f.value ?? 'Unknown'}</p>
                                        {f.evidence && (
                                            <small>Source excerpt: “{f.evidence}”</small>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="muted-copy">No extracted facts are available.</p>
                        )}
                        <h3 className="subheading">Missing information</h3>
                        {s.parsed_output ? (
                            s.parsed_output.missing_information.length ? (
                                <div className="tag-list">
                                    {s.parsed_output.missing_information.map((f, i) => (
                                        <span className="small-tag" key={`${f}-${i}`}>
                                            {label(f)}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <p className="muted-copy">No missing fields listed by the model.</p>
                            )
                        ) : (
                            <p className="muted-copy">Unavailable without an accepted response.</p>
                        )}
                    </section>
                    <section className="panel details-section">
                        <h2>Technical record</h2>
                        <p className="section-description">
                            The accepted original output is retained before any future rule
                            processing.
                        </p>
                        <dl className="fact-grid">
                            <div>
                                <dt>Internal session ID</dt>
                                <dd className="mono">{s.id}</dd>
                            </div>
                            <div>
                                <dt>Prompt version</dt>
                                <dd>{s.prompt_version}</dd>
                            </div>
                            <div>
                                <dt>Requested model</dt>
                                <dd>{s.requested_model || 'Not configured'}</dd>
                            </div>
                            <div>
                                <dt>Returned model</dt>
                                <dd>{s.returned_model || 'No accepted response'}</dd>
                            </div>
                            <div>
                                <dt>Application status</dt>
                                <dd>{label(s.processing_status)}</dd>
                            </div>
                            <div>
                                <dt>Total elapsed time</dt>
                                <dd>
                                    {s.latency_ms === null
                                        ? 'Pending'
                                        : `${s.latency_ms.toLocaleString()} ms`}
                                </dd>
                            </div>
                            <div>
                                <dt>Completed · Manila time</dt>
                                <dd>{s.completed_at ? formatDate(s.completed_at) : 'Pending'}</dd>
                            </div>
                            <div>
                                <dt>Failure code</dt>
                                <dd>{s.failure_code || 'None'}</dd>
                            </div>
                        </dl>
                        <details>
                            <summary>Request settings & output schema</summary>
                            <JsonBlock value={s.request_settings} />
                        </details>
                        <details>
                            <summary>Saved prompt text</summary>
                            <JsonBlock value={s.prompt_text} />
                        </details>
                        <details>
                            <summary>Token usage</summary>
                            <JsonBlock value={s.token_usage ?? 'Unavailable'} />
                        </details>
                        <details>
                            <summary>Original accepted model response</summary>
                            <JsonBlock value={s.original_response ?? 'No accepted response'} />
                        </details>
                        <details>
                            <summary>Parsed Method A output</summary>
                            <JsonBlock value={s.parsed_output ?? 'No accepted response'} />
                        </details>
                        <h3 className="subheading">Request attempts · {s.attempts.length}</h3>
                        {s.attempts.map((a) => (
                            <details key={a.id}>
                                <summary>
                                    Attempt {a.number} · {label(a.status)} · {a.latency_ms} ms{' '}
                                    {a.failure_code && `· ${a.failure_code}`}
                                </summary>
                                <p className="attempt-meta">
                                    HTTP {a.http_status ?? 'unavailable'} ·{' '}
                                    {formatDate(a.started_at)} → {formatDate(a.completed_at)}
                                </p>
                                <JsonBlock
                                    value={
                                        a.original_response ??
                                        'No model response retained. HTTP error bodies are excluded to avoid recording provider credentials or diagnostics.'
                                    }
                                />
                            </details>
                        ))}
                        {s.attempts.length === 0 && (
                            <p className="muted-copy">No provider request was sent.</p>
                        )}
                    </section>
                </div>
                <aside className="detail-aside">
                    <section className="panel guidance-card">
                        <span className="method-letter muted">B</span>
                        <h2>Future rule layer</h2>
                        <span className="status-badge not_implemented">
                            <LockKeyhole size={13} /> Not implemented
                        </span>
                        <p>
                            Approved rules will consume this same saved response. No second LLM
                            request is used for Method B.
                        </p>
                        <div className="inline-note">
                            <CircleAlert size={18} />
                            <p>No hybrid prediction, priority or rule trace has been generated.</p>
                        </div>
                    </section>
                    <section className="detail-note">
                        <FileJson size={20} />
                        <h3>Preserved for comparison</h3>
                        <p>
                            Method A is the original accepted prediction. Validation never replaces
                            it with a fallback priority.
                        </p>
                    </section>
                </aside>
            </div>
        </Layout>
    );
}
