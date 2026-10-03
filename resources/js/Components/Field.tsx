import { useId } from 'react';
import type { ChangeEventHandler, InputHTMLAttributes } from 'react';

export default function Field({
    label,
    hint,
    error,
    multiline,
    ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange'> & {
    onChange?: ChangeEventHandler<HTMLInputElement | HTMLTextAreaElement>;
    label: string;
    hint?: string;
    error?: string;
    multiline?: boolean;
}) {
    const id = useId();
    return (
        <div className="field">
            <label htmlFor={id}>{label}</label>
            {hint && (
                <p id={`${id}-hint`} className="field-hint">
                    {hint}
                </p>
            )}
            {multiline ? (
                <textarea
                    id={id}
                    rows={4}
                    value={String(props.value ?? '')}
                    placeholder={props.placeholder}
                    maxLength={props.maxLength}
                    onChange={props.onChange}
                    aria-invalid={!!error}
                    aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
                />
            ) : (
                <input
                    id={id}
                    {...props}
                    aria-invalid={!!error}
                    aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
                />
            )}
            {error && (
                <p role="alert" className="field-error" id={`${id}-error`}>
                    {error}
                </p>
            )}
        </div>
    );
}
