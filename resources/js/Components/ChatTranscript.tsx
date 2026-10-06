import { Bot, UserRound } from 'lucide-react';
import type { ChatMessage } from '../types';
import { patientChatCopy } from '../patientLanguage';

export default function ChatTranscript({
    messages,
    language = 'English',
}: {
    messages: ChatMessage[];
    language?: string;
}) {
    const copy = patientChatCopy(language).ui;
    return (
        <div
            className="chat-transcript"
            role="log"
            aria-label={copy.transcript}
            aria-live="polite"
            aria-relevant="additions"
        >
            {messages.map((message) => (
                <article key={message.id} className={`transcript-message ${message.role}`}>
                    <span className="transcript-avatar" aria-hidden="true">
                        {message.role === 'patient' ? <UserRound size={18} /> : <Bot size={19} />}
                    </span>
                    <div className="transcript-body">
                        <div className="transcript-label">
                            {message.role === 'patient'
                                ? copy.patient
                                : message.source === 'model'
                                  ? copy.model
                                  : message.source === 'intake'
                                    ? copy.intake
                                    : copy.guide}
                        </div>
                        <p>{message.content}</p>
                    </div>
                </article>
            ))}
        </div>
    );
}
