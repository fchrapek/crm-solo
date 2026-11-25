import { ActionMenuItem } from '@/components/solo/action-menu';
import { ActorMark, actorOf } from '@/components/solo/actor-mark';
import { HeaderAction } from '@/components/solo/client-header';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { listDetails, type RowDetail, Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { useHelperAction } from '@/components/solo/shell/helper/actions';
import { GroupHeading, Tile, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { type EditableTimeEntry, TimeEntryEditDialog } from '@/components/time-entry-edit-dialog';
import { capitalize, fullDate, keepTogether, monthName, shortDate, statDuration } from '@/lib/dates';
import { lifecycleStageLabelKey } from '@/lib/lifecycle-stage';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import projectBoard from '@/routes/project-board';
import taskRoutes from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { Fragment, type ReactNode, useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MessageList, type MessageRow, useEventLine } from '../client-fields';
import { hoursNumber, hoursTerms, localeOf } from '../client-list/format';
import { StageDialog } from '../details-tab/details-tab';
import { ProjectFormDialog } from '../project-form-dialog';
import styles from './client-journal.module.css';
import { projectHours } from './format';
import { type EditedEntry, JournalEntryDialog } from './journal-entry-dialog';
import type {
    ClientJournal,
    JournalEntryNote,
    JournalFieldChange,
    JournalNote,
    JournalPage,
    JournalProject,
    JournalSettlement,
    JournalWorkRow,
} from './types';

type Translate = ReturnType<typeof useTranslation>['t'];

/** Agents run as Claude until a token carries its own name. */
const AGENT = 'claude';

/** A note longer than this, about what a row's one line and a phone's two hold, or of more than one line, shows its whole text in the details too. */
const TITLE_AT = 40;

const IGNORE = () => undefined;

interface ClientJournalTabProps {
    clientId: number;
    journal: ClientJournal;
    work: JournalPage<JournalWorkRow>;
    notes: JournalPage<JournalNote>;
    /** The account's text messages to this client; null when the account sends none. */
    sms?: MessageRow[] | null;
    owner: string | null;
    /** False for a member, who reads the text messages but cannot cancel or resend one. */
    manage?: boolean;
    /** The New entry dialog, opened from the record frame. */
    composing?: boolean;
    onComposingChange?: (open: boolean) => void;
    /** `relationship`: the sub-view with every entry; the tab otherwise. */
    view?: JournalView;
    /** The account shows billing, so the retainer's start belongs on the stage tile. */
    billing?: boolean;
}

export type JournalView = 'relationship' | null;

/** The column shows this many of the newest entries; the sub-view shows them all. */
export const COLUMN_ENTRIES = 5;

/** Journal › Relationship, its own address. */
export const relationHref = (clientId: number) => clients.show({ client: clientId, tab: 'journal' }, { query: { view: 'relationship' } }).url;

/**
 * Figma: Client · Journal 7A (1454:1463, phone 1454:1977). Work leads the main column and SMS follows it; beside them
 * Projects with this month's hours and Client relationship with its five newest entries and the way to all of them. A
 * phone reads Projects, Relationship, Work, SMS. The Relationship sub-view (1368:18948) lists every entry by month.
 */
export function ClientJournalTab({
    clientId,
    journal,
    work,
    notes,
    sms = null,
    owner,
    manage = true,
    composing = false,
    onComposingChange = IGNORE,
    view = null,
    billing = true,
}: ClientJournalTabProps) {
    const { i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [editing, setEditing] = useState<EditedEntry | null>(null);
    useHelperAction(
        'journal-note',
        useCallback(() => onComposingChange(true), [onComposingChange]),
    );

    return (
        <>
            {view === 'relationship' ? (
                <TileGrid>
                    <TileStack>
                        <RelationshipTile clientId={clientId} journal={journal} notes={notes} owner={owner} locale={locale} onEdit={setEditing} />
                    </TileStack>
                    <TileStack as="aside">
                        <StageTile clientId={clientId} stage={journal.stage} billing={billing} locale={locale} />
                    </TileStack>
                </TileGrid>
            ) : (
                <TileGrid>
                    <TileStack>
                        <WorkTile clientId={clientId} work={work} owner={owner} locale={locale} tasks={journal.tasks ?? []} />
                        {sms !== null && <SmsTile clientId={clientId} rows={sms} manage={manage} />}
                    </TileStack>
                    <TileStack as="aside" className={styles.side}>
                        <ProjectsTile clientId={clientId} projects={journal.projects} period={journal.period} locale={locale} />
                        <RelationshipColumn clientId={clientId} journal={journal} notes={notes} owner={owner} locale={locale} onEdit={setEditing} />
                    </TileStack>
                </TileGrid>
            )}
            <JournalEntryDialog clientId={clientId} open={composing} onOpenChange={onComposingChange} />
            <JournalEntryDialog clientId={clientId} open={editing !== null} onOpenChange={(open) => !open && setEditing(null)} entry={editing} />
        </>
    );
}

/** The record frame's action on Journal. */
export function JournalActions({ onCompose }: { onCompose: () => void }) {
    const { t } = useTranslation();

    return (
        <HeaderAction primary onClick={onCompose}>
            {t('New entry')}
        </HeaderAction>
    );
}

/** What happened since the last visit, in one sentence. */
export function journalBrief(journal: ClientJournal, locale: string, t: Translate): string {
    const { rows, notes, person_minutes: minutes, field_changes: fieldChanges = 0, sms = 0 } = journal.summary;
    const since = shortDate(journal.since, locale);
    if (rows === 0 && fieldChanges === 0 && sms === 0) {
        return `${journal.since_today ? t('Nothing new since today') : t('Nothing new since {{date}}', { date: since })}.`;
    }
    const parts = [
        rows > 0 ? t('{{count}} things', { count: rows }) : null,
        notes > 0 ? t('{{count}} new entries', { count: notes }) : null,
        fieldChanges > 0 ? t('field_change_count', { count: fieldChanges }) : null,
        sms > 0 ? t('sent_sms_count', { count: sms }) : null,
        minutes > 0 ? t('your time {{time}}', { time: keepTogether(statDuration(minutes, t)) }) : null,
    ].filter((part) => part !== null);

    return `${journal.since_today ? t('Since today') : t('Since {{date}}', { date: since })}: ${parts.join(' · ')}.`;
}

/** Older fetches the page after the cursor for its own list only; the server marks the rows to append. */
function OlderRow({ clientId, next, part, view = null }: { clientId: number; next: string; part: 'work' | 'notes'; view?: JournalView }) {
    const { t } = useTranslation();
    const only = part === 'work' ? 'journal_work' : 'journal_notes';
    const cursor = part === 'work' ? { work: next } : { notes: next };

    return (
        <TileInset>
            <SoloButtonLink
                href={clients.show({ client: clientId, tab: 'journal' }).url}
                data={view === null ? cursor : { ...cursor, view }}
                only={[only]}
                preserveUrl
                preserveScroll
                preserveState
                variant="secondary"
                size="control"
            >
                {t('Older')}
            </SoloButtonLink>
        </TileInset>
    );
}

interface RelationshipProps {
    clientId: number;
    journal: ClientJournal;
    notes: JournalPage<JournalNote>;
    owner: string | null;
    locale: string;
    onEdit: (entry: EditedEntry) => void;
}

/** "Etap: Aktywny od 12.03.2024 · abonament od 1.04". */
export function stageLine(stage: ClientJournal['stage'], locale: string, t: Translate): string {
    const current = [
        t(lifecycleStageLabelKey(stage.stage)),
        stage.since !== null ? t('since {{date}}', { date: fullDate(stage.since, locale) }) : null,
    ]
        .filter((part) => part !== null)
        .join(' ');
    const retainer = stage.retainer_from !== null ? t('retainer since {{date}}', { date: shortDate(stage.retainer_from, locale) }) : null;

    return `${t('Stage')}: ${[current, retainer].filter(Boolean).join(' · ')}`;
}

/** The stage line (unless a stage tile stands beside) and the sentence since the last visit, under a relationship tile's head. */
function RelationshipHead({ journal, locale, stage = true }: { journal: ClientJournal; locale: string; stage?: boolean }) {
    const { t } = useTranslation();

    return (
        <TileInset className={styles.brief}>
            {stage && <p className={styles.stageLine}>{stageLine(journal.stage, locale, t)}</p>}
            <TileNote>{journalBrief(journal, locale, t)}</TileNote>
        </TileInset>
    );
}

type EntryProps = { owner: string | null; locale: string; onEdit: (entry: EditedEntry) => void };

function Entry({ note, rule, ...props }: EntryProps & { note: JournalNote; rule: boolean }) {
    return note.kind === 'settlement' ? (
        <SettlementEntry entry={note} owner={props.owner} locale={props.locale} rule={rule} />
    ) : note.kind === 'field' ? (
        <FieldEntry entry={note} owner={props.owner} locale={props.locale} rule={rule} />
    ) : (
        <NoteEntry note={note} rule={rule} {...props} />
    );
}

/** Figma: Tile · Client relationship in the column (1454:1496). The stage, the sentence, the five newest entries as rows, and Whole relationship. */
function RelationshipColumn({ clientId, journal, notes, owner, locale, onEdit }: RelationshipProps) {
    const { t } = useTranslation();
    const shown = notes.rows.slice(0, COLUMN_ENTRIES);
    const total = journal.notes_total ?? notes.rows.length;

    return (
        <Tile kind="list">
            <TileHeader label={t('Client relationship')} />
            <RelationshipHead journal={journal} locale={locale} />
            {shown.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No journal entries yet.')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {shown.map((note, index) => (
                        <Entry key={note.key} note={note} rule={index > 0} owner={owner} locale={locale} onEdit={onEdit} />
                    ))}
                    <Row title={t('Whole relationship')} href={relationHref(clientId)} meta={t('{{count}} entries', { count: total })} />
                </TileRows>
            )}
        </Tile>
    );
}

