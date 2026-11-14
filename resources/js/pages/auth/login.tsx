import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { Notice } from '@/components/solo/notice';
import { SoloButton } from '@/components/solo/primitives';
import { resetTurnstile } from '@/components/turnstile-widget';
import login from '@/routes/login';

import { AuthFrame, TurnstileSlot } from './frame';
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
    const { t } = useTranslation();

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

    return (
        <AuthFrame tone="cobalt" heading={t('Log in')} pose={Object.keys(errors).length > 0 ? 'wow' : 'cool'}>
            <Head title={t('Login')} />
            <form className={styles.form} onSubmit={onSubmit}>
                {demo && (
                    <Notice kind="info">
                        <strong>{t('Public demo')}</strong> {t('demo_login_hint')}
                    </Notice>
                )}
                {status && <Notice kind="info">{status}</Notice>}

                <SoloField label={t('Email')} htmlFor="email" error={errors.email}>
                    <SoloInput
                        {...fieldControl('email', errors.email)}
                        type="email"
                        required
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </SoloField>

                <SoloField label={t('Password')} htmlFor="password" error={errors.password}>
                    <SoloInput
                        {...fieldControl('password', errors.password)}
                        type="password"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </SoloField>

                <label className={styles.remember} htmlFor="remember">
                    <input id="remember" name="remember" type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    <span>{t('Remember me')}</span>
                </label>

                <TurnstileSlot siteKey={turnstileSiteKey} action="turnstile-spin-v2" error={errors['cf-turnstile-response']} onToken={setTurnstileToken} />

                <SoloButton type="submit" size="control" variant="primary" block disabled={processing}>
                    {processing ? t('Logging in') : t('Log in')}
                </SoloButton>
            </form>
        </AuthFrame>
    );
}
