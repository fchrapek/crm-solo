import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEvent, useEffect } from 'react';
import { useTranslation } from 'react-i18next';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePageActions } from '@/contexts/page-context';
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

declare global {
    interface Window {
        turnstile?: { reset: (widget?: string | HTMLElement) => void };
        onTurnstileToken?: (token: string) => void;
        onTurnstileExpired?: () => void;
    }
}

export default function Login({ status, demo, turnstileSiteKey }: LoginProps) {
    const { t } = useTranslation();
    const { setAuthInfo } = usePageActions();

    useEffect(() => {
        setAuthInfo(t('Log in to your account'), t('Enter your email and password below to log in'));
    }, [setAuthInfo, t]);

    const { data, setData, submit, processing, errors, reset } = useForm<Required<LoginForm>>({
        email: demo?.email ?? '',
        password: demo?.password ?? '',
        remember: false,
        'cf-turnstile-response': '',
    });

    // Turnstile: implicit rendering picks up the .cf-turnstile div once the
    // api.js script loads; the data-callback feeds the token into the Inertia
    // form payload (useForm submits its state, never the DOM form).
    useEffect(() => {
        if (!turnstileSiteKey) return;

        window.onTurnstileToken = (token: string) => setData('cf-turnstile-response', token);
        window.onTurnstileExpired = () => setData('cf-turnstile-response', '');

        if (!document.getElementById('cf-turnstile-script')) {
            const script = document.createElement('script');
            script.id = 'cf-turnstile-script';
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [turnstileSiteKey]);

    const onSubmit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        submit(login.store(), {
            onFinish: () => reset('password'),
            // Tokens are single-use: a failed login keeps the page (no
            // navigation), so the widget must issue a fresh token before the
            // retry or Cloudflare rejects it as timeout-or-duplicate.
            onError: () => {
                window.turnstile?.reset();
                setData('cf-turnstile-response', '');
            },
        });
    };

    return (
        <>
            <Head title={t('Login')} />

            {demo && (
                <div className={styles.demoHint}>
                    <strong>{t('Public demo')}</strong>
                    <span>{t('demo_login_hint')}</span>
                </div>
            )}

            <form className={styles.form} onSubmit={onSubmit}>
                <div className={styles.grid}>
                    <div className={styles.fieldGrid}>
                        <Label htmlFor="email">{t('Email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className={styles.fieldGrid}>
                        <div className={styles.labelRow}>
                            <Label htmlFor="password">{t('Password')}</Label>
                        </div>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className={styles.checkboxRow}>
                        <Checkbox
                            id="remember"
                            name="remember"
                            tabIndex={3}
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', Boolean(checked))}
                        />
                        <Label htmlFor="remember">{t('Remember me')}</Label>
                    </div>

                    {turnstileSiteKey && (
                        <div className={styles.fieldGrid}>
                            <div
                                className="cf-turnstile"
                                data-sitekey={turnstileSiteKey}
                                data-action="turnstile-spin-v2"
                                data-callback="onTurnstileToken"
                                data-expired-callback="onTurnstileExpired"
                                data-error-callback="onTurnstileExpired"
                            />
                            <InputError message={errors['cf-turnstile-response']} />
                        </div>
                    )}

                    <Button type="submit" className={styles.submitButton} tabIndex={4} disabled={processing}>
                        {processing && <LoaderCircle className={styles.spinner} />}
                        {t('Log in')}
                    </Button>
                </div>
            </form>

            {status && <div className={styles.statusMessage}>{status}</div>}
        </>
    );
}
