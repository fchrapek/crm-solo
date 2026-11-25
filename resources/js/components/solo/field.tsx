import { Icon } from '@/components/ui/icon';
import type { ComponentProps, ReactNode } from 'react';
import styles from './field.module.css';

const errorId = (id: string) => `${id}-error`;
const hintId = (id: string) => `${id}-hint`;

/** The SoloField label's id, for a control the label cannot name by itself. */
export const labelId = (id: string) => `${id}-label`;

/** The id, invalid state and the error and hint links a control needs; spread it on SoloInput or a solo SelectTrigger. */
export function fieldControl(id: string, error?: string, hint = false) {
    const described = [error ? errorId(id) : null, hint ? hintId(id) : null].filter(Boolean).join(' ');

    return {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': described === '' ? undefined : described,
    } as const;
}

/** Figma: Input (Filled on tone). */
/** The field box class, for a control that is not an input, such as the phone select's button. */
export const fieldBoxClass = styles.box;

export function SoloField({
    label,
    htmlFor,
    error,
    hint,
    className,
    children,
}: {
    label: ReactNode;
    htmlFor: string;
    error?: string;
    /** Figma: Field's one hint line under the control; pass `true` as fieldControl's third argument to link it. */
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={[styles.field, className].filter(Boolean).join(' ')}>
            <label className={styles.label} htmlFor={htmlFor} id={labelId(htmlFor)}>
                {label}
            </label>
            {children}
            {hint !== undefined && (
                <p id={hintId(htmlFor)} className={styles.hint}>
                    {hint}
                </p>
            )}
            <FieldError htmlFor={htmlFor} error={error} />
        </div>
    );
}

/** A field's error line, for a control that is not a SoloField (a checkbox row); link it with fieldControl's id. */
export function FieldError({ htmlFor, error }: { htmlFor: string; error?: string }) {
    return error ? (
        <p id={errorId(htmlFor)} className={styles.error}>
            <Icon name="warning" size={16} />
            {error}
        </p>
    ) : null;
}

export function SoloInput({ className, ...rest }: ComponentProps<'input'>) {
    return <input className={[styles.box, className].filter(Boolean).join(' ')} {...rest} />;
}

export function SoloTextarea({ className, ...rest }: ComponentProps<'textarea'>) {
    return <textarea className={[styles.box, styles.area, className].filter(Boolean).join(' ')} {...rest} />;
}

export function SoloFieldRow({ children }: { children: ReactNode }) {
    return <div className={styles.row}>{children}</div>;
}
