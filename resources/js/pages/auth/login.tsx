import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { Wordmark } from '@/components/solo/logos';
import { SoloButton } from '@/components/solo/primitives';
import { resetTurnstile, TurnstileWidget } from '@/components/turnstile-widget';
import login from '@/routes/login';

import styles from './login.module.css';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
    'cf-turnstile-response': string;
};

interface LoginProps {
    status?: string;
    /** Set on the public demo instance: prefills the form and shows the hint. */
    demo?: { email: string; password: string } | null;
    /** Present only when the backend has TURNSTILE_SECRET configured. */
    turnstileSiteKey?: string | null;
}

export default function Login({ status, demo, turnstileSiteKey }: LoginProps) {
    const { t, i18n } = useTranslation();

    const { data, setData, submit, processing, errors, reset } = useForm<Required<LoginForm>>({
        email: demo?.email ?? '',
        password: demo?.password ?? '',
        remember: false,
        'cf-turnstile-response': '',
    });

    const setTurnstileToken = (token: string) => setData('cf-turnstile-response', token);

    const onSubmit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        submit(login.store(), {
            onFinish: () => reset('password'),
            // A failed login keeps the page, so the retry needs a fresh token.
            onError: () => resetTurnstile(setTurnstileToken),
        });
    };

    const today = new Intl.DateTimeFormat(i18n.language, { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());

    return (
        <div className={styles.page} data-day="timer">
            <Head title={t('Login')} />
            <main className={styles.frame}>
                <div className={styles.brand}>
                    <p className={styles.eyebrow}>{today.charAt(0).toUpperCase() + today.slice(1)}</p>
                    <Wordmark className={styles.wordmark} />
                </div>

                <form className={styles.form} onSubmit={onSubmit}>
                    <h1 className={styles.title}>{t('Log in')}</h1>

                    {demo && (
                        <div className={styles.demoHint}>
                            <strong>{t('Public demo')}</strong>
                            <span>{t('demo_login_hint')}</span>
                        </div>
                    )}

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

                    <label className={styles.field} htmlFor="password">
                        <span>{t('Password')}</span>
                        <input
                            id="password"
                            type="password"
                            required
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        {errors.password && <span className={styles.error}>{errors.password}</span>}
                    </label>

                    <label className={styles.remember} htmlFor="remember">
                        <input
                            id="remember"
                            name="remember"
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        <span>{t('Remember me')}</span>
                    </label>

                    {turnstileSiteKey && (
                        <div className={styles.turnstile}>
                            <TurnstileWidget siteKey={turnstileSiteKey} action="turnstile-spin-v2" onToken={setTurnstileToken} />
                            {errors['cf-turnstile-response'] && <span className={styles.error}>{errors['cf-turnstile-response']}</span>}
                        </div>
                    )}

                    <SoloButton type="submit" size="lg" block disabled={processing}>
                        {processing ? t('Logging in') : t('Log in')} →
                    </SoloButton>

                    {status && <p className={styles.status}>{status}</p>}
                </form>
            </main>
        </div>
    );
}