/** The entries newest first, cut where the month changes. */
export function byMonth(rows: JournalNote[]): { period: string; rows: JournalNote[] }[] {
    const groups: { period: string; rows: JournalNote[] }[] = [];
    for (const row of rows) {
        const period = row.date.slice(0, 7);
        const last = groups[groups.length - 1];
        if (last?.period === period) last.rows.push(row);
        else groups.push({ period, rows: [row] });
    }

    return groups;
}

/** Figma: Journal › Relationship (1368:18948). Every entry by month, its whole text in its details, then Older. */
function RelationshipTile({ clientId, journal, notes, owner, locale, onEdit }: RelationshipProps) {
    const { t } = useTranslation();

    return (
        <Tile kind="list">
            <TileHeader label={t('All entries')} count={journal.notes_total ?? notes.rows.length} />
            <RelationshipHead journal={journal} locale={locale} stage={false} />
            {notes.rows.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No journal entries yet.')}</TileNote>
                </TileInset>
            ) : (
                byMonth(notes.rows).map((group) => (
                    <Fragment key={group.period}>
                        <GroupHeading label={`${capitalize(monthName(group.period, locale))} ${group.period.slice(0, 4)}`} />
                        <TileRows>
                            {group.rows.map((note, index) => (
                                <Entry key={note.key} note={note} rule={index > 0} owner={owner} locale={locale} onEdit={onEdit} />
                            ))}
                        </TileRows>
                    </Fragment>
                ))
            )}
            {notes.next !== null && <OlderRow clientId={clientId} next={notes.next} part="notes" view="relationship" />}
        </Tile>
    );
}

