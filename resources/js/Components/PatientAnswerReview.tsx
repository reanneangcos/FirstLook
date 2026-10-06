import { patientChatCopy } from '../patientLanguage';

export default function PatientAnswerReview({
    language,
    answers,
    onChange,
    disabled,
}: {
    language: string;
    answers: Record<string, string>;
    onChange: (field: string, value: string) => void;
    disabled: boolean;
}) {
    const copy = patientChatCopy(language);

    return (
        <details className="patient-answer-review">
            <summary>{copy.ui.reviewTitle}</summary>
            <p>{copy.ui.reviewHelp}</p>
            <div className="patient-review-fields">
                {Object.entries(copy.fieldLabels).map(([field, label]) => (
                    <label key={field}>
                        <span>{label}</span>
                        <textarea
                            value={answers[field] ?? ''}
                            onChange={(event) => onChange(field, event.target.value)}
                            inputMode={field === 'age' ? 'numeric' : 'text'}
                            placeholder={copy.ui.unknownPlaceholder}
                            maxLength={3000}
                            rows={field === 'age' ? 1 : 2}
                            disabled={disabled}
                        />
                    </label>
                ))}
            </div>
        </details>
    );
}
