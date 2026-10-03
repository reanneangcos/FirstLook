import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    FlaskConical,
    History,
    LayoutDashboard,
    LogOut,
    Plus,
    ShieldCheck,
    Users,
    MessageSquare,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import type { SharedProps } from '../types';

export function Brand({
    href = '/admin',
    subtitle = 'STAFF WORKSPACE',
}: {
    href?: string;
    subtitle?: string;
}) {
    return (
        <Link href={href} className="brand" aria-label="TriageFlow home">
            <span className="brand-mark">
                <Activity size={23} strokeWidth={2.2} />
            </span>
            <span>
                Triage<span className="font-normal">Flow</span>
                <small>{subtitle}</small>
            </span>
        </Link>
    );
}

export default function Layout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { auth, integration } = usePage<SharedProps>().props;
    const { url } = usePage();
    const navigation = [
        { href: '/admin', text: 'Overview', icon: LayoutDashboard, active: url === '/admin' },
        {
            href: '/admin/patients',
            text: 'Patients & chats',
            icon: Users,
            active: url.startsWith('/admin/patients'),
        },
        {
            href: '/admin/screenings/create',
            text: 'New screening',
            icon: Plus,
            active: url.startsWith('/admin/screenings/create'),
        },
        {
            href: '/admin/screenings',
            text: 'Screening history',
            icon: History,
            active:
                url.startsWith('/admin/screenings') && !url.startsWith('/admin/screenings/create'),
        },
    ];
    return (
        <div className="app-shell">
            <Head title={title} />
            <a href="#main" className="skip-link">
                Skip to content
            </a>
            <aside className="sidebar">
                <Brand />
                <div className="workspace-label">WORKSPACE</div>
                <nav aria-label="Main navigation">
                    {navigation.map(({ href, text, icon: Icon, active }) => (
                        <Link
                            key={href}
                            href={href}
                            className={`nav-item ${active ? 'active' : ''}`}
                            aria-current={active ? 'page' : undefined}
                        >
                            <Icon size={19} />
                            <span>{text}</span>
                            {active && <span className="nav-dot" />}
                        </Link>
                    ))}
                </nav>
                <Link href="/" className="nav-item patient-preview">
                    <MessageSquare size={18} /> Patient chatbot
                </Link>
                <div className="sidebar-study">
                    <FlaskConical size={21} />
                    <h3>A study in progress</h3>
                    <p>
                        Fictional adult cases.
                        <br />
                        Preliminary screening only.
                    </p>
                    <span className="small-tag">Clinical review pending</span>
                </div>
                <div className="sidebar-user">
                    <span className="avatar">{auth.user?.name.slice(0, 1).toUpperCase()}</span>
                    <div>
                        <strong>{auth.user?.name}</strong>
                        <small>Admin / healthcare staff</small>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        aria-label="Sign out"
                        className="icon-button"
                    >
                        <LogOut size={18} />
                    </Link>
                </div>
            </aside>
            <div className="workspace-main">
                <header className="topbar">
                    <div className="breadcrumb">
                        Workspace <span>/</span> <strong>{title}</strong>
                    </div>
                    <span className="environment">
                        <span /> Synthetic data only
                    </span>
                </header>
                <main id="main" tabIndex={-1} className="main-content">
                    {children}
                </main>
                <footer className="footer">
                    <span>
                        <ShieldCheck size={14} /> Research prototype · Not clinically validated
                    </span>
                    <span>
                        TriageFlow <span className="footer-version">v0.1</span>
                    </span>
                </footer>
            </div>
        </div>
    );
}

export function IntegrationNotice() {
    const { integration } = usePage<SharedProps>().props;
    return !integration.configured ? (
        <div className="notice">
            <span className="notice-dot" />
            <div>
                <strong>API setup pending</strong>
                <p>
                    Add your OpenAI API key in the server’s .env file to connect screening requests.
                    Sessions can still be saved with a technical-failure status.
                </p>
            </div>
        </div>
    ) : (
        <div className="notice neutral">
            <ShieldCheck size={20} />
            <div>
                <strong>API configuration present</strong>
                <p>
                    Account access and live model behavior have not been verified by this interface.
                </p>
            </div>
        </div>
    );
}

export function PageHeading({
    eyebrow,
    title,
    description,
    action,
}: {
    eyebrow: string;
    title: string;
    description: string;
    action?: React.ReactNode;
}) {
    return (
        <div className="page-heading">
            <div>
                <p className="eyebrow">{eyebrow}</p>
                <h1>{title}</h1>
                <p className="page-description">{description}</p>
            </div>
            {action}
        </div>
    );
}

export function NewScreeningLink() {
    return (
        <Link href="/admin/screenings/create" className="button primary">
            <Plus size={17} /> New screening <ArrowUpRight size={16} />
        </Link>
    );
}
