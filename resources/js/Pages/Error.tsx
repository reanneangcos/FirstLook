import { Head, Link } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';

export default function Error({ status }: { status: number }) {
    const messages: Record<number, [string, string]> = {
        403: ['Access is restricted', 'Sign in with a researcher account to continue.'],
        404: [
            'This record was not found',
            'Return to your workspace and find the session in screening history.',
        ],
        419: [
            'Your session has expired',
            'Sign in again before submitting. Check screening history before repeating a request.',
        ],
        429: [
            'Too many requests',
            'Wait one minute before trying again. Check screening history before resubmitting a case.',
        ],
        500: [
            'The application encountered an error',
            'Check screening history for a saved session before trying again.',
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
                <Link href="/" className="button primary">
                    Return to workspace
                </Link>
            </section>
        </main>
    );
}
