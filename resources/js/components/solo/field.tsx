import type { ComponentProps, InputHTMLAttributes, ReactNode } from 'react';
import styles from './field.module.css';

const errorId = (id: string) => `${id}-error`;

/** The SoloField label's id, for a control the label cannot name by itself. */
export const labelId = (id: string) => `${id}-label`;

/** The id, invalid state and error link a control needs; spread it on SoloInput or a solo SelectTrigger. */
export function fieldControl(id: string, error?: string) {
    return {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error ? errorId(id) : undefined,
    } as const;
}

/** Figma: Input (Outline). */
/** The field box class, for a control that is not an input, such as the phone select's button. */
export const fieldBoxClass = styles.box;

export function SoloField({
    label,
    htmlFor,
    error,
    className,
    children,
}: {
    label: ReactNode;
    htmlFor: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={[styles.field, className].filter(Boolean).join(' ')}>
            <label className={styles.label} htmlFor={htmlFor} id={labelId(htmlFor)}>
                {label}
            </label>
            {children}
            {error && (
                <p id={errorId(htmlFor)} className={styles.error}>
                    {error}
                </p>
            )}
        </div>
    );
}

export function SoloInput({ className, ...rest }: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={[styles.box, className].filter(Boolean).join(' ')} {...rest} />;
}

export function SoloTextarea({ className, ...rest }: ComponentProps<'textarea'>) {
    return <textarea className={[styles.box, styles.area, className].filter(Boolean).join(' ')} {...rest} />;
}

export function SoloFieldRow({ children }: { children: ReactNode }) {
    return <div className={styles.row}>{children}</div>;
}
