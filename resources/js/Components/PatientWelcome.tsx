import { useForm } from '@inertiajs/react';
import { ArrowRight, Check, LoaderCircle, MessageCircle, Ticket } from 'lucide-react';

export default function PatientWelcome({ languages }: { languages: string[] }) {
    const start = useForm({
        language: 'English',
        synthetic_confirmed: false,
        adult_confirmed: false,
    });
    return (
        <section className="chat-welcome">
            <span className="welcome-icon">
                <MessageCircle size={30} />
            </span>
            <p className="eyebrow">A SIMPLE START</p>
            <h1>
                Let’s take it
                <br />
                <em>one question at a time.</em>
            </h1>
            <p className="welcome-copy">
                Share the fictional patient’s symptoms in a guided chat. We’ll keep the conversation
                together under one stub number for staff to review.
            </p>
            <div className="welcome-steps">
                <span>
                    <Ticket size={17} /> Get a stub
                </span>
                <span>
                    <MessageCircle size={17} /> Answer in chat
                </span>
                <span>
                    <Check size={17} /> Save for review
                </span>
            </div>
            <form
                className="chat-start panel"
                onSubmit={(event) => {
                    event.preventDefault();
                    start.post('/patient/start');
                }}
            >
                <label htmlFor="chat-language">Which language will you mainly use?</label>
                <select
                    id="chat-language"
                    value={start.data.language}
                    onChange={(event) => start.setData('language', event.target.value)}
                >
                    {languages.map((language) => (
                        <option key={language}>{language}</option>
                    ))}
                </select>
                <small>
                    The intake questions are in English. You can mix English, Bisaya and Tagalog in
                    your replies; your original wording is preserved.
                </small>
                <label className="chat-check">
                    <input
                        type="checkbox"
                        checked={start.data.synthetic_confirmed}
                        onChange={(event) =>
                            start.setData('synthetic_confirmed', event.target.checked)
                        }
                    />
                    I’m using a fictional case, without names or personal identifiers.
                </label>
                <label className="chat-check">
                    <input
                        type="checkbox"
                        checked={start.data.adult_confirmed}
                        onChange={(event) => start.setData('adult_confirmed', event.target.checked)}
                    />
                    The fictional patient is aged 18 or above.
                </label>
                {Object.values(start.errors).map((error, index) => (
                    <p className="chat-error" role="alert" key={index}>
                        {error}
                    </p>
                ))}
                <button className="button primary" disabled={start.processing}>
                    {start.processing ? (
                        <LoaderCircle className="animate-spin" size={18} />
                    ) : (
                        <MessageCircle size={18} />
                    )}{' '}
                    Start conversation <ArrowRight size={17} />
                </button>
            </form>
        </section>
    );
}
