import { Bot, UserRound } from 'lucide-react';
import type { ChatMessage } from '../types';
import { patientChatCopy } from '../patientLanguage';

export default function ChatTranscript({
    messages,
    language = 'English',
    pendingReply = null,
}: {
    messages: ChatMessage[];
    language?: string;
    /** A reply that is being sent: shown immediately with a typing indicator until the server answers. */
    pendingReply?: { content: string | null } | null;
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
            {pendingReply && (
                <>
                    {pendingReply.content && (
                        <article className="transcript-message patient pending">
                            <span className="transcript-avatar" aria-hidden="true">
                                <UserRound size={18} />
                            </span>
                            <div className="transcript-body">
                                <div className="transcript-label">{copy.patient}</div>
                                <p>{pendingReply.content}</p>
                            </div>
                        </article>
                    )}
                    <article className="transcript-message assistant typing">
                        <span className="transcript-avatar" aria-hidden="true">
                            <Bot size={19} />
                        </span>
                        <div className="transcript-body">
                            <div className="transcript-label">{copy.intake}</div>
                            <p role="status" aria-label={copy.reading}>
                                <span className="typing-dot" />
                                <span className="typing-dot" />
                                <span className="typing-dot" />
                            </p>
                        </div>
                    </article>
                </>
            )}
        </div>
    );
}
