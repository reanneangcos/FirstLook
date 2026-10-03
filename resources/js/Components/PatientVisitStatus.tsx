import { StatusBadge } from './SessionList';
import type { PatientVisit } from '../types';

export default function PatientVisitStatus({ patient }: { patient: PatientVisit }) {
    if (patient.screening) return <StatusBadge session={patient.screening} />;
    const labels: Record<string, string> = {
        collecting: 'In conversation',
        ready: 'Ready to submit',
        screening: 'Screening in progress',
        completed: 'Conversation saved',
    };
    return (
        <span className="status-badge processing">{labels[patient.status] ?? patient.status}</span>
    );
}