/** Figma: Tile · Relationship stage beside the sub-view: the stage, since when, the retainer's start, and Change stage. */
function StageTile({ clientId, stage, billing, locale }: { clientId: number; stage: ClientJournal['stage']; billing: boolean; locale: string }) {
    const { t } = useTranslation();
    const [changing, setChanging] = useState(false);

    return (
        <Tile kind="plain">
            <TileHeader
                label={t('Relationship stage')}
                end={
                    <SoloButton variant="secondary" size="control" onClick={() => setChanging(true)}>
                        {t('Change stage')}
                    </SoloButton>
                }
            />
            <dl className={styles.facts}>
                <KvRow layout="tile" label={t('Stage')}>
                    {t(lifecycleStageLabelKey(stage.stage))}
                </KvRow>
                {stage.since !== null && (
                    <KvRow layout="tile" label={t('Stage since')}>
                        {fullDate(stage.since, locale)}
                    </KvRow>
                )}
                {billing && stage.retainer_from !== null && (
                    <KvRow layout="tile" label={t('Retainer since')}>
                        {fullDate(stage.retainer_from, locale)}
                    </KvRow>
                )}
            </dl>
            <StageDialog clientId={clientId} current={stage.stage} open={changing} onOpenChange={setChanging} />
        </Tile>
    );
}

/** Who wrote it: the agent's mark or the person's initials, beside the name. */
function Author({ via, name }: { via: string | null; name: string | null }) {
    const shown = actorOf(via) === 'agent' ? AGENT : name;

    return (
        <span className={styles.author}>
            <ActorMark via={via} name={name} agent={AGENT} />
            <span className={styles.authorName} title={shown ?? undefined}>
                {shown}
            </span>
        </span>
    );
}

