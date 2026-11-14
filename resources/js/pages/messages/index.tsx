import InertiaPagination from '@/components/inertia-pagination';
import { Poster, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type Place, type PlaceContext, type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { GroupHeading, Tile, TileInset, TileNote } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import { shortDate } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import messages from '@/routes/messages';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { MessageList, type MessageRow } from '../clients/components/client-fields';
import styles from './index.module.css';

type StatusFilter = 'pending' | 'sent' | 'failed' | 'not_sent';

const STATUS_FILTERS: { value: StatusFilter | null; label: string }[] = [
    { value: null, label: 'All statuses' },
    { value: 'pending', label: 'Waiting' },
    { value: 'sent', label: 'Sent messages' },
    { value: 'failed', label: 'Failed' },
    { value: 'not_sent', label: 'Not sent' },
];

interface QueueFilters {
    status: StatusFilter | null;
    search: string;
    client: number | null;
}

interface QueueProps {
    filters: QueueFilters;
    today: string;
    tomorrow: string;
    /** From today on soonest first, then earlier newest first, one paged list. */
    messages: { data: MessageRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    sending: { quiet_from: string; quiet_to: string; ascii: boolean; provider: string };
    [key: string]: unknown;
}

const queueUrl = ({ status, search, client }: QueueFilters) =>
    messages.index({ query: { ...(status ? { status } : {}), ...(search.trim() ? { search: search.trim() } : {}), ...(client ? { client } : {}) } })
        .url;

/** The queue is reached from Klienci: its token is SMS, the statuses are its pills and the command bar narrows it by client or text. */
export function queuePlace({ filters, messages: list }: Pick<QueueProps, 'filters' | 'messages'>, { t }: PlaceContext): Place {
    return {
        tokens: [{ label: 'SMS' }],
        pills: STATUS_FILTERS.map((option) => ({
            label: t(option.label),
            href: queueUrl({ ...filters, status: option.value }),
            active: option.value === filters.status,
            count: option.value === filters.status ? list.total : undefined,
        })),
        filter: { label: t('Search client or text'), href: (query) => queueUrl({ ...filters, search: query }) },
    };
}

/** Figma: SMS · kolejka (1094:43607, phone 1094:43868). Today, tomorrow and later soonest first, then everything earlier. */
export default function MessagesIndex() {
    const props = usePage<SharedData & QueueProps>().props;
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { filters } = props;
    const manage = Boolean(props.auth.user?.owner);

    const rows = props.messages.data;
    const groups = [
        { key: 'today', label: `${t('Today')} · ${shortDate(props.today, locale)}`, rows: rows.filter((row) => row.day === props.today) },
        { key: 'tomorrow', label: `${t('Tomorrow')} · ${shortDate(props.tomorrow, locale)}`, rows: rows.filter((row) => row.day === props.tomorrow) },
        { key: 'later', label: t('Later'), rows: rows.filter((row) => row.day > props.tomorrow) },
        { key: 'earlier', label: t('Earlier'), rows: rows.filter((row) => row.day < props.today) },
    ].filter((group) => group.rows.length > 0);
    const simulator = props.sending.provider === 'simulator';
    const narrowed = filters.status !== null || filters.search !== '' || filters.client !== null;

    return (
        <SoloSurface day="paper">
            <Head title="SMS" />
            <main className={styles.page}>
                <header className={styles.head}>
                    <Poster>SMS</Poster>
                    <SoloButtonLink href={settings.show('sms').url} variant="secondary" size="control">
                        {t('SMS settings')}
                    </SoloButtonLink>
                </header>
                <p className={styles.note}>
                    {simulator ? t('Simulator: nothing is sent') : t('Sending')} ·{' '}
                    {t('quiet hours {{from}} to {{to}}', { from: props.sending.quiet_from, to: props.sending.quiet_to })}{' '}
                    <InfoHint>{t('A message due in quiet hours waits for their end; past its window it is skipped, never sent late.')}</InfoHint>
                </p>
                {filters.search !== '' && (
                    <Tile kind="list" as="div">
                        <Row
                            as="div"
                            rule={false}
                            title={t('Results for "{{query}}"', { query: filters.search })}
                            meta={props.messages.total}
                            action={
                                <SoloButtonLink href={queueUrl({ ...filters, search: '' })} variant="secondary" size="control">
                                    {t('Clear')}
                                </SoloButtonLink>
                            }
                        />
                    </Tile>
                )}
                {filters.client !== null && (
                    <Tile kind="list" as="div">
                        <Row
                            as="div"
                            rule={false}
                            title={t('Messages of one client')}
                            action={
                                <SoloButtonLink href={queueUrl({ ...filters, client: null })} variant="secondary" size="control">
                                    {t('Show all')}
                                </SoloButtonLink>
                            }
                        />
                    </Tile>
                )}
                {groups.length === 0 && (
                    <Tile kind="list" as="div">
                        <TileInset>
                            <TileNote>{narrowed ? t('No text messages match.') : t('No text messages yet.')}</TileNote>
                        </TileInset>
                    </Tile>
                )}
                {groups.map((group) => (
                    <Tile key={group.key} kind="list">
                        <GroupHeading label={group.label} note={group.rows.length} />
                        <MessageList rows={group.rows} withClient withDay={group.key === 'later' || group.key === 'earlier'} manage={manage} />
                    </Tile>
                ))}
                <InertiaPagination
                    look="tile"
                    links={props.messages.links}
                    preserveScroll
                    summary={t('sms_message_count', { count: props.messages.total })}
                />
            </main>
        </SoloSurface>
    );
}

MessagesIndex.place = queuePlace satisfies PlaceResolver<QueueProps>;
