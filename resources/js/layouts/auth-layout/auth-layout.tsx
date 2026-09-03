import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import { usePageContext } from '@/contexts/page-context';
import { dashboard } from '@/routes';

import styles from './auth-layout.module.css';

export default function AuthLayout({ children }: PropsWithChildren) {
    const { authTitle, authDescription } = usePageContext();

    return (
        <div className={styles.container}>
            <div className={styles.wrapper}>
                <div className={styles.inner}>
                    <div className={styles.header}>
                        <Link href={dashboard()} className={styles.logoLink}>
                            <div className={styles.logoWrapper}>
                                <AppLogoIcon className={styles.logo} />
                            </div>
                            <span className={styles.srOnly}>{authTitle}</span>
                        </Link>

                        <div className={styles.titleWrapper}>
                            <h1 className={styles.title}>{authTitle}</h1>
                            <p className={styles.description}>{authDescription}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
