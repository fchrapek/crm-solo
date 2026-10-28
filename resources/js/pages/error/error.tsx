import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { Masthead } from '@/components/solo/masthead';
import { Poster } from '@/components/solo/primitives';
import { SoloSurface } from '@/components/solo/surface';
import { dashboard, login } from '@/routes';
import { SharedData } from '@/types';

import styles from './error.module.css';

interface ErrorPageProps extends Partial<SharedData> {
    status: number;
}

const LINE: Record<number, string> = {
    503: 'We are doing some maintenance. Try again in a moment.',
    500: 'Something went wrong on our side. Try again in a moment.',
    429: 'Too many requests at once. Try again in a moment.',
    404: 'This page does not exist or has moved.',
    403: 'This page does not belong to your account.',
};

/** Figma: Błąd 404 / 403 / 500 (581:17161, 581:17188, 581:17215; phones 581:17242 on). A guest gets no masthead and a link to log in. */
export default function ErrorPage({ status, auth }: ErrorPageProps) {
    const { t } = useTranslation();
    // Shared props are absent when the error fires before the Inertia middleware, so treat that as a guest.
    const signedIn = Boolean(auth?.user);
    const line = t(LINE[status] ?? LINE[500]);

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={`${status}`} />
            <div className={styles.frame}>
                {signedIn && <Masthead current={null} />}
                <main className={styles.main}>
                    <Poster size="display">{status}</Poster>
                    <p className={styles.line}>{line}</p>
                    <Link className={styles.link} href={signedIn ? dashboard() : login()}>
                        {signedIn ? t('Back to Today') : t('Log in')} →
                    </Link>
                </main>
            </div>
        </SoloSurface>
    );
}
