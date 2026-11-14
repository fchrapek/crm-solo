import { ActionMenuItem } from '@/components/solo/action-menu';
import { ActorMark, actorOf } from '@/components/solo/actor-mark';
import { Chip } from '@/components/solo/chip';
import { HeaderAction } from '@/components/solo/client-header';
import { JournalEntry } from '@/components/solo/journal-entry';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { Tile, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { type EditableTimeEntry, TimeEntryEditDialog } from '@/components/time-entry-edit-dialog';
import { durationText, fullDate, inMonth, monthName, shortDate } from '@/lib/dates';
import { lifecycleStageLabelKey } from '@/lib/lifecycle-stage';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import projectBoard from '@/routes/project-board';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MessageList, type MessageRow, useEventLine } from '../client-fields';
import { hoursNumber, hoursTerms, localeOf } from '../client-list/format';
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

const FOLD_AT = 160;

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
    /** The Nowy wpis dialog, opened from the record frame. */
    composing?: boolean;
    onComposingChange?: (open: boolean) => void;
}

/**
 * Figma: Klient · Dziennik (1092:25306, phone 1092:25549). The relationship with the client as dated entries, the
 * work log with each time entry's editor, and beside them the projects with this month's hours.
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
    onComposingChange = () => undefined,
}: ClientJournalTabProps) {
    const { i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [editing, setEditing] = useState<EditedEntry | null>(null);

    return (
        <>
            <TileGrid>
                <TileStack>
                    <RelationshipTile clientId={clientId} journal={journal} notes={notes} owner={owner} locale={locale} onEdit={setEditing} />
                    {sms !== null && <SmsTile clientId={clientId} rows={sms} manage={manage} />}
                    <WorkTile clientId={clientId} work={work} owner={owner} locale={locale} tasks={journal.tasks ?? []} />
                </TileStack>
                <TileStack as="aside">
                    <ProjectsTile clientId={clientId} projects={journal.projects} period={journal.period} locale={locale} />
                </TileStack>
            </TileGrid>
            <JournalEntryDialog clientId={clientId} open={composing} onOpenChange={onComposingChange} />
            <JournalEntryDialog clientId={clientId} open={editing !== null} onOpenChange={(open) => !open && setEditing(null)} entry={editing} />
        </>
    );
}

/** The record frame's action on Dziennik. */
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
        minutes > 0 ? t('your time {{time}}', { time: durationText(minutes, t) }) : null,
    ].filter((part) => part !== null);

    return `${journal.since_today ? t('Since today') : t('Since {{date}}', { date: since })}: ${parts.join(', ')}.`;
}

