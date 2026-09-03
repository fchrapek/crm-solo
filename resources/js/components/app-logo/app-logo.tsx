import AppLogoIcon from '@/components/app-logo-icon';

import styles from './app-logo.module.css';

export default function AppLogo() {
    return (
        <>
            <div className={styles.logoContainer}>
                <AppLogoIcon className={styles.logoIcon} />
            </div>
            <div className={styles.textContainer}>
                <span className={styles.title}>CRM Solo</span>
            </div>
        </>
    );
}
