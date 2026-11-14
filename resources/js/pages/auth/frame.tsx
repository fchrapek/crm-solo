import { FieldError } from '@/components/solo/field';
import { Wordmark } from '@/components/solo/logos';
import { Poster } from '@/components/solo/primitives';
import { type DogPose } from '@/components/solo/shell/dog-still';
import { Dog } from '@/components/solo/shell/helper/dog';
import { SoloSurface } from '@/components/solo/surface';
import { TurnstileWidget } from '@/components/turnstile-widget';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import styles from './login.module.css';

const SHADE = { cobalt: [10, 20, 70], vermilion: [96, 22, 6] } as const;

/** A field the person types into takes the dog's eyes; a checkbox does not. */
export function takesTheCursor(target: EventTarget | null): boolean {
    const field = target as Partial<Pick<HTMLInputElement, 'tagName' | 'type'>> | null;

    return field?.tagName === 'INPUT' && field.type !== 'checkbox';
}

/** While a field has the cursor the dog looks at the form with its glasses up, unless the form asks for another pose (an error). */
export function formDog(pose: DogPose, peek: boolean, filling: boolean): { pose: DogPose; peek: boolean } {
    return { pose: filling && pose === 'cool' ? 'look-down-left' : pose, peek: peek || filling };
}

/**
 * Figma: Logowanie, Lista oczekujących. One layout in two colours: the page is the colour, the words and the form
 * stand on it, in the app's column from 768 px, and the dog is the large live one. It follows the pointer; while a
 * field has the cursor it looks at the form with its sunglasses up, and it takes the pose the form asks for
 * otherwise. A title replaces the wordmark from 768 px.
 */
export function AuthFrame({
    tone,
    title,
    heading,
    lede,
    pose = 'cool',
    peek = false,
    children,
}: {
    tone: 'cobalt' | 'vermilion';
    title?: string;
    /** The page's name for a screen whose visible headline is the wordmark. */
    heading?: string;
    lede?: ReactNode;
    pose?: DogPose;
    peek?: boolean;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const [filling, setFilling] = useState(false);
    const dog = formDog(pose, peek, filling);

    return (
        <SoloSurface day="paper" className={styles.page} data-tone={tone} data-titled={title !== undefined ? '' : undefined}>
            <div className={styles.column}>
                <div className={styles.brand}>
                    <Wordmark className={styles.wordmark} />
                    <p className={styles.claim}>{t('brand_claim')}</p>
                </div>
                <span className={styles.dog}>
                    <Dog size={960} fluid live pose={dog.pose} peek={dog.peek} shade={SHADE[tone]} />
                </span>
                <main
                    className={styles.main}
                    onFocus={(event) => takesTheCursor(event.target) && setFilling(true)}
                    onBlur={() => setFilling(false)}
                >
                {heading !== undefined && <h1 className="sr-only">{heading}</h1>}
                {(title !== undefined || lede !== undefined) && (
                    <div className={styles.words} aria-live="polite">
                        {title !== undefined && <Poster>{title}</Poster>}
                        {lede !== undefined && <p className={styles.lede}>{lede}</p>}
                    </div>
                )}
                    {children}
                </main>
            </div>
        </SoloSurface>
    );
}

/** Figma: TurnstileSlot. The Cloudflare widget's place with its own error line. */
export function TurnstileSlot({ siteKey, action, error, onToken }: { siteKey?: string | null; action: string; error?: string; onToken: (token: string) => void }) {
    return siteKey ? (
        <div className={styles.turnstile}>
            <TurnstileWidget siteKey={siteKey} action={action} onToken={onToken} />
            <FieldError htmlFor="cf-turnstile-response" error={error} />
        </div>
    ) : null;
}