/** "Starsze" fetches the page after the cursor for its own list only; the server marks the rows to append. */
function OlderRow({ clientId, next, part }: { clientId: number; next: string; part: 'work' | 'notes' }) {
    const { t } = useTranslation();
    const only = part === 'work' ? 'journal_work' : 'journal_notes';

    return (
        <TileInset>
            <SoloButtonLink
                href={clients.show({ client: clientId, tab: 'dziennik' }).url}
                data={part === 'work' ? { praca: next } : { wpisy: next }}
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

/** Figma: Tile · Relacja z klientem. The stage and since when, the sentence since the last visit, then every entry newest first. */
function RelationshipTile({ clientId, journal, notes, owner, locale, onEdit }: RelationshipProps) {
    const { t } = useTranslation();
    const { stage } = journal;
    const stageLine = [
        t(lifecycleStageLabelKey(stage.stage)),
        stage.since !== null ? t('since {{date}}', { date: fullDate(stage.since, locale) }) : null,
    ]
        .filter((part) => part !== null)
        .join(' ');
    const retainer = stage.retainer_from !== null ? t('retainer since {{date}}', { date: shortDate(stage.retainer_from, locale) }) : null;

    return (
        <Tile kind="list">
            <TileHeader
                label={t('Client relationship')}
                end={<span className={styles.stage}>{[stageLine, retainer].filter(Boolean).join(' · ')}</span>}
            />
            <TileInset className={styles.brief}>
                <TileNote>{journalBrief(journal, locale, t)}</TileNote>
            </TileInset>
            {notes.rows.length === 0 ? (
                <TileInset>
                    <TileNote>{t('No journal entries yet.')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {notes.rows.map((note, index) =>
                        note.kind === 'settlement' ? (
                            <SettlementEntry key={note.key} entry={note} owner={owner} locale={locale} rule={index > 0} />
                        ) : note.kind === 'field' ? (
                            <FieldEntry key={note.key} entry={note} owner={owner} locale={locale} rule={index > 0} />
                        ) : (
                            <NoteEntry key={note.key} note={note} owner={owner} locale={locale} rule={index > 0} onEdit={onEdit} />
                        ),
                    )}
                </TileRows>
            )}
            {notes.next !== null && <OlderRow clientId={clientId} next={notes.next} part="notes" />}
        </Tile>
    );
}

function EntryMeta({ date, via, name }: { date: string; via: string | null; name: string | null }) {
    return (
        <>
            {date}
            <ActorMark via={via} name={name} agent={AGENT} />
        </>
    );
}

/** A stage change is the headline; a note is body text, folded when long. An entry with no author is the owner's. */
function NoteEntry({
    note,
    owner,
    locale,
    rule,
    onEdit,
}: {
    note: JournalEntryNote;
    owner: string | null;
    locale: string;
    rule: boolean;
    onEdit: (entry: EditedEntry) => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const text = note.note ?? '';
    const folded = !open && text.length > FOLD_AT;
    const name = text !== '' ? text.slice(0, 40) : t('Journal entry');

    return (
        <JournalEntry
            rule={rule}
            meta={<EntryMeta date={shortDate(note.date, locale)} via={note.via} name={note.author ?? owner} />}
            kind={note.stage_change ? t('Stage') : undefined}
            more={
                <RowMenu name={name}>
                    <ActionMenuItem onSelect={() => onEdit({ id: note.id, note: text, optional: note.stage_change })}>
                        {t('Edit entry')}
                    </ActionMenuItem>
                </RowMenu>
            }
        >
            {note.stage_change && (
                <p className={styles.headline}>
                    {note.from_stage !== null && `${t(lifecycleStageLabelKey(note.from_stage))} → `}
                    {t(lifecycleStageLabelKey(note.to_stage))}
                </p>
            )}
            {text !== '' && (
                <p className={styles.text} data-folded={folded ? true : undefined}>
                    {text}
                </p>
            )}
            {folded && (
                <button type="button" className={styles.more} onClick={() => setOpen(true)}>
                    {t('more')}
                </button>
            )}
        </JournalEntry>
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
        <JournalEntry
            rule={rule}
            meta={<EntryMeta date={shortDate(entry.date, locale)} via={null} name={entry.author ?? owner} />}
            kind={t('Settlement')}
        >
            <p>{settlementText(entry, locale, t)}</p>
        </JournalEntry>
    );
}

/** A field change in the timeline: "Wizja: do umówienia → umówiona". */
function FieldEntry({ entry, owner, locale, rule }: { entry: JournalFieldChange; owner: string | null; locale: string; rule: boolean }) {
    const { t } = useTranslation();
    const line = useEventLine();

    return (
        <JournalEntry
            rule={rule}
            meta={<EntryMeta date={`${shortDate(entry.date, locale)} · ${entry.time}`} via={null} name={entry.author ?? owner} />}
            kind={t('Field')}
        >
            <p className={styles.headline}>{line({ ...entry, id: 0, day: entry.date, user: entry.author, key: entry.field_key ?? '' })}</p>
        </JournalEntry>
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

/** Who did the work and how: an agent session with its writes, a running timer, or the person who logged it. */
export function workMeta(row: JournalWorkRow, owner: string | null, locale: string, t: Translate): string {
    const agent = actorOf(row.via) === 'agent';

    return [
        shortDate(row.date, locale),
        agent ? AGENT : owner,
        row.kind === 'session' ? t('session, {{count}} writes', { count: row.writes ?? 0 }) : null,
        row.running ? t('running') : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Figma: Tile / list · Praca. One row per stretch of work; a row that is one finished time entry opens its editor. */
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
    const oldest = rows.at(-1);

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
                                time={row.minutes !== null ? durationText(row.minutes, t) : undefined}
                                wideMeta={workMeta(row, owner, locale, t)}
                                chip={row.is_new ? <Chip status="open">{t('New since last visit')}</Chip> : undefined}
                                action={
                                    entry !== null ? (
                                        <SoloButton
                                            variant="quiet"
                                            size="control"
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

/** Figma: Tile / list · Projekty. Each project with this month's hours; a row opens the project's page. */
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
                count={projects.length}
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
                            title={project.name}
                            href={projectBoard.show({ client: clientId, project: project.id })}
                            time={`${projectHours(project.minutes, locale)} h`}
                            wideMeta={inMonth(period, t)}
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
