import { useForm } from '@inertiajs/react';
import { ArrowRight, Check, LoaderCircle, MessageCircle, Ticket } from 'lucide-react';
import { patientChatCopy } from '../patientLanguage';

export default function PatientWelcome({
    languages,
    language,
    onLanguageChange,
}: {
    languages: string[];
    language: string;
    onLanguageChange: (language: string) => void;
}) {
    const start = useForm({
        language,
        synthetic_confirmed: false,
        adult_confirmed: false,
    });
    const copy = patientChatCopy(language).ui;
    return (
        <section className="chat-welcome">
            <span className="welcome-icon">
                <MessageCircle size={30} />
            </span>
            <p className="eyebrow">{copy.welcomeEyebrow}</p>
            <h1>{copy.welcomeTitle}</h1>
            <p className="welcome-copy">{copy.welcomeCopy}</p>
            <div className="welcome-steps">
                <span>
                    <Ticket size={17} /> {copy.getStub}
                </span>
                <span>
                    <MessageCircle size={17} /> {copy.answerInChat}
                </span>
                <span>
                    <Check size={17} /> {copy.saveForReview}
                </span>
            </div>
            <form
                className="chat-start panel"
                onSubmit={(event) => {
                    event.preventDefault();
                    start.post('/patient/start');
                }}
            >
                <label htmlFor="chat-language">{copy.languageLabel}</label>
                <select
                    id="chat-language"
                    value={start.data.language}
                    onChange={(event) => {
                        start.setData('language', event.target.value);
                        start.clearErrors();
                        onLanguageChange(event.target.value);
                    }}
                >
                    {languages.map((language) => (
                        <option key={language}>{language}</option>
                    ))}
                </select>
                <small>{copy.languageHelp}</small>
                <label className="chat-check">
                    <input
                        type="checkbox"
                        checked={start.data.synthetic_confirmed}
                        onChange={(event) =>
                            start.setData('synthetic_confirmed', event.target.checked)
                        }
                    />
                    {copy.syntheticConfirmation}
                </label>
                <label className="chat-check">
                    <input
                        type="checkbox"
                        checked={start.data.adult_confirmed}
                        onChange={(event) => start.setData('adult_confirmed', event.target.checked)}
                    />
                    {copy.adultConfirmation}
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
                    {copy.start} <ArrowRight size={17} />
                </button>
            </form>
        </section>
    );
}
