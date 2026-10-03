import { Link } from '@inertiajs/react';
import { ArrowRight, CircleAlert, Clock3, ClipboardList, FileCheck2 } from 'lucide-react';
import type { Session } from '../types';

export function formatDate(value: string) {
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'Asia/Manila',
    }).format(new Date(value));
}
export function label(value: string) {
    return value.replaceAll('_', ' ').replace(/^./, (x) => x.toUpperCase());
}

export function StatusBadge({ session }: { session: Session }) {
    const status =
        session.processing_status === 'completed'
            ? session.method_a_status
            : session.processing_status;
    const Icon =
        status === 'classified' ? FileCheck2 : status === 'processing' ? Clock3 : CircleAlert;
    return (
        <span className={`status-badge ${status}`}>
            <Icon size={13} />
            {status === 'classified'
                ? `Classified · ESI ${session.method_a_priority}`
                : label(status ?? 'unknown')}
        </span>
    );
}

export default function SessionList({
    sessions,
    filtered = false,
}: {
    sessions: Session[];
    filtered?: boolean;
}) {
    if (!sessions.length)
        return (
            <div className="empty-state">
                <span className="empty-icon">
                    <ClipboardList size={28} strokeWidth={1.4} />
                </span>
                <h3>{filtered ? 'No matching sessions' : 'Your first screening starts here'}</h3>
                <p>
                    {filtered
                        ? 'Try another search term or processing status.'
                        : 'Submit a fictional adult case to create a saved screening record.'}
                </p>
                {!filtered && (
                    <Link className="text-link" href="/admin/screenings/create">
                        Create a screening <ArrowRight size={15} />
                    </Link>
                )}
            </div>
        );
    return (
        <div className="table-scroll">
            <table className="session-table">
                <thead>
                    <tr>
                        <th>Screening session</th>
                        <th>Language</th>
                        <th>Method A</th>
                        <th>Created · Manila time</th>
                        <th>
                            <span className="sr-only">Details</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {sessions.map((session) => (
                        <tr key={session.id}>
                            <td>
                                <Link
                                    className="session-title"
                                    href={`/admin/screenings/${session.id}`}
                                >
                                    {String(
                                        session.patient_input.main_complaint ||
                                            'Unspecified complaint',
                                    )}
                                </Link>
                                <div className="session-meta">
                                    {session.dataset_case_id || session.id.slice(0, 8)}
                                    {session.variant_id && ` / ${session.variant_id}`}
                                    {session.is_fixture && (
                                        <span className="fixture-label">MOCK FIXTURE</span>
                                    )}
                                </div>
                            </td>
                            <td>
                                <span className="language-pill">{session.language}</span>
                            </td>
                            <td>
                                <StatusBadge session={session} />
                            </td>
                            <td className="date-cell">{formatDate(session.created_at)}</td>
                            <td>
                                <Link
                                    className="row-arrow"
                                    href={`/admin/screenings/${session.id}`}
                                    aria-label={`View session ${session.id}`}
                                >
                                    <ArrowRight size={17} />
                                </Link>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
