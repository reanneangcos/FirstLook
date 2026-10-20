import { Link, router, usePoll } from '@inertiajs/react';
import { Search, ArrowRight, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import Layout, { PageHeading } from '../../../Components/Layout';
import PatientVisitStatus from '../../../Components/PatientVisitStatus';
import { formatDate } from '../../../Components/SessionList';
import type { PatientVisit, Paginated } from '../../../types';

export default function Index({
    patients,
    filters,
}: {
    patients: Paginated<PatientVisit>;
    filters: { q: string; status: string };
}) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);

    useEffect(() => {
        setQ(filters.q);
        setStatus(filters.status);
    }, [filters.q, filters.status]);
    usePoll(10000, { only: ['patients'] });
    return (
        <Layout title="Patients & chats">
            <PageHeading
                eyebrow="ADMIN & HEALTHCARE STAFF"
                title="Every stub. The whole conversation."
                description="Review fictional patient chats and their saved preliminary triage results."
            />
            <section className="panel">
                <form
                    className="search-toolbar"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            '/admin/patients',
                            { q, status },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <div className="search-field">
                        <Search size={18} />
                        <label htmlFor="stub-search" className="sr-only">
                            Search stub number
                        </label>
                        <input
                            id="stub-search"
                            placeholder="Search a stub, e.g. FL-000001"
                            maxLength={80}
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                        />
                    </div>
                    <label htmlFor="patient-status" className="sr-only">
                        Patient status
                    </label>
                    <select
                        id="patient-status"
                        value={status}
                        onChange={(event) => {
                            const newStatus = event.target.value;
                            setStatus(newStatus);
                            router.get(
                                '/admin/patients',
                                { q, status: newStatus },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <option value="">All statuses</option>
                        <option value="collecting">In conversation</option>
                        <option value="ready">Ready to submit</option>
                        <option value="screening">Screening in progress</option>
                        <option value="classified">Classified</option>
                        <option value="needs_review">Needs review</option>
                        <option value="technical_failure">Technical failure</option>
                    </select>
                </form>
                {patients.data.length ? (
                    <div className="table-scroll">
                        <table className="session-table">
                            <thead>
                                <tr>
                                    <th>Patient stub</th>
                                    <th>Language</th>
                                    <th>Triage / progress</th>
                                    <th>Started · Manila time</th>
                                    <th>Conversation</th>
                                </tr>
                            </thead>
                            <tbody>
                                {patients.data.map((patient) => (
                                    <tr key={patient.id}>
                                        <td>
                                            <Link
                                                className="session-title"
                                                href={`/admin/patients/${patient.id}`}
                                            >
                                                {patient.stub_number}
                                            </Link>
                                            <div className="session-meta">Fictional adult case</div>
                                        </td>
                                        <td>{patient.language}</td>
                                        <td>
                                            <PatientVisitStatus patient={patient} />
                                        </td>
                                        <td className="date-cell">
                                            {formatDate(patient.created_at)}
                                        </td>
                                        <td>
                                            <Link
                                                className="text-link"
                                                href={`/admin/patients/${patient.id}`}
                                            >
                                                View history <ArrowRight size={15} />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="empty-state">
                        <span className="empty-icon">
                            <Users size={28} />
                        </span>
                        <h3>
                            {filters.q || filters.status
                                ? 'No matching patients'
                                : 'Patient conversations start here'}
                        </h3>
                        <p>
                            {filters.q || filters.status
                                ? 'Try another stub or status.'
                                : 'Start a fictional case in the patient chatbot. Its stub and conversation will appear here.'}
                        </p>
                    </div>
                )}
                <div className="pagination">
                    <span>
                        {patients.total} patient stubs · Page {patients.current_page} of{' '}
                        {patients.last_page}
                    </span>
                    <div>
                        {patients.prev_page_url && (
                            <Link
                                className="button secondary compact"
                                href={patients.prev_page_url}
                            >
                                Previous
                            </Link>
                        )}
                        {patients.next_page_url && (
                            <Link
                                className="button secondary compact"
                                href={patients.next_page_url}
                            >
                                Next
                            </Link>
                        )}
                    </div>
                </div>
            </section>
            <p className="record-note">
                Updates every 10 seconds. Stub numbers identify records; they are not queue
                positions or clinical priorities.
            </p>
        </Layout>
    );
}
