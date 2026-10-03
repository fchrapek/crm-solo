import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { dashboard, login } from '@/routes';
import { SharedData } from '@/types';

import styles from './error.module.css';

interface ErrorPageProps extends Partial<SharedData> {
    status: number;
}

export default function ErrorPage({ status, auth }: ErrorPageProps) {
    const { t } = useTranslation();
    // Shared props are absent when the error fires before the Inertia middleware, so treat that as a guest.
    const signedIn = Boolean(auth?.user);

    const title = {
        503: t('503: Service Unavailable'),
        500: t('500: Server Error'),
        429: t('429: Too Many Requests'),
        404: t('404: Page Not Found'),
        403: t('403: Forbidden'),
    }[status];

    const description = {
        503: t('Sorry, we are doing some maintenance. Please check back soon.'),
        500: t('Whoops, something went wrong on our servers.'),
        429: t('Sorry, you are making too many requests to our servers.'),
        404: t('Sorry, the page you are looking for could not be found.'),
        403: t('Sorry, you are forbidden from accessing this page.'),
    }[status];

    return (
        <div className={styles.container}>
            <Head title={title} />
            <div className={styles.content}>
                <h1 className={styles.title}>{title}</h1>
                <p className={styles.description}>{description}</p>
                <a className={styles.link} href={signedIn ? dashboard().url : login().url}>
                    {signedIn ? t('Back to today') : t('Log in')}
                </a>
            </div>
        </div>
    );
}
