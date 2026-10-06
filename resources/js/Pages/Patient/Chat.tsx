import { Head, Link, useForm, router, usePoll } from '@inertiajs/react';
import { ArrowRight, ArrowUp, Check, LoaderCircle, Ticket, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Brand } from '../../Components/Layout';
import PatientWelcome from '../../Components/PatientWelcome';
import ChatTranscript from '../../Components/ChatTranscript';
import PatientAnswerReview from '../../Components/PatientAnswerReview';
import type { PatientChatVisit } from '../../types';
import { interpolate, patientChatCopy } from '../../patientLanguage';

export default function Chat({
    visit,
    question,
    languages,
    questionCount,
    review,
}: {
    visit: PatientChatVisit | null;
    question: { field: string; text: string; revision: number; follow_up: boolean } | null;
    languages: string[];
    questionCount: number;
    review: Record<string, string | number | null> | null;
}) {
    const answer = useForm({ message: '' });
    const screening = useForm({ scope_confirmed: false, patient: {} as Record<string, string> });
    const [ending, setEnding] = useState(false);
    const [confirmEnd, setConfirmEnd] = useState(false);
    const [selectedLanguage, setSelectedLanguage] = useState('English');
    const language = visit?.language ?? selectedLanguage;
    const content = patientChatCopy(language);
    const copy = content.ui;
    const endRef = useRef<HTMLDivElement>(null);
    const replyRef = useRef<HTMLTextAreaElement>(null);
    const poll = usePoll(5000, { only: ['visit', 'question'] }, { autoStart: false });
    useEffect(() => {
        if (review) {
            screening.setData(
                'patient',
                Object.fromEntries(
                    Object.entries(review).map(([field, value]) => [
                        field,
                        value === null ? '' : String(value),
                    ]),
                ),
            );
        }
    }, [visit?.status, visit?.stub_number]);
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
        answer.transform((data) => ({
            ...data,
            field: question.field,
            question_revision: question.revision,
            skip,
        }));
        answer.post('/patient/messages', {
            preserveScroll: true,
            onSuccess: () => {
                answer.reset();
                replyRef.current?.focus();
            },
        });
    }
    return (
        <div className="patient-shell" lang={content.locale}>
            <Head title={copy.title} />
            <a className="skip-link" href="#patient-main">
                {copy.skipLink}
            </a>
            <header className="patient-header">
                <Brand href="/" subtitle={copy.title} />
                <Link href="/admin" className="staff-entry">
                    <ShieldCheck size={16} /> {copy.staffSignIn} <ArrowRight size={15} />
                </Link>
            </header>
            <main id="patient-main" className="patient-main">
                {!visit ? (
                    <PatientWelcome
                        languages={languages}
                        language={selectedLanguage}
                        onLanguageChange={setSelectedLanguage}
                    />
                ) : (
                    <section className="patient-chat panel">
                        <div className="chat-topline">
                            <div>
                                <span className="eyebrow">{copy.conversation}</span>
                                <h1>
                                    <Ticket size={22} /> {visit.stub_number}
                                </h1>
                            </div>
                            <span className="small-tag">{visit.language}</span>
                        </div>
                        <div className="chat-progress">
                            <span>
                                {visit.status === 'collecting'
                                    ? interpolate(
                                          visit.conversational
                                              ? copy.coveredProgress
                                              : copy.topicProgress,
                                          {
                                              current:
                                                  visit.question_index +
                                                  (visit.conversational ? 0 : 1),
                                              total: questionCount,
                                          },
                                      )
                                    : visit.status === 'ready'
                                      ? copy.ready
                                      : visit.status === 'screening'
                                        ? copy.screening
                                        : copy.saved}
                                {question?.follow_up && ` · ${copy.followUp}`}
                            </span>
                            <span>{copy.browserSession}</span>
                        </div>
                        <ChatTranscript messages={visit.messages} language={language} />
                        <div ref={endRef} />
                        {question && (
                            <form
                                className="chat-composer"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    send(false);
                                }}
                            >
                                <label htmlFor="patient-reply">{copy.reply}</label>
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
                                        inputMode={
                                            !visit.conversational && question.field === 'age'
                                                ? 'numeric'
                                                : 'text'
                                        }
                                        placeholder={
                                            !visit.conversational && question.field === 'age'
                                                ? copy.agePlaceholder
                                                : copy.replyPlaceholder
                                        }
                                        disabled={answer.processing}
                                        aria-describedby={
                                            answer.errors.message ? 'reply-error' : undefined
                                        }
                                    />
                                    <button
                                        className="button primary"
                                        aria-label={copy.send}
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
                                {answer.processing && (
                                    <p className="chat-help" role="status">
                                        {copy.reading}
                                    </p>
                                )}
                                <div className="composer-footer">
                                    <button
                                        type="button"
                                        className="button secondary compact"
                                        onClick={() => send(true)}
                                        disabled={answer.processing}
                                    >
                                        {question.follow_up ? copy.skipFollowUp : copy.skip}
                                    </button>
                                    <small>{copy.inputHelp}</small>
                                </div>
                            </form>
                        )}
                        {visit.status === 'ready' && (
                            <form
                                className="chat-composer"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    screening.transform((data) => ({
                                        scope_confirmed: data.scope_confirmed,
                                        ...(review
                                            ? {
                                                  patient: Object.fromEntries(
                                                      Object.entries(data.patient).map(
                                                          ([field, value]) => [
                                                              field,
                                                              value.trim() === '' ? null : value,
                                                          ],
                                                      ),
                                                  ),
                                              }
                                            : {}),
                                    }));
                                    screening.post('/patient/screen', { preserveScroll: true });
                                }}
                            >
                                {review && (
                                    <PatientAnswerReview
                                        language={language}
                                        answers={screening.data.patient}
                                        onChange={(field, value) =>
                                            screening.setData('patient', {
                                                ...screening.data.patient,
                                                [field]: value,
                                            })
                                        }
                                        disabled={screening.processing}
                                    />
                                )}
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
                                    {copy.reviewConfirmation}
                                </label>
                                {Object.values(screening.errors).map((error, index) => (
                                    <p key={index} className="chat-error" role="alert">
                                        {error}
                                    </p>
                                ))}
                                <button className="button primary" disabled={screening.processing}>
                                    {screening.processing ? (
                                        <LoaderCircle size={18} className="animate-spin" />
                                    ) : (
                                        <Check size={18} />
                                    )}
                                    {screening.processing ? copy.preparing : copy.submit}
                                </button>
                                {screening.processing && (
                                    <p className="chat-help" role="status">
                                        {copy.wait}
                                    </p>
                                )}
                            </form>
                        )}
                        {visit.status === 'screening' && (
                            <div className="chat-composer" role="status">
                                <LoaderCircle className="animate-spin" size={20} />
                                <p>{copy.processing}</p>
                            </div>
                        )}
                        <div className="chat-end">
                            {confirmEnd ? (
                                <div>
                                    <p>
                                        {interpolate(copy.endConfirmation, {
                                            stub: visit.stub_number,
                                        })}
                                    </p>
                                    <button
                                        className="button secondary compact"
                                        disabled={
                                            ending ||
                                            answer.processing ||
                                            screening.processing ||
                                            visit.status === 'screening'
                                        }
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
                                        {copy.end}
                                    </button>
                                    <button
                                        className="text-link"
                                        onClick={() => setConfirmEnd(false)}
                                    >
                                        {copy.keepChatting}
                                    </button>
                                </div>
                            ) : (
                                <button
                                    className="text-link"
                                    disabled={
                                        answer.processing ||
                                        screening.processing ||
                                        visit.status === 'screening'
                                    }
                                    onClick={() => setConfirmEnd(true)}
                                >
                                    {copy.newCase}
                                </button>
                            )}
                        </div>
                    </section>
                )}
                <p className="patient-disclaimer">
                    <ShieldCheck size={15} /> {copy.disclaimer}
                </p>
            </main>
        </div>
    );
}
