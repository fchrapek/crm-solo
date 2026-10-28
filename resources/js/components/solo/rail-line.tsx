import type { LiHTMLAttributes } from 'react';
import styles from './rail-line.module.css';

/** Figma: Rail line, a mono line in the Dla agentów rail; tone agent is for skills. */
export function RailLine({ tone, className, ...rest }: { tone?: 'agent' } & LiHTMLAttributes<HTMLLIElement>) {
    return <li className={[styles.line, className].filter(Boolean).join(' ')} data-tone={tone} {...rest} />;
}
