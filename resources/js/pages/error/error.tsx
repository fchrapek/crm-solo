import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { Poster, SoloButtonLink } from '@/components/solo/primitives';
import { DogStill } from '@/components/solo/shell/dog-still';
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

/**
 * Figma: Error 404 / 403 / 500 (1095:44768, 1095:45104, 1095:45339; phones 1095:44933 on). The surprised dog, the code
 * as the poster, one line and one way out. Signed in it stands in the shell; a guest gets the page alone and a link to log in.
 */
export default function ErrorPage({ status, auth }: ErrorPageProps) {
    const { t } = useTranslation();
    // Shared props are absent when the error fires before the Inertia middleware, so treat that as a guest.
    const signedIn = Boolean(auth?.user);
    const line = t(LINE[status] ?? LINE[500]);

    return (
        <SoloSurface className={styles.page} day="paper" data-guest={signedIn ? undefined : ''}>
            <Head title={`${status}`} />
            <main className={styles.main}>
                <span className={styles.dog}>
                    <DogStill size={128} pose="wow" />
                </span>
                <Poster size="display">{status}</Poster>
                <p className={styles.line}>{line}</p>
                <SoloButtonLink href={signedIn ? dashboard() : login()} size="control">
                    {signedIn ? t('Back to Today') : t('Log in')}
                </SoloButtonLink>
            </main>
        </SoloSurface>
    );
}