/** A relationship entry's details: its date, who wrote it and what kind it is; the row at rest is its title alone. */
export function entryDetails(date: string, author: ReactNode, kind: string, t: Translate): RowDetail[] {
    return [
        { label: t('Date'), value: date },
        { label: t('Author'), value: author },
        { label: t('Kind'), value: kind },
    ];
}

/**
 * Figma: Shared · Row details (1557:285960). A note is a row of its first line, with no page of its own, so no arrow; its
 * date, author and kind, and a long note's whole text, open under it. An entry with no author is the owner's.
 */
function NoteEntry({ note, owner, locale, rule, onEdit }: EntryProps & { note: JournalEntryNote; rule: boolean }) {
    const { t } = useTranslation();
    const text = note.note ?? '';
    const firstLine = text.split('\n')[0].trim();
    const stage = note.stage_change
        ? `${note.from_stage !== null ? `${t(lifecycleStageLabelKey(note.from_stage))} → ` : ''}${t(lifecycleStageLabelKey(note.to_stage))}`
        : null;
    const title = stage ?? (firstLine !== '' ? firstLine : t('Journal entry'));
    const more = stage !== null || text.trim() !== firstLine;
    const whole = text !== '' && (more || firstLine.length > TITLE_AT);

    return (
        <Row
            rule={rule}
            title={title}
            details={[
                ...entryDetails(shortDate(note.date, locale), <Author via={note.via} name={note.author ?? owner} />, note.stage_change ? t('Stage') : t('Note'), t),
                { label: t('Text'), value: whole ? text : null, wide: true },
            ]}
            more={
                <RowMenu name={text !== '' ? text.slice(0, 40) : t('Journal entry')}>
                    <ActionMenuItem onSelect={() => onEdit({ id: note.id, note: text, optional: note.stage_change })}>{t('Edit entry')}</ActionMenuItem>
                </RowMenu>
            }
        />
    );
}

/** "Zamknięto wrzesień 2026: 32 z 30 h, bilans −11,5 h (z raportu)": the figures the close froze, or the reopen discarded. */
export function settlementText(entry: JournalSettlement, locale: string, t: Translate): string {
    const { action, period, used, contracted, closing, source } = entry.settlement;
    const values = {
        month: `${monthName(period, locale)} ${period.slice(0, 4)}`,
        used: hoursNumber(used ?? 0, locale),
        contracted: contracted !== null ? hoursTerms(contracted, locale) : '',
        balance: hoursNumber(closing ?? 0, locale, true),
        source: source !== null ? t(`journal_source_${source}`) : '',
    };
    const close = action === 'close';
    const key =
        contracted !== null
            ? close
                ? 'Closed {{month}}: {{used}} of {{contracted}} h, balance {{balance}} h ({{source}})'
                : 'Reopened {{month}}: {{used}} of {{contracted}} h, balance {{balance}} h ({{source}})'
            : close
              ? 'Closed {{month}}: {{used}} h, balance {{balance}} h ({{source}})'
              : 'Reopened {{month}}: {{used}} h, balance {{balance}} h ({{source}})';

    return t(key, values);
}

function SettlementEntry({ entry, owner, locale, rule }: { entry: JournalSettlement; owner: string | null; locale: string; rule: boolean }) {
    const { t } = useTranslation();

    return (
        <Row
            rule={rule}
            title={settlementText(entry, locale, t)}
            details={entryDetails(shortDate(entry.date, locale), <Author via={null} name={entry.author ?? owner} />, t('Settlement'), t)}
        />
    );
}

/** A field change in the timeline: "Wizja: do umówienia → umówiona". */
function FieldEntry({ entry, owner, locale, rule }: { entry: JournalFieldChange; owner: string | null; locale: string; rule: boolean }) {
    const { t } = useTranslation();
    const line = useEventLine();

    return (
        <Row
            rule={rule}
            title={line({ ...entry, id: 0, day: entry.date, user: entry.author, key: entry.field_key ?? '' })}
            details={entryDetails(keepTogether(`${shortDate(entry.date, locale)} · ${entry.time}`), <Author via={null} name={entry.author ?? owner} />, t('Field'), t)}
        />
    );
}

/** The client's text messages, newest first, with the way to the queue. */
function SmsTile({ clientId, rows, manage }: { clientId: number; rows: MessageRow[]; manage: boolean }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list">
            <TileHeader
                label="SMS"
                count={rows.length}
                end={
                    <SoloButtonLink href={messages.index({ query: { client: clientId } }).url} variant="secondary" size="control">
                        {t('Queue')}
                    </SoloButtonLink>
                }
            />
            {rows.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No text messages yet.')}</TileNote>
                </TileInset>
            ) : (
                <MessageList rows={rows} manage={manage} />
            )}
        </Tile>
    );
}

