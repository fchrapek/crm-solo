import { router } from '@inertiajs/react';
import { ExternalLink, MoreVertical, RefreshCw } from 'lucide-react';
import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import styles from './kanban-card.module.css';

export type Priority = 'high' | 'medium' | 'low' | null | undefined;

export type StatusBadgeTone = 'info' | 'warning' | 'error' | 'success';

export interface KanbanCardChip {
    label: string;
    tone?: 'project' | 'repository' | 'label' | 'trello';
    icon?: React.ReactNode;
}

export interface KanbanCardAction {
    label: string;
    icon?: React.ReactNode;
    onClick: () => void;
    destructive?: boolean;
    href?: string;
    target?: string;
}

interface Props {
    priority?: Priority;
    title: string;
    /** When provided, the title becomes a clickable link (stops drag propagation). */
    href?: string;
    isCompleted?: boolean;

    /** Optional small subtitle/description rendered below the title (e.g. agent prompt preview). */
    subtitle?: string | null;

    /** Status badge inline with the title (e.g. run-status). */
    statusBadge?: { text: string; tone: StatusBadgeTone; spinner?: boolean };

    /** Meta row (below title): due date + recurring + labels + custom chips. */
    dueDate?: string | null;
    isOverdue?: boolean;
    recurrencePeriodDays?: number | null;
    labels?: string[];
    chips?: KanbanCardChip[];

    /**
     * Consumer-rendered badge shown inline with the title - for a classification
     * the card is sorted/triaged by (e.g. a lead's Gold/Oak/Rowan tier).
     * `statusBadge` covers the four generic tones; this is the escape hatch for
     * a domain badge that owns its own colours.
     */
    badge?: React.ReactNode;

    /** Visible primary action button (e.g. Run agent). Stops drag propagation. */
    primaryAction?: { label: string; icon?: React.ReactNode; onClick: () => void };

    /**
     * Action items rendered in the ⋮ dropdown menu. Optional - omit (or pass
     * an empty list) to render no menu at all; with `href` set the whole card
     * is already the click target, so a menu whose only item is "open" would
     * be redundant chrome.
     */
    actions?: KanbanCardAction[];
}

