import FlashMessages from '@/components/flash-messages';
import InertiaPagination from '@/components/inertia-pagination';
import { RecordHeader } from '@/components/solo/client-header';
import { Masthead } from '@/components/solo/masthead';
import { menuHeadClass, menuLabelClass, menuRowClass, menuValueClass } from '@/components/solo/menu';
import { SectionHead } from '@/components/solo/primitives';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { soloPillArrowClass, soloPillTriggerClass } from '@/components/ui/select';
import { shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MessageList, type MessageRow } from '../clients/components/client-fields';
import listStyles from '../clients/index.module.css';
import styles from './index.module.css';

type StatusFilter = 'pending' | 'sent' | 'failed' | 'not_sent';

const STATUS_FILTERS: { value: StatusFilter | null; label: string }[] = [
    { value: null, label: 'All statuses' },
    { value: 'pending', label: 'Waiting' },
    { value: 'sent', label: 'Sent messages' },
    { value: 'failed', label: 'Failed' },
    { value: 'not_sent', label: 'Not sent' },
];

interface QueueProps {
    filters: { status: StatusFilter | null; search: string; client: number | null };
    today: string;
    tomorrow: string;
    /** From today on soonest first, then earlier newest first, one paged list. */
    messages: { data: MessageRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    sending: { quiet_from: string; quiet_to: string; ascii: boolean; provider: string };
    [key: string]: unknown;
}

const SEARCH_DEBOUNCE_MS = 250;

/** Figma: SMS / Kolejka (607:5435), phones 607:5737. Today, tomorrow and later soonest first, then everything earlier. */
export default function MessagesIndex() {
    const props = usePage<SharedData & QueueProps>().props;
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [search, setSearch] = useState(props.filters.search);
    const sent = useRef(props.filters.search);

    const visit = (change: Partial<QueueProps['filters']>) => {
        const next = { ...props.filters, search, ...change };
        router.get(
            messages.index.url(),
            {
                ...(next.status ? { status: next.status } : {}),
                ...(next.search.trim() ? { search: next.search.trim() } : {}),
                ...(next.client ? { client: next.client } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (search.trim() === sent.current.trim()) return;
        const timer = window.setTimeout(() => {
            sent.current = search;
            visit({ search });
        }, SEARCH_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const rows = props.messages.data;
    const groups = [
        { key: 'today', label: `${t('Today')} · ${shortDate(props.today, locale)}`, rows: rows.filter((row) => row.day === props.today) },
        { key: 'tomorrow', label: `${t('Tomorrow')} · ${shortDate(props.tomorrow, locale)}`, rows: rows.filter((row) => row.day === props.tomorrow) },
        { key: 'later', label: t('Later'), rows: rows.filter((row) => row.day > props.tomorrow) },
        { key: 'earlier', label: t('Earlier'), rows: rows.filter((row) => row.day < props.today) },
    ].filter((group) => group.rows.length > 0);
    const status = STATUS_FILTERS.find((option) => option.value === props.filters.status) ?? STATUS_FILTERS[0];
    const simulator = props.sending.provider === 'simulator';

    return (
        <SoloSurface className={listStyles.page} day="paper">
            <Head title="SMS" />
            <div className={listStyles.frame}>
                <Masthead current="clients" />
                <main>
                    <RecordHeader
                        size="poster"
                        back={{ href: clients.index().url, label: t('Clients') }}
                        trail={[{ label: t('Clients'), href: clients.index().url }, { label: 'SMS' }]}
                        title="SMS"
                    >
                        <div className={styles.tools}>
                            <label className={styles.search}>
                                <Icon name="search" size={20} />
                                <input
                                    type="search"
                                    value={search}
                                    placeholder={t('Search client or text')}
                                    aria-label={t('Search client or text')}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                            </label>
                            <Popover>
                                <PopoverTrigger className={soloPillTriggerClass} title={t('Status')}>
                                    {t(status.label)} · {props.messages.total}
                                    <span className={soloPillArrowClass} aria-hidden="true">
                                        ↓
                                    </span>
                                </PopoverTrigger>
                                <PopoverContent look="solo-menu" align="end" sideOffset={8} aria-label={t('Status')}>
                                    <p className={menuHeadClass}>{t('Status')}</p>
                                    {STATUS_FILTERS.map((option) => (
                                        <button
                                            key={option.value ?? 'all'}
                                            type="button"
                                            className={menuRowClass}
                                            aria-pressed={option.value === props.filters.status}
                                            onClick={() => visit({ status: option.value })}
                                        >
                                            <span className={menuLabelClass}>{t(option.label)}</span>
                                            <span className={menuValueClass} />
                                        </button>
                                    ))}
                                </PopoverContent>
                            </Popover>
                        </div>
                    </RecordHeader>

                    <p className={styles.note}>
                        {simulator ? t('Simulator: nothing is sent') : t('Sending')} ·{' '}
                        {t('quiet hours {{from}} to {{to}}', { from: props.sending.quiet_from, to: props.sending.quiet_to })}{' '}
                        <InfoHint>{t('A message due in quiet hours waits for their end; past its window it is skipped, never sent late.')}</InfoHint>
                        {' · '}
                        <Link href={settings.show('sms').url}>{t('Settings')} →</Link>
                    </p>
                    {props.filters.client !== null && (
                        <p className={styles.note}>
                            <button type="button" className={styles.clear} onClick={() => visit({ client: null })}>
                                {t('One client · show all')}
                            </button>
                        </p>
                    )}

                    {groups.length === 0 && <p className={styles.empty}>{t('No text messages yet.')}</p>}
                    {groups.map((group) => (
                        <section key={group.key} className={styles.group}>
                            <SectionHead kind="body" label={group.label} meta={String(group.rows.length)} />
                            <MessageList rows={group.rows} withClient withDay={group.key === 'later' || group.key === 'earlier'} />
                        </section>
                    ))}
                    <InertiaPagination
                        look="solo"
                        links={props.messages.links}
                        preserveScroll
                        summary={t('sms_message_count', { count: props.messages.total })}
                    />
                </main>
            </div>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}