/** A work row's details: its day, its time, whether it is new since the last visit, who did it, and an agent session with its writes or a time entry. */
export function workDetails(row: JournalWorkRow, owner: string | null, locale: string, t: Translate): RowDetail[] {
    return [
        { label: t('Date'), value: shortDate(row.date, locale), short: true },
        { label: t('Time'), value: row.minutes !== null ? keepTogether(statDuration(row.minutes, t)) : null, short: true },
        { label: t('Status'), value: row.is_new ? t('New since last visit') : null, short: true },
        { label: t('Author'), value: <Author via={row.via} name={owner} /> },
        { label: t('Kind'), value: row.kind === 'session' ? t('agent session, {{count}} writes', { count: row.writes ?? 0 }) : t('Time entry') },
    ];
}

/**
 * Figma: Tile / list · Work, Shared · Row details (1557:285892). One row per stretch of work: the title leads to its
 * task, a running timer shows its dot and time, and the chevron opens its day, time, status, author and kind; a row that
 * is one finished time entry opens its editor.
 */
function WorkTile({
    clientId,
    work,
    owner,
    locale,
    tasks,
}: {
    clientId: number;
    work: JournalPage<JournalWorkRow>;
    owner: string | null;
    locale: string;
    tasks: NonNullable<ClientJournal['tasks']>;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<EditableTimeEntry | null>(null);
    const rows = work.rows;
    const oldest = rows[rows.length - 1];
    const details = listDetails(rows.map((row) => workDetails(row, owner, locale, t)));

    return (
        <Tile kind="list">
            <TileHeader label={oldest !== undefined ? t('Work since {{date}}', { date: shortDate(oldest.date, locale) }) : t('Work')} />
            {rows.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No time logged yet.')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {rows.map((row, index) => {
                        const title = row.title ?? t('Journal entry');
                        const entry = row.entry ?? null;

                        return (
                            <Row
                                key={row.key}
                                rule={index > 0}
                                title={row.subtitle !== null ? <span title={row.subtitle}>{title}</span> : title}
                                href={row.task_id ? taskRoutes.show(row.task_id) : undefined}
                                running={row.running ? row.running_since : undefined}
                                details={details[index]}
                                action={
                                    entry !== null ? (
                                        <SoloButton
                                            variant="quiet"
                                            size="control"
                                            className={styles.rowAction}
                                            aria-label={`${t('Edit time entry')}: ${title}`}
                                            onClick={() => setEditing(entry)}
                                        >
                                            {t('Edit')}
                                        </SoloButton>
                                    ) : undefined
                                }
                            />
                        );
                    })}
                </TileRows>
            )}
            {work.next !== null && <OlderRow clientId={clientId} next={work.next} part="work" />}
            <TimeEntryEditDialog
                open={editing !== null}
                entry={editing}
                tasks={tasks}
                onOpenChange={(open) => !open && setEditing(null)}
                onSaved={() => router.reload({ only: ['journal', 'journal_work', 'client'] })}
            />
        </Tile>
    );
}

/** Figma: Tile / list · Projects (1454:1509). The month in the head, each project with its hours as end meta; a row opens the project's page. */
export function ProjectsTile({
    clientId,
    projects,
    period,
    locale,
}: {
    clientId: number;
    projects: JournalProject[];
    period: string;
    locale: string;
}) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);

    return (
        <Tile kind="list">
            <TileHeader
                label={t('Projects')}
                count={
                    <>
                        {projects.length}
                        <span className={styles.month}> · {monthName(period, locale)}</span>
                    </>
                }
                end={
                    <SoloButton variant="secondary" size="control" onClick={() => setCreating(true)}>
                        {t('New project')}
                    </SoloButton>
                }
            />
            {projects.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No projects yet.')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {projects.map((project, index) => (
                        <Row
                            key={project.id}
                            rule={index > 0}
                            wrap
                            title={project.name}
                            href={projectBoard.show({ client: clientId, project: project.id })}
                            meta={keepTogether(`${projectHours(project.minutes, locale)} h`)}
                            action={
                                project.trello_url !== null ? (
                                    <a href={project.trello_url} target="_blank" rel="noopener noreferrer" className={styles.trello}>
                                        Trello ↗
                                    </a>
                                ) : undefined
                            }
                        />
                    ))}
                </TileRows>
            )}
            <ProjectFormDialog open={creating} onOpenChange={setCreating} clientId={clientId} />
        </Tile>
    );
}
