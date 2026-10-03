import { Bot, UserRound } from 'lucide-react';
import type { ChatMessage } from '../types';

export default function ChatTranscript({ messages }: { messages: ChatMessage[] }) {
    return (
        <div
            className="chat-transcript"
            role="log"
            aria-label="Conversation"
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
                                ? 'Patient'
                                : message.source === 'model'
                                  ? 'TriageFlow · AI screening'
                                  : 'TriageFlow · intake guide'}
                        </div>
                        <p>{message.content}</p>
                    </div>
                </article>
            ))}
        </div>
    );
}
