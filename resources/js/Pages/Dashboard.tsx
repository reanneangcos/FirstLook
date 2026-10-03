import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    CheckCheck,
    CircleAlert,
    ClipboardList,
    FlaskConical,
    Layers2,
} from 'lucide-react';
import Layout, { IntegrationNotice, NewScreeningLink, PageHeading } from '../Components/Layout';
import SessionList from '../Components/SessionList';
import type { Session } from '../types';

type Counts = {
    total: number;
    classified: number;
    needs_review: number;
    technical_failure: number;
    processing: number;
};
export default function Dashboard({ counts, sessions }: { counts: Counts; sessions: Session[] }) {
    const stats = [
        {
            label: 'Total sessions',
            value: counts.total,
            icon: ClipboardList,
            note: `${counts.processing} currently processing`,
            tone: 'green',
        },
        {
            label: 'Classified',
            value: counts.classified,
            icon: CheckCheck,
            note: 'Method A · ESI 1–5',
            tone: 'blue',
        },
        {
            label: 'Needs review',
            value: counts.needs_review,
            icon: CircleAlert,
            note: 'No priority assigned',
            tone: 'amber',
        },
        {
            label: 'Technical failures',
            value: counts.technical_failure,
            icon: Layers2,
            note: 'Request or output issues',
            tone: 'gray',
        },
    ];
    return (
        <Layout title="Overview">
            <PageHeading
                eyebrow="RESEARCH OVERVIEW"
                title="A clearer view of every screening."
                description="Your workspace for a controlled, shared-response comparison."
                action={<NewScreeningLink />}
            />
            <div className="research-banner">
                <div className="banner-icon">
                    <FlaskConical size={27} strokeWidth={1.5} />
                </div>
                <div>
                    <span className="eyebrow">THE TRIAGEFLOW STUDY</span>
                    <h2>One response. Two methods to examine.</h2>
                    <p>
                        Explore multilingual preliminary screening with a pretrained LLM and a
                        future approved rule layer.
                    </p>
                </div>
                <span className="banner-tag">
                    PROTOTYPE <span>01</span>
                </span>
            </div>
            <div className="stats-grid">
                {stats.map(({ label, value, icon: Icon, note, tone }) => (
                    <section className="stat-card" key={label}>
                        <div className="stat-top">
                            <span>{label}</span>
                            <span className={`stat-icon ${tone}`}>
                                <Icon size={17} />
                            </span>
                        </div>
                        <strong>{value.toLocaleString()}</strong>
                        <p>{note}</p>
                    </section>
                ))}
            </div>
            <div className="section-heading">
                <div>
                    <h2>Recent screenings</h2>
                    <p>Stored sessions, including any explicitly labeled test fixtures.</p>
                </div>
                <Link className="text-link" href="/admin/screenings">
                    View history <ArrowUpRight size={16} />
                </Link>
            </div>
            <section className="panel">
                <SessionList sessions={sessions} />
            </section>
            <div className="study-grid">
                <section className="panel study-card">
                    <div className="section-heading">
                        <h2>The comparison</h2>
                        <span className="small-tag">Paired response</span>
                    </div>
                    <div className="method-row">
                        <span className="method-letter">A</span>
                        <div>
                            <strong>Pretrained LLM</strong>
                            <p>Original preliminary prediction, saved for inspection.</p>
                        </div>
                        <span className="method-state">Implemented</span>
                    </div>
                    <div className="method-connector">
                        <ArrowRight size={14} />
                        <span>Same saved response</span>
                    </div>
                    <div className="method-row">
                        <span className="method-letter muted">B</span>
                        <div>
                            <strong>LLM + approved rules</strong>
                            <p>Rule processing awaits clinical review.</p>
                        </div>
                        <span className="small-tag">Not implemented</span>
                    </div>
                </section>
                <section className="panel boundaries-card">
                    <div className="section-icon">
                        <FlaskConical size={19} />
                    </div>
                    <h2>Built for a bounded study</h2>
                    <p>
                        Adults 18 and above. English, Tagalog, Bisaya and code-switched inputs. No
                        personal identifiers, diagnoses or treatment advice.
                    </p>
                    <div className="boundary-footer">
                        Provisional prompt · Clinical criteria pending
                    </div>
                </section>
            </div>
            <IntegrationNotice />
        </Layout>
    );
}
