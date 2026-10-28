import { useTranslation } from 'react-i18next';
import styles from './actor-mark.module.css';

export type Actor = 'agent' | 'person';

/** Anything written through an agent transport carries actor_via, the owner's own crm runs over the bridge included. */
export function actorOf(via: string | null | undefined): Actor {
    return via ? 'agent' : 'person';
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

interface ActorMarkProps {
    via: string | null | undefined;
    name?: string | null;
    agent?: string;
    /** The agent square's letters when one initial is not enough, such as CX for Codex. */
    mark?: string;
}

/** Figma: Avatar xs. */
export function ActorMark({ via, name, agent = 'Claude', mark }: ActorMarkProps) {
    const { t } = useTranslation();
    const actor = actorOf(via);
    const label = actor === 'agent' ? t('{{name}} (agent)', { name: agent }) : name || t('Person');
    const text = actor === 'agent' ? (mark ?? agent.charAt(0).toUpperCase()) : initials(name ?? '');

    return (
        <span className={styles.mark} data-actor={actor} role="img" aria-label={label} title={label}>
            <span aria-hidden="true">{text}</span>
        </span>
    );
}
