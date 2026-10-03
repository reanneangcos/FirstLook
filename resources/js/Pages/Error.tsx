import { Head, Link, usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import type { SharedProps } from '../types';

export default function Error({ status }: { status: number }) {
    const { auth } = usePage<SharedProps>().props;
    const messages: Record<number, [string, string]> = {
        403: [
            'Access is restricted',
            'Return to the chatbot to start a conversation, or sign in for staff access.',
        ],
        404: [
            'This record was not found',
            'Return to the chatbot or your staff workspace to continue.',
        ],
        419: [
            'Your session has expired',
            'Reload the page before submitting again. Staff can still find saved conversations by stub number.',
        ],
        429: [
            'Too many requests',
            'Wait one minute before trying again. Your saved conversation will remain available.',
        ],
        500: [
            'The application encountered an error',
            'Reload your conversation before trying again. Staff can check its saved history.',
        ],
        503: ['The application is temporarily unavailable', 'Try again after a short pause.'],
    };
    const [title, description] = messages[status] ?? messages[500];
    return (
        <main className="min-h-screen flex items-center justify-center p-6">
            <Head title={title} />
            <section className="panel max-w-lg p-8">
                <CircleAlert size={28} className="mb-5" />
                <p className="eyebrow">TRIAGEFLOW · {status}</p>
                <h1>{title}</h1>
                <p className="my-5 text-sm">{description}</p>
                <Link href={auth?.user ? '/admin' : '/'} className="button primary">
                    Return to TriageFlow
                </Link>
            </section>
        </main>
    );
}
