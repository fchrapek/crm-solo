import { Icon } from '@/components/ui/icon';
import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SearchHints, SearchResults, useSearch, type SearchPlace } from '../command-palette';
import { useLinkPrefetch } from '../link-prefetch';
import styles from './command-bar.module.css';
import type { PlaceFilter, PlaceToken } from './place';

/** Figma: Token. One level of the place; a level above the current one is a link to it, and a level with siblings to switch between opens its switcher. */
export function Token({ label, href, switcher }: PlaceToken) {
    const prefetch = useLinkPrefetch();

    if (switcher !== undefined) {
        return (
            <>
                {switcher(
                    <button type="button" className={styles.token} title={label}>
                        {label}
                    </button>,
                )}
            </>
        );
    }

    return href === undefined ? (
        <span className={styles.token} aria-current="page" title={label}>
            {label}
        </span>
    ) : (
        <Link href={href} prefetch={prefetch(href)} className={styles.token} title={label}>
            {label}
        </Link>
    );
}

/**
 * Figma: CommandBar. The search field and the place in one: tokens name where the page is, and the field takes
 * their place once it has the cursor (⌘K, a click, the phone search button), with the results under it and the
 * page dimmed behind. Before two letters are typed the panel holds only the keys, from 768 px.
 */
export function CommandBar({ tokens = [], filter, places }: { tokens?: PlaceToken[]; filter?: PlaceFilter; places?: SearchPlace[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const last = useRef<HTMLElement>(null);
    const onOpen = useCallback(() => input.current?.focus(), []);
    const onClose = useCallback(() => {
        setOpen(false);
        input.current?.blur();
    }, []);
    const search = useSearch({ open, onOpen, onClose, filter, places });
    const placed = tokens.length > 0 && !open;
    const current = tokens.map((token) => token.label).join(' ');
    const typed = search.query.trim().length >= 2;

    // On a phone a long place scrolls sideways and shows its end.
    useEffect(() => {
        last.current?.scrollIntoView({ block: 'nearest', inline: 'end' });
    }, [current, placed]);

    return (
        <div className={styles.wrap} data-open={open ? '' : undefined} data-placed={placed ? '' : undefined}>
            <div
                className={styles.bar}
                role="search"
                onClick={(event) => {
                    const target = event.target as Element;
                    // A switcher's panel is a portal: its clicks reach here through React, not through the bar.
                    if (event.currentTarget.contains(target) && target.closest('a, button') === null) onOpen();
                }}
            >
                <Icon name="search" size={24} className={styles.glass} />
                {placed && (
                    <nav className={styles.tokens} aria-label={t('You are here')}>
                        {tokens.map((token, index) => (
                            <span key={`${index}-${token.label}`} ref={index === tokens.length - 1 ? last : undefined} className={styles.level}>
                                <Token {...token} />
                            </span>
                        ))}
                    </nav>
                )}
                <input
                    ref={input}
                    type="search"
                    className={styles.input}
                    value={search.query}
                    placeholder={placed ? t('search further...') : t('What are you looking for?')}
                    aria-label={t('Search')}
                    aria-controls="palette-results"
                    aria-activedescendant={search.activeId}
                    autoComplete="off"
                    onChange={(event) => search.setQuery(event.target.value)}
                    onFocus={() => setOpen(true)}
                    onBlur={() => search.close()}
                    onKeyDown={(event) => {
                        // The panel and its close button go before the browser picks the next stop, or it picks one that is about to vanish.
                        if (event.key === 'Tab') setOpen(false);
                        else search.onKeyDown(event);
                    }}
                />
                {open ? (
                    <button
                        type="button"
                        className={styles.key}
                        aria-label={t('Close')}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={(event) => {
                            event.stopPropagation();
                            search.close();
                        }}
                    >
                        <span className={styles.keyText}>esc</span>
                        <Icon name="close" size={20} className={styles.keyIcon} />
                    </button>
                ) : (
                    <kbd className={styles.key} data-idle="">
                        ⌘K
                    </kbd>
                )}
            </div>
            {open && <div className={styles.scrim} aria-hidden="true" />}
            {open && (
                <div className={styles.panel} data-empty={typed ? undefined : ''} onMouseDown={(event) => event.preventDefault()}>
                    {typed ? <SearchResults search={search} /> : <SearchHints />}
                </div>
            )}
        </div>
    );
}
