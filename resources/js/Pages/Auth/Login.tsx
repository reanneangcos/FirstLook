import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, FlaskConical, LockKeyhole, LoaderCircle } from 'lucide-react';
import { Brand } from '../../Components/Layout';
import Field from '../../Components/Field';

export default function Login() {
    const form = useForm({ email: '', password: '' });
    return (
        <div className="login-page">
            <Head title="Staff sign in" />
            <section className="login-story">
                <Brand href="/" subtitle="PATIENT & STAFF DEMO" />
                <div>
                    <span className="eyebrow">A PRELIMINARY SCREENING STUDY</span>
                    <h1>
                        Understand the response.
                        <br />
                        <em>Preserve the comparison.</em>
                    </h1>
                    <p>
                        A research workspace for multilingual fictional cases, original model
                        predictions and a future approved rule layer.
                    </p>
                    <div className="login-methods">
                        <span>A</span>
                        <div />
                        <span>B</span>
                    </div>
                    <small>ONE SAVED RESPONSE · TWO METHODS</small>
                </div>
                <p className="login-caption">
                    <FlaskConical size={17} /> Synthetic cases only · Clinical review pending
                </p>
            </section>
            <main className="login-main">
                <div className="login-card">
                    <span className="section-icon">
                        <LockKeyhole size={23} />
                    </span>
                    <p className="eyebrow">ADMIN & HEALTHCARE STAFF</p>
                    <h2>Welcome to TriageFlow.</h2>
                    <p>Sign in to review patient stubs, conversations and triage records.</p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/login', { onFinish: () => form.reset('password') });
                        }}
                    >
                        <Field
                            label="Email address"
                            type="email"
                            autoComplete="username"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            error={form.errors.email}
                            required
                        />
                        <Field
                            label="Password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            error={form.errors.password}
                            required
                        />
                        <button type="submit" className="button primary" disabled={form.processing}>
                            {form.processing ? (
                                <LoaderCircle className="animate-spin" size={17} />
                            ) : null}{' '}
                            Sign in <ArrowRight size={17} />
                        </button>
                    </form>
                    <p className="login-help">
                        <Link href="/" className="text-link">
                            Open patient chatbot →
                        </Link>
                    </p>
                    <p className="login-help">
                        Accounts are created by the project custodian using{' '}
                        <code>php artisan researcher:create</code>.
                    </p>
                </div>
                <p className="login-disclaimer">
                    Research prototype. Not clinically validated.
                    <br />
                    For fictional adults aged 18 and above.
                </p>
            </main>
        </div>
    );
}
