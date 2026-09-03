import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { MobileAwareLink } from '@/components/mobile-aware-link';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import users from '@/routes/users';
import { type SharedData, type User } from '@/types';

import styles from './user-menu-content.module.css';

interface UserMenuContentProps {
    user: User;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const { t } = useTranslation();
    const cleanup = useMobileNavigation();
    const { auth } = usePage<SharedData>().props;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className={styles.label}>
                <div className={styles.labelInner}>
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <MobileAwareLink className={styles.menuLink} href={users.edit(auth.user.id)} as="button" prefetch onClick={cleanup}>
                        <Settings className={styles.icon} />
                        {t('My Profile')}
                    </MobileAwareLink>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link className={styles.menuLink} href={logout()} as="button" onClick={handleLogout}>
                    <LogOut className={styles.icon} />
                    {t('Logout')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
