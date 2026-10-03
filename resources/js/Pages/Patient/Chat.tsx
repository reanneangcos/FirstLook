import { Head, Link, useForm, router, usePoll } from '@inertiajs/react';
import { ArrowRight, ArrowUp, Check, LoaderCircle, Ticket, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Brand } from '../../Components/Layout';
import PatientWelcome from '../../Components/PatientWelcome';
import ChatTranscript from '../../Components/ChatTranscript';
import type { PatientChatVisit } from '../../types';

export default function Chat({
    visit,
    question,
    languages,
    questionCount,
}: {
    visit: PatientChatVisit | null;
    question: { field: string; text: string } | null;
    languages: string[];
    questionCount: number;
}) {
    const answer = useForm({ message: '' });
    const screening = useForm({ scope_confirmed: false });
    const [ending, setEnding] = useState(false);
    const [confirmEnd, setConfirmEnd] = useState(false);
    const endRef = useRef<HTMLDivElement>(null);
    const replyRef = useRef<HTMLTextAreaElement>(null);
    const poll = usePoll(5000, { only: ['visit', 'question'] }, { autoStart: false });
    useEffect(() => {
        if (visit?.status === 'screening') {
            poll.start();
        } else {
            poll.stop();
        }
        return () => poll.stop();
    }, [visit?.status]);
    useEffect(() => {
        if (visit) {
            endRef.current?.scrollIntoView({ behavior: 'instant', block: 'end' });
        }
    }, [visit?.messages.length]);
    function send(skip: boolean) {
        if (!question || answer.processing) return;
        answer.transform((data) => ({ ...data, field: question.field, skip }));
        answer.post('/patient/messages', {
            preserveScroll: true,
            onSuccess: () => {
                answer.reset();
                replyRef.current?.focus();
            },
        });
    }
    return (
        <div className="patient-shell">
            <Head title="Patient chatbot" />
            <a className="skip-link" href="#patient-main">
                Skip to conversation
            </a>
            <header className="patient-header">
                <Brand href="/" subtitle="PATIENT CHATBOT" />
                <Link href="/admin" className="staff-entry">
                    <ShieldCheck size={16} /> Staff sign in <ArrowRight size={15} />
                </Link>
            </header>
            <main id="patient-main" className="patient-main">
                {!visit ? (
                    <PatientWelcome languages={languages} />
                ) : (
                    <section className="patient-chat panel">
                        <div className="chat-topline">
                            <div>
                                <span className="eyebrow">YOUR CONVERSATION</span>
                                <h1>
                                    <Ticket size={22} /> {visit.stub_number}
                                </h1>
                            </div>
                            <span className="small-tag">{visit.language}</span>
                        </div>
                        <div className="chat-progress">
                            <span>
                                {visit.status === 'collecting'
                                    ? `Question ${visit.question_index + 1} of ${questionCount}`
                                    : visit.status === 'ready'
                                      ? 'Ready to submit'
                                      : visit.status === 'screening'
                                        ? 'Screening in progress'
                                        : 'Saved for staff review'}
                            </span>
                            <span>Saved in this browser session</span>
                        </div>
                        <ChatTranscript messages={visit.messages} />
                        <div ref={endRef} />
                        {question && (
                            <form
                                className="chat-composer"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    send(false);
                                }}
                            >
                                <label htmlFor="patient-reply">Your reply</label>
                                <div className="composer-input">
                                    <textarea
                                        ref={replyRef}
                                        id="patient-reply"
                                        value={answer.data.message}
                                        onChange={(event) =>
                                            answer.setData('message', event.target.value)
                                        }
                                        maxLength={3000}
                                        rows={3}
                                        inputMode={question.field === 'age' ? 'numeric' : 'text'}
                                        placeholder={
                                            question.field === 'age'
                                                ? 'Age in years…'
                                                : 'Write in your own words…'
                                        }
                                        disabled={answer.processing}
                                        aria-describedby={
                                            answer.errors.message ? 'reply-error' : undefined
                                        }
                                    />
                                    <button
                                        className="button primary"
                                        aria-label="Send reply"
                                        disabled={answer.processing || !answer.data.message.trim()}
                                    >
                                        {answer.processing ? (
                                            <LoaderCircle className="animate-spin" size={18} />
                                        ) : (
                                            <ArrowUp size={20} />
                                        )}
                                    </button>
                                </div>
                                {answer.errors.message && (
                                    <p id="reply-error" className="chat-error" role="alert">
                                        {answer.errors.message}
                                    </p>
                                )}
                                <div className="composer-footer">
                                    <button
                                        type="button"
                                        className="button secondary compact"
                                        onClick={() => send(true)}
                                        disabled={answer.processing}
                                    >
                                        Unknown / skip
                                    </button>
                                    <small>
                                        No test results, measured vital signs or identifiers.
                                    </small>
                                </div>
                            </form>
                        )}
                        {visit.status === 'ready' && (
                            <form
                                className="chat-composer"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    screening.post('/patient/screen', { preserveScroll: true });
                                }}
                            >
                                <label className="chat-check">
                                    <input
                                        type="checkbox"
                                        checked={screening.data.scope_confirmed}
                                        onChange={(event) =>
                                            screening.setData(
                                                'scope_confirmed',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    I reviewed this fictional case. It contains no identifiers,
                                    measured vital signs, examination findings, test results or
                                    answer-key labels.
                                </label>
                                {screening.errors.scope_confirmed && (
                                    <p className="chat-error" role="alert">
                                        {screening.errors.scope_confirmed}
                                    </p>
                                )}
                                <button className="button primary" disabled={screening.processing}>
                                    {screening.processing ? (
                                        <LoaderCircle size={18} className="animate-spin" />
                                    ) : (
                                        <Check size={18} />
                                    )}
                                    {screening.processing
                                        ? 'Preparing the screening…'
                                        : 'Submit for screening'}
                                </button>
                                {screening.processing && (
                                    <p className="chat-help" role="status">
                                        Your answers are saved. This can take up to 95 seconds;
                                        please keep this tab open.
                                    </p>
                                )}
                            </form>
                        )}
                        {visit.status === 'screening' && (
                            <div className="chat-composer" role="status">
                                <LoaderCircle className="animate-spin" size={20} />
                                <p>
                                    Your screening is being processed. This page checks for the
                                    saved result automatically. If processing remains unfinished,
                                    staff can inspect your stub.
                                </p>
                            </div>
                        )}
                        <div className="chat-end">
                            {confirmEnd ? (
                                <div>
                                    <p>
                                        End this browser’s access to {visit.stub_number}? Staff will
                                        keep its saved history. A new conversation receives a new
                                        stub.
                                    </p>
                                    <button
                                        className="button secondary compact"
                                        disabled={ending}
                                        onClick={() => {
                                            setEnding(true);
                                            router.post(
                                                '/patient/end',
                                                {},
                                                {
                                                    onFinish: () => {
                                                        setEnding(false);
                                                        setConfirmEnd(false);
                                                        answer.reset();
                                                        screening.reset();
                                                    },
                                                },
                                            );
                                        }}
                                    >
                                        End conversation
                                    </button>
                                    <button
                                        className="text-link"
                                        onClick={() => setConfirmEnd(false)}
                                    >
                                        Keep chatting
                                    </button>
                                </div>
                            ) : (
                                <button
                                    className="text-link"
                                    disabled={screening.processing || visit.status === 'screening'}
                                    onClick={() => setConfirmEnd(true)}
                                >
                                    End this conversation / start a new case
                                </button>
                            )}
                        </div>
                    </section>
                )}
                <p className="patient-disclaimer">
                    <ShieldCheck size={15} /> Fictional-case thesis demo · Preliminary screening
                    only · Clinical review pending
                </p>
            </main>
        </div>
    );
}