export function KanbanCard({
    priority,
    title,
    href,
    isCompleted,
    subtitle,
    statusBadge,
    badge,
    dueDate,
    isOverdue,
    recurrencePeriodDays,
    labels,
    chips,
    primaryAction,
    actions,
}: Props) {
    const { t } = useTranslation();

    const priorityClass = priority === 'high' ? styles.priorityDotHigh
        : priority === 'medium' ? styles.priorityDotMedium
        : priority === 'low' ? styles.priorityDotLow
        : styles.priorityDotNone;

    const stopDrag = (e: React.SyntheticEvent) => {
        e.stopPropagation();
    };
    const stopDragNative = (e: React.PointerEvent | React.MouseEvent) => {
        e.stopPropagation();
    };

    // Title link: let pointer-down propagate so dnd-kit (5px activation distance)
    // can decide click-vs-drag. On click, suppress navigation if the pointer moved
    // far enough that this was a drag, not a tap.
    const pointerDownRef = React.useRef<{ x: number; y: number } | null>(null);
    const handleTitlePointerDown = (e: React.PointerEvent) => {
        pointerDownRef.current = { x: e.clientX, y: e.clientY };
    };
    const handleTitleClick = (e: React.MouseEvent) => {
        const start = pointerDownRef.current;
        pointerDownRef.current = null;
        if (start) {
            const dx = e.clientX - start.x;
            const dy = e.clientY - start.y;
            if (Math.hypot(dx, dy) > 5) {
                e.preventDefault();

                return;
            }
        }
        e.stopPropagation();
    };

    // Whole-card click target (when href is set): the Trello/Linear pattern -
    // the card navigates, inner interactive elements stopPropagation. Same
    // pointer-distance check as the title so a drag never counts as a click.
    const cardPointerDownRef = React.useRef<{ x: number; y: number } | null>(null);
    const handleCardPointerDown = (e: React.PointerEvent) => {
        cardPointerDownRef.current = { x: e.clientX, y: e.clientY };
    };
    const handleCardClick = (e: React.MouseEvent) => {
        if (!href) return;
        const start = cardPointerDownRef.current;
        cardPointerDownRef.current = null;
        if (start && Math.hypot(e.clientX - start.x, e.clientY - start.y) > 5) {
            return;
        }
        router.visit(href);
    };
    const handleCardKeyDown = (e: React.KeyboardEvent) => {
        if (!href || e.target !== e.currentTarget) return;
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            router.visit(href);
        }
    };

    const titleEl = href ? (
        <a
            href={href}
            className={`${styles.title} ${isCompleted ? styles.titleCompleted : ''}`}
            onClick={handleTitleClick}
            onPointerDown={handleTitlePointerDown}
        >
            {title}
        </a>
    ) : (
        <span className={`${styles.title} ${isCompleted ? styles.titleCompleted : ''}`}>{title}</span>
    );

    const hasMeta = !!dueDate || recurrencePeriodDays != null || (labels && labels.length > 0) || (chips && chips.length > 0);

    return (
        <div
            className={`${styles.cardInner} ${href ? styles.cardClickable : ''}`}
            {...(href
                ? {
                      role: 'link' as const,
                      tabIndex: 0,
                      'aria-label': title,
                      onPointerDown: handleCardPointerDown,
                      onClick: handleCardClick,
                      onKeyDown: handleCardKeyDown,
                  }
                : {})}
        >
            <div className={styles.cardHeader}>
                <span className={`${styles.priorityDot} ${priorityClass}`} />
                <div className={styles.titleColumn}>
                    <div className={styles.titleRow}>
                        {badge}
                        {titleEl}
                        {statusBadge && <StatusBadge {...statusBadge} />}
                    </div>
                    {subtitle && <p className={styles.subtitle}>{subtitle}</p>}
                </div>
                <div className={styles.actions}>
                    {primaryAction && (
                        <button
                            type="button"
                            onClick={(e) => { stopDrag(e); primaryAction.onClick(); }}
                            onPointerDown={stopDragNative}
                            className={styles.primaryAction}
                            aria-label={primaryAction.label}
                            title={primaryAction.label}
                        >
                            {primaryAction.icon}
                            {primaryAction.label}
                        </button>
                    )}
                    {actions && actions.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className={styles.menuButton}
                                aria-label={t('Task actions')}
                                onPointerDown={stopDragNative}
                                onClick={stopDrag}
                            >
                                <MoreVertical size={14} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {actions.map((action, i) => {
                                if (action.href) {
                                    return (
                                        <DropdownMenuItem key={i} asChild>
                                            <a href={action.href} target={action.target ?? '_self'} rel={action.target === '_blank' ? 'noopener noreferrer' : undefined}>
                                                {action.icon && <span className={styles.dropdownIcon}>{action.icon}</span>}
                                                {action.label}
                                            </a>
                                        </DropdownMenuItem>
                                    );
                                }
                                return (
                                    <DropdownMenuItem
                                        key={i}
                                        variant={action.destructive ? 'destructive' : 'default'}
                                        onClick={action.onClick}
                                    >
                                        {action.icon && <span className={styles.dropdownIcon}>{action.icon}</span>}
                                        {action.label}
                                    </DropdownMenuItem>
                                );
                            })}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    )}
                </div>
            </div>
            {hasMeta && (
                <div className={styles.cardMeta}>
                    {dueDate && (
                        <span className={isOverdue ? styles.dueOverdue : styles.due}>
                            {new Date(dueDate).toLocaleDateString()}
                        </span>
                    )}
                    {recurrencePeriodDays != null && (
                        <span className={styles.recurringChip}>
                            <RefreshCw size={10} />
                            {t('every_n_days', { count: recurrencePeriodDays })}
                        </span>
                    )}
                    {chips?.map((chip, i) => (
                        <span key={`chip-${i}`} className={`${styles.chip} ${chipToneClass(chip.tone)}`}>
                            {chip.icon}
                            {chip.label}
                        </span>
                    ))}
                    {labels?.map((label) => (
                        <span key={label} className={`${styles.chip} ${styles.chipLabel}`}>{label}</span>
                    ))}
                </div>
            )}
        </div>
    );
}

function StatusBadge({ text, tone, spinner }: { text: string; tone: StatusBadgeTone; spinner?: boolean }) {
    const toneClass = tone === 'error' ? styles.statusError
        : tone === 'warning' ? styles.statusWarning
        : tone === 'success' ? styles.statusSuccess
        : styles.statusInfo;
    return (
        <span className={`${styles.statusBadge} ${toneClass}`}>
            {spinner && <SpinnerDot />}
            {text}
        </span>
    );
}

function SpinnerDot() {
    return <span className={styles.spinner} />;
}

function chipToneClass(tone?: KanbanCardChip['tone']): string {
    switch (tone) {
        case 'project': return styles.chipProject;
        case 'repository': return styles.chipRepo;
        case 'trello': return styles.chipTrello;
        case 'label':
        default: return styles.chipLabel;
    }
}

// Re-export ExternalLink so consumers don't need a separate import for the common
// "Open in Trello" dropdown action use case.
export { ExternalLink as KanbanCardExternalIcon };
