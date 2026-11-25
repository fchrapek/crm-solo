import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { Notice } from '@/components/solo/notice';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { resetTurnstile } from '@/components/turnstile-widget';
import { login } from '@/routes';
import waitlist from '@/routes/waitlist';
import type { SharedData } from '@/types';

import own from './waitlist.module.css';
import { AuthFrame, TurnstileSlot } from './frame';
import styles from './login.module.css';

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

/** Closed mode: what a guest sees instead of the app. */
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

        submit(waitlist.store(), {
            onSuccess: () => reset(),
            onError: () => resetTurnstile(setTurnstileToken),
        });
    };

    return (
        <AuthFrame
            tone="vermilion"
            title={joined ? t('waitlist_joined_title') : t('waitlist_title')}
            lede={joined ? t('waitlist_thanks') : t('waitlist_lede')}
            pose={Object.keys(errors).length > 0 || Boolean(flash?.error) ? 'wow' : 'cool'}
            peek={joined}
        >
            <Head title={t('Waitlist')} />
            {joined ? (
                <section className={styles.form}>
                    <SoloButtonLink href={login.url()} variant="secondary" size="control" block>
                        {t('Log in')}
                    </SoloButtonLink>
                </section>
            ) : (
                <form className={styles.form} onSubmit={onSubmit}>
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

                    <SoloField label={t('Name (optional)')} htmlFor="name" error={errors.name}>
                        <SoloInput {...fieldControl('name', errors.name)} type="text" autoComplete="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    </SoloField>

                    <div className={own.honeypot} aria-hidden="true">
                        <label htmlFor="website">{t('Leave this field empty')}</label>
                        <input id="website" type="text" tabIndex={-1} autoComplete="off" value={data.website} onChange={(e) => setData('website', e.target.value)} />
                    </div>

                    <TurnstileSlot siteKey={turnstileSiteKey} action="waitlist" error={errors['cf-turnstile-response']} onToken={setTurnstileToken} />

                    {flash?.error && (
                        <Notice kind="warn" role="alert">
                            {flash.error}
                        </Notice>
                    )}

                    <SoloButton type="submit" size="control" variant="primary" block disabled={processing}>
                        {processing ? t('Signing up') : t('waitlist_submit')}
                    </SoloButton>

                    <p className={own.signIn}>
                        {t('Already have an account?')} <Link href={login.url()}>{t('Log in')}</Link>
                    </p>
                </form>
            )}
        </AuthFrame>
    );
}
