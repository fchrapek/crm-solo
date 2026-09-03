import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { type User } from '@/types';

import styles from './user-info.module.css';

export function UserInfo({ user, showEmail = false }: { user: User; showEmail?: boolean }) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className={styles.avatar}>
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback className={styles.fallback}>{getInitials(user.name)}</AvatarFallback>
            </Avatar>
            <div className={styles.textContainer}>
                <span className={styles.name}>{user.name}</span>
                {showEmail && <span className={styles.email}>{user.email}</span>}
            </div>
        </>
    );
}
