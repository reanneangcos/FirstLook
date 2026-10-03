import { Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Ticket } from 'lucide-react';
import Layout, { PageHeading } from '../../../Components/Layout';
import PatientVisitStatus from '../../../Components/PatientVisitStatus';
import ChatTranscript from '../../../Components/ChatTranscript';
import { formatDate, label } from '../../../Components/SessionList';
import type { PatientVisit } from '../../../types';

export default function Show({ patient }: { patient: PatientVisit }) {
    usePoll(10000, { only: ['patient'] });
    return (
        <Layout title="Patient conversation">
            <Link href="/admin/patients" className="text-link back-link">
                <ArrowLeft size={15} /> Patients & chats
            </Link>
            <PageHeading
                eyebrow="PATIENT RECORD"
                title={patient.stub_number}
                description={`${patient.language} · Started ${formatDate(patient.created_at)} · Fictional adult case`}
                action={<PatientVisitStatus patient={patient} />}
            />
            <div className="detail-grid">
                <section className="panel staff-transcript">
                    <div className="section-heading">
                        <div>
                            <h2>Conversation history</h2>
                            <p>Original messages, in the order they were saved.</p>
                        </div>
                        <Ticket size={22} />
                    </div>
                    <ChatTranscript messages={patient.messages ?? []} />
                </section>
                <aside className="detail-aside">
                    <section className="panel guidance-card">
                        <h2>Preliminary triage</h2>
                        <PatientVisitStatus patient={patient} />
                        {patient.screening ? (
                            <>
                                <p>
                                    {patient.screening.parsed_output?.explanation ??
                                        patient.screening.failure_message ??
                                        'The screening has not completed.'}
                                </p>
                                <Link
                                    className="button primary"
                                    href={`/admin/screenings/${patient.screening.id}`}
                                >
                                    Full triage record <ArrowRight size={16} />
                                </Link>
                            </>
                        ) : (
                            <p>
                                The result will appear after the patient submits the completed
                                conversation.
                            </p>
                        )}
                        <div className="inline-note">
                            <p>
                                Method B: <strong>Not implemented.</strong> Clinical criteria and
                                approved rules are pending.
                            </p>
                        </div>
                    </section>
                    <section className="panel guidance-card">
                        <h2>Reported information</h2>
                        <dl className="fact-grid">
                            {Object.entries(patient.answers ?? {}).map(([field, value]) => (
                                <div key={field}>
                                    <dt>{label(field)}</dt>
                                    <dd>{value ?? 'Unknown'}</dd>
                                </div>
                            ))}
                        </dl>
                        {!Object.keys(patient.answers ?? {}).length && (
                            <p>No answers submitted yet.</p>
                        )}
                    </section>
                </aside>
            </div>
        </Layout>
    );
}
