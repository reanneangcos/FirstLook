import { Link, router } from '@inertiajs/react';
import { Search, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import Layout, { NewScreeningLink, PageHeading } from '../../Components/Layout';
import SessionList from '../../Components/SessionList';
import type { Pagination } from '../../types';

export default function Index({
    sessions,
    filters,
}: {
    sessions: Pagination;
    filters: { q: string; status: string };
}) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    return (
        <Layout title="Screening history">
            <PageHeading
                eyebrow="THE RESEARCH RECORD"
                title="Every case, kept in context."
                description="Find submitted cases and inspect their original screening results."
                action={<NewScreeningLink />}
            />
            <section className="panel">
                <form
                    className="search-toolbar"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/screenings',
                            { q, status },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    <div className="search-field">
                        <Search size={18} />
                        <label htmlFor="search" className="sr-only">
                            Search sessions
                        </label>
                        <input
                            id="search"
                            value={q}
                            maxLength={100}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search complaint, session or case ID…"
                        />
                    </div>
                    <label htmlFor="status-filter" className="sr-only">
                        Processing status
                    </label>
                    <select
                        id="status-filter"
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                    >
                        <option value="">All statuses</option>
                        <option value="classified">Classified</option>
                        <option value="needs_review">Needs review</option>
                        <option value="technical_failure">Technical failure</option>
                        <option value="processing">Processing</option>
                    </select>
                    <button className="button secondary" type="submit">
                        Search
                    </button>
                    {(filters.q || filters.status) && (
                        <Link
                            className="text-link"
                            href="/admin/screenings"
                            onClick={() => {
                                setQ('');
                                setStatus('');
                            }}
                        >
                            Clear
                        </Link>
                    )}
                </form>
                <SessionList sessions={sessions.data} filtered={!!filters.q || !!filters.status} />
                <div className="pagination">
                    <span>
                        {sessions.total} saved {sessions.total === 1 ? 'session' : 'sessions'} ·
                        Page {sessions.current_page} of {sessions.last_page}
                    </span>
                    <div>
                        {sessions.prev_page_url ? (
                            <Link
                                className="button secondary compact"
                                href={sessions.prev_page_url}
                            >
                                <ChevronLeft size={16} /> Previous
                            </Link>
                        ) : (
                            <button className="button secondary compact" disabled>
                                <ChevronLeft size={16} /> Previous
                            </button>
                        )}
                        {sessions.next_page_url ? (
                            <Link
                                className="button secondary compact"
                                href={sessions.next_page_url}
                            >
                                Next <ChevronRight size={16} />
                            </Link>
                        ) : (
                            <button className="button secondary compact" disabled>
                                Next <ChevronRight size={16} />
                            </button>
                        )}
                    </div>
                </div>
            </section>
            <p className="record-note">
                Priorities are provisional. Needs review and technical failures have no assigned
                priority.
            </p>
        </Layout>
    );
}
