import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { Wordmark } from '@/components/solo/logos';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { resetTurnstile, TurnstileWidget } from '@/components/turnstile-widget';
import { login } from '@/routes';
import czesc from '@/routes/czesc';
import type { SharedData } from '@/types';

import styles from './login.module.css';
import own from './czesc.module.css';

type WaitlistForm = {
    email: string;
    name: string;
    website: string;
    'cf-turnstile-response': string;
};

interface WaitlistProps {
    /** True right after a submission; the same answer for every address. */
    joined: boolean;
    /** Present only when the backend has TURNSTILE_SECRET configured. */
    turnstileSiteKey?: string | null;
}

/** Closed mode: what a guest sees instead of the app. Visual design is not final (Figma first). */
export default function Waitlist({ joined, turnstileSiteKey }: WaitlistProps) {
    const { t } = useTranslation();
    const { flash } = usePage<SharedData & { flash?: { error?: string | null } }>().props;

    const { data, setData, submit, processing, errors, reset } = useForm<WaitlistForm>({
        email: '',
        name: '',
        website: '',
        'cf-turnstile-response': '',
    });

    const setTurnstileToken = (token: string) => setData('cf-turnstile-response', token);

    const onSubmit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        submit(czesc.store(), {
            onSuccess: () => reset(),
            onError: () => resetTurnstile(setTurnstileToken),
        });
    };

    return (
        <div className={styles.page} data-day="timer">
            <Head title={t('Waitlist')} />
            <main className={styles.frame}>
                <div className={styles.brand}>
                    <p className={styles.eyebrow}>{t('Closed for now')}</p>
                    <Wordmark className={styles.wordmark} />
                </div>

                {joined ? (
                    <section className={styles.form} aria-live="polite">
                        <h1 className={styles.title}>{t('Thank you')}</h1>
                        <p className={own.lede}>{t('waitlist_thanks')}</p>
                        <SoloButtonLink href={login.url()} variant="ghost" size="lg" block>
                            {t('Log in')}
                        </SoloButtonLink>
                    </section>
                ) : (
                    <form className={styles.form} onSubmit={onSubmit}>
                        <h1 className={styles.title}>{t('Join the waitlist')}</h1>
                        <p className={own.lede}>{t('waitlist_lede')}</p>

                        <label className={styles.field} htmlFor="email">
                            <span>{t('Email')}</span>
                            <input
                                id="email"
                                type="email"
                                required
                                autoFocus
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            {errors.email && <span className={styles.error}>{errors.email}</span>}
                        </label>

                        <label className={styles.field} htmlFor="name">
                            <span>{t('Name (optional)')}</span>
                            <input id="name" type="text" autoComplete="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <span className={styles.error}>{errors.name}</span>}
                        </label>

                        <div className={own.honeypot} aria-hidden="true">
                            <label htmlFor="website">{t('Leave this field empty')}</label>
                            <input
                                id="website"
                                type="text"
                                tabIndex={-1}
                                autoComplete="off"
                                value={data.website}
                                onChange={(e) => setData('website', e.target.value)}
                            />
                        </div>

                        {turnstileSiteKey && (
                            <div className={styles.turnstile}>
                                <TurnstileWidget siteKey={turnstileSiteKey} action="waitlist" onToken={setTurnstileToken} />
                                {errors['cf-turnstile-response'] && <span className={styles.error}>{errors['cf-turnstile-response']}</span>}
                            </div>
                        )}

                        <SoloButton type="submit" size="lg" block disabled={processing}>
                            {processing ? t('Signing up') : t('Sign me up')} →
                        </SoloButton>

                        {flash?.error && (
                            <p className={styles.error} role="alert">
                                {flash.error}
                            </p>
                        )}

                        <p className={own.signIn}>
                            {t('Already have an account?')} <Link href={login.url()}>{t('Log in')}</Link>
                        </p>
                    </form>
                )}
            </main>
        </div>
    );
}
