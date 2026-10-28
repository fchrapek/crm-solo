import { ActorMark, actorOf } from '@/components/solo/actor-mark';
import { BottomSheet } from '@/components/solo/bottom-sheet';
import { PhoneNavBar } from '@/components/solo/client-header';
import { FloatingButton } from '@/components/solo/floating-button';
import { ListRow, ListRowStatic } from '@/components/solo/list-row';
import { BodyLink, SectionAction, SectionHead, SectionLink, SoloButtonLink } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { StagePill } from '@/components/solo/stage-pill';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { durationText, fullDate, inMonth, monthName, shortDate } from '@/lib/dates';
import { lifecycleStageLabelKey } from '@/lib/lifecycle-stage';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import projectBoard from '@/routes/project-board';
import { Link, router } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { MessageList, useEventLine, type MessageRow } from '../client-fields';
import { hoursNumber, hoursTerms, localeOf } from '../client-list/format';
import styles from './client-journal.module.css';
import { projectHours, withRelationshipScreen } from './format';
import { JournalEntryDialog } from './journal-entry-dialog';
import {
    closeRelationshipScreen,
    holdJournal,
    journalChanged,
    openRelationshipScreen,
    takeFocusReturn,
    takeHeldJournal,
} from './relationship-screen';
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

/** Figma: Klient / Dziennik; phones 333:2817. Cofnij per session, entry kinds and project domains are not built and stay hidden. */
interface ClientJournalTabProps {
    clientId: number;
    clientName: string;
    journal: ClientJournal;
    work: JournalPage<JournalWorkRow>;
    notes: JournalPage<JournalNote>;
    /** The account's text messages to this client; null when the account sends none. */
    sms?: MessageRow[] | null;
    owner: string | null;
    /** The phone's pushed Relacja z klientem screen (?relacja); from 768 px the page reads as always. */
    relationship?: boolean;
}

export function ClientJournalTab({ clientId, clientName, journal, work, notes, sms = null, owner, relationship = false }: ClientJournalTabProps) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [composing, setComposing] = useState(false);
    const compose = () => setComposing(true);
    useCarriedJournal(clientId, relationship, journal, notes);
    const page = (
        <>
            <JournalBrief journal={journal} locale={locale} />
            <PhoneJournalRows clientId={clientId} journal={journal} locale={locale} relationship={relationship} />
            <div className={`${splitClass} ${styles.body}`}>
                {sms !== null ? (
                    <div className={`${splitMainClass} ${styles.main}`}>
                        <SmsSection clientId={clientId} rows={sms} />
                        {work.rows.length > 0 && <WorkSection clientId={clientId} work={work} owner={owner} locale={locale} nested />}
                    </div>
                ) : (
                    <WorkSection clientId={clientId} work={work} owner={owner} locale={locale} />
                )}
                <div className={`${splitSideClass} ${styles.rail}`}>
                    <ProjectsSection clientId={clientId} projects={journal.projects} period={journal.period} locale={locale} />
                    <RelationshipSection clientId={clientId} journal={journal} notes={notes} owner={owner} locale={locale} onCompose={compose} />
                </div>
            </div>
        </>
    );

    return (
        <>
            {relationship ? (
                <>
                    <RelationshipScreen clientId={clientId} clientName={clientName} journal={journal} notes={notes} owner={owner} locale={locale} />
                    <div className={styles.pushedAway}>{page}</div>
                </>
            ) : (
                page
            )}
            <FloatingButton onClick={compose}>{t('New entry')}</FloatingButton>
            <JournalEntryDialog clientId={clientId} open={composing} onOpenChange={setComposing} />
        </>
    );
}

/** A new entry or older notes loaded on the Relacja screen come back to Dziennik with it, client side. */
function useCarriedJournal(clientId: number, relationship: boolean, journal: ClientJournal, notes: JournalPage<JournalNote>) {
    useEffect(() => {
        const journalUrl = clients.show({ client: clientId, tab: 'dziennik' }).url;
        if (relationship) {
            holdJournal(journalUrl, { journal, notes });
            return;
        }
        const kept = takeHeldJournal(journalUrl);
        if (kept !== null && journalChanged(kept, { journal, notes })) {
            router.replace({
                props: (props) => ({ ...props, journal: kept.journal, journal_notes: kept.notes }),
                preserveScroll: true,
                preserveState: true,
            });
        }
    }, [clientId, relationship, journal, notes]);
}

function Accent({ children }: { children: ReactNode }) {
    return <span className={styles.accent}>{children}</span>;
}

function JournalBrief({ journal, locale }: { journal: ClientJournal; locale: string }) {
    const { t } = useTranslation();
    const { rows, notes, person_minutes: minutes, field_changes: fieldChanges = 0, sms = 0 } = journal.summary;
    const since = shortDate(journal.since, locale);

    if (rows === 0 && fieldChanges === 0 && sms === 0) {
        return (
            <p className={styles.brief}>{journal.since_today ? t('Nothing new since today') : t('Nothing new since {{date}}', { date: since })}.</p>
        );
    }

    const parts = [
        rows > 0 ? <Accent key="rows">{t('{{count}} things', { count: rows })}</Accent> : null,
        notes > 0 ? <Accent key="notes">{t('{{count}} new entries', { count: notes })}</Accent> : null,
        fieldChanges > 0 ? <Accent key="fields">{t('field_change_count', { count: fieldChanges })}</Accent> : null,
        sms > 0 ? <Accent key="sms">{t('sent_sms_count', { count: sms })}</Accent> : null,
        minutes > 0 ? <span key="time">{t('your time {{time}}', { time: durationText(minutes, t) })}</span> : null,
    ].filter((part) => part !== null);

    return (
        <p className={styles.brief}>
            {journal.since_today ? t('Since today') : t('Since {{date}}', { date: since })}:{' '}
            {parts.map((part, index) => (
                <Fragment key={part.key}>
                    {index > 0 && ', '}
                    {part}
                </Fragment>
            ))}
            .
        </p>
    );
}

/** Figma: the disclosure rows. Relacja z klientem pushes its screen, Projekty opens a sheet. */
function PhoneJournalRows({
    clientId,
    journal,
    locale,
    relationship,
}: {
    clientId: number;
    journal: ClientJournal;
    locale: string;
    relationship: boolean;
}) {
    const { t } = useTranslation();
    const [projects, setProjects] = useState(false);
    const wide = useSoloWide();
    const newNotes = journal.summary.notes;
    const rows = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!relationship && takeFocusReturn(clients.show({ client: clientId, tab: 'dziennik' }).url)) {
            rows.current?.querySelector('a')?.focus({ preventScroll: true });
        }
    }, [clientId, relationship]);

    const push = (event: MouseEvent) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        openRelationshipScreen();
    };

    return (
        <div ref={rows} className={styles.phoneRows}>
            <ListRow
                href={withRelationshipScreen(clients.show({ client: clientId, tab: 'dziennik' }).url, true)}
                onClick={push}
                title={t('Client relationship')}
                value={newNotes > 0 ? <span className={styles.accent}>{t('{{count}} new entries', { count: newNotes })}</span> : undefined}
            />
            <BottomSheet
                open={projects && !wide}
                onOpenChange={setProjects}
                action="done"
                title={t('Projects · {{n}}', { n: journal.projects.length })}
                trigger={
                    <ListRow title={t('Projects')} value={<span className={styles.muted}>{journal.projects.length}</span>} aria-haspopup="dialog" />
                }
            >
                {!wide && <ProjectsSheetBody clientId={clientId} projects={journal.projects} period={journal.period} locale={locale} />}
            </BottomSheet>
        </div>
    );
}

/** "Starsze" fetches the page after the cursor for its own list only; the server marks the rows to append. */
function OlderLink({ clientId, next, part }: { clientId: number; next: string; part: 'work' | 'notes' }) {
    const { t } = useTranslation();

    return (
        <BodyLink
            href={clients.show({ client: clientId, tab: 'dziennik' }).url}
            data={part === 'work' ? { praca: next } : { wpisy: next }}
            only={[part === 'work' ? 'journal_work' : 'journal_notes']}
            preserveUrl
            preserveScroll
            preserveState
            className={part === 'work' ? styles.olderWork : styles.olderNotes}
        >
            {t('Older')} →
        </BodyLink>
    );
}

/** Figma: Klient / Dziennik · SMS (605:3689): the client's text messages, newest first. */
function SmsSection({ clientId, rows }: { clientId: number; rows: MessageRow[] }) {
    const { t } = useTranslation();

    return (
        <section>
            <SectionHead
                kind="body"
                label={`SMS · ${rows.length}`}
                action={<SectionLink href={messages.index({ query: { client: clientId } }).url}>{t('Queue')} →</SectionLink>}
            />
            {rows.length === 0 ? <p className={styles.empty}>{t('No text messages yet.')}</p> : <MessageList rows={rows} />}
        </section>
    );
}

function WorkSection({
    clientId,
    work,
    owner,
    locale,
    nested = false,
}: {
    clientId: number;
    work: JournalPage<JournalWorkRow>;
    owner: string | null;
    locale: string;
    /** Below the SMS list, inside the main column. */
    nested?: boolean;
}) {
    const { t } = useTranslation();
    const rows = work.rows;
    const oldest = rows.at(-1);

    return (
        <section className={nested ? styles.nextSection : `${splitMainClass} ${styles.main}`}>
            <SectionHead
                kind="body"
                className={styles.workHead}
                label={oldest !== undefined ? t('Work since {{date}}', { date: shortDate(oldest.date, locale) }) : t('Work')}
            />
            {rows.length === 0 ? (
                <p className={styles.empty}>{t('No time logged yet.')}</p>
            ) : (
                <>
                    <ul className={styles.workList}>
                        {rows.map((row) => (
                            <WorkRow key={row.key} row={row} owner={owner} locale={locale} />
                        ))}
                    </ul>
                    <ul className={styles.phoneList}>
                        {rows.map((row) => (
                            <PhoneWorkRow key={row.key} row={row} owner={owner} locale={locale} />
                        ))}
                    </ul>
                </>
            )}
            {work.next !== null && <OlderLink clientId={clientId} next={work.next} part="work" />}
        </section>
    );
}

function workMeta(row: JournalWorkRow, t: Translate): string {
    if (row.kind === 'session') {
        return `${AGENT} · ${t('session, {{count}} writes', { count: row.writes ?? 0 })}`;
    }

    if (row.running) {
        return t('running');
    }

    return durationText(row.minutes ?? 0, t);
}

/** Figma: Work row. Time is logged by the account owner unless an agent wrote it. */
function WorkRow({ row, owner, locale }: { row: JournalWorkRow; owner: string | null; locale: string }) {
    const { t } = useTranslation();
    const agent = actorOf(row.via) === 'agent';

    return (
        <li className={styles.workRow}>
            <ActorMark via={row.via} name={owner} agent={AGENT} />
            <div className={styles.workTitle}>
                <p className={styles.workName}>
                    {row.title ?? t('Journal entry')}
                    {row.is_new && <NewMark />}
                </p>
                {row.subtitle !== null && <p className={styles.workSub}>{row.subtitle}</p>}
            </div>
            <span className={styles.workMeta} data-actor={agent ? 'agent' : undefined}>
                {workMeta(row, t)}
            </span>
            <span className={styles.workDate}>{shortDate(row.date, locale)}</span>
        </li>
    );
}

/** Figma: Mobile / List row (Entry): the mark, the title with NOWE, then what it took and the date on one line. */
export function PhoneWorkRow({ row, owner, locale }: { row: JournalWorkRow; owner: string | null; locale: string }) {
    const { t } = useTranslation();
    const agent = actorOf(row.via) === 'agent';

    return (
        <li>
            <ListRowStatic
                leading={<ActorMark via={row.via} name={owner} agent={AGENT} />}
                title={row.title ?? t('Journal entry')}
                marks={{ isNew: row.is_new }}
                sub={
                    <>
                        <span data-actor={agent ? 'agent' : undefined} className={styles.phoneMeta}>
                            {workMeta(row, t)}
                        </span>
                        {` · ${shortDate(row.date, locale)}`}
                    </>
                }
            />
        </li>
    );
}

function NewMark() {
    const { t } = useTranslation();

    return <span className={styles.newMark}>{t('New since last visit')}</span>;
}

/** Figma: Project row. The domain line waits for a project domain field; new projects are still made on the previous client page. */
function ProjectsSection({ clientId, projects, period, locale }: { clientId: number; projects: JournalProject[]; period: string; locale: string }) {
    const { t } = useTranslation();

    return (
        <section className={styles.railSection}>
            <SectionHead
                kind="body"
                label={t('Projects · {{n}}', { n: projects.length })}
                action={<SectionLink href={newProjectUrl(clientId)}>{t('New project')}</SectionLink>}
            />
            {projects.length === 0 ? (
                <p className={styles.empty}>{t('No projects yet.')}</p>
            ) : (
                <ul className={styles.list}>
                    {projects.map((project) => (
                        <li key={project.id} className={styles.projectRow}>
                            <div className={styles.projectTitle}>
                                <Link href={projectBoard.show({ client: clientId, project: project.id }).url} className={styles.projectName}>
                                    {project.name}
                                </Link>
                                {project.trello_url !== null && (
                                    <a href={project.trello_url} target="_blank" rel="noopener noreferrer" className={styles.projectLink}>
                                        Trello ↗
                                    </a>
                                )}
                            </div>
                            <p className={styles.projectMeta}>
                                {t('{{hours}} h {{inMonth}}', { hours: projectHours(project.minutes, locale), inMonth: inMonth(period, t) })}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function newProjectUrl(clientId: number): string {
    return `${clients.edit(clientId).url}#work`;
}

/** Figma: Projekty sheet (333:3265): hours this month, Trello, Nowy projekt to where projects are made. */
export function ProjectsSheetBody({
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

    return (
        <>
            {projects.length === 0 ? (
                <p className={styles.sheetEmpty}>{t('No projects yet.')}</p>
            ) : (
                <ul className={styles.sheetList}>
                    {projects.map((project) => (
                        <li key={project.id} className={styles.sheetItem}>
                            <ListRowStatic
                                size="large"
                                title={
                                    <Link href={projectBoard.show({ client: clientId, project: project.id }).url} className={styles.sheetProject}>
                                        {project.name}
                                    </Link>
                                }
                                sub={t('{{hours}} h {{inMonth}}', { hours: projectHours(project.minutes, locale), inMonth: inMonth(period, t) })}
                                trailing={
                                    project.trello_url !== null ? (
                                        <a href={project.trello_url} target="_blank" rel="noopener noreferrer" className={styles.sheetTrello}>
                                            Trello ↗
                                        </a>
                                    ) : null
                                }
                            />
                        </li>
                    ))}
                </ul>
            )}
            <div className={styles.sheetAdd}>
                <SoloButtonLink href={newProjectUrl(clientId)} variant="outline" size="touch" block>
                    {t('New project')}
                </SoloButtonLink>
            </div>
        </>
    );
}

interface RelationshipProps {
    clientId: number;
    journal: ClientJournal;
    notes: JournalPage<JournalNote>;
    owner: string | null;
    locale: string;
}

function RelationshipSection({ onCompose, ...props }: RelationshipProps & { onCompose: () => void }) {
    const { t } = useTranslation();

    return (
        <section className={styles.railSection}>
            <SectionHead kind="body" label={t('Client relationship')} action={<SectionAction onClick={onCompose}>{t('New entry')}</SectionAction>} />
            <RelationshipBody {...props} />
        </section>
    );
}

function RelationshipBody({ clientId, journal, notes, owner, locale }: RelationshipProps) {
    const { t } = useTranslation();
    const { stage } = journal;
    const stageLine = [
        stage.since !== null ? t('since {{date}}', { date: fullDate(stage.since, locale) }) : null,
        stage.retainer_from !== null ? t('retainer since {{date}}', { date: shortDate(stage.retainer_from, locale) }) : null,
    ].filter((part) => part !== null);

    return (
        <>
            <div className={styles.relationBlock}>
                <div className={styles.stage}>
                    <StagePill stage={stage.stage} />
                    {stageLine.length > 0 && <span className={styles.stageLine}>{stageLine.join(' · ')}</span>}
                </div>
                {notes.rows.length === 0 ? (
                    <p className={styles.empty}>{t('No journal entries yet.')}</p>
                ) : (
                    <ul className={styles.list}>
                        {notes.rows.map((note) =>
                            note.kind === 'settlement' ? (
                                <SettlementEntry key={note.key} entry={note} owner={owner} locale={locale} />
                            ) : note.kind === 'field' ? (
                                <FieldEntry key={note.key} entry={note} owner={owner} locale={locale} />
                            ) : (
                                <JournalEntry key={note.key} note={note} owner={owner} locale={locale} />
                            ),
                        )}
                    </ul>
                )}
            </div>
            {notes.next !== null && <OlderLink clientId={clientId} next={notes.next} part="notes" />}
        </>
    );
}

/** Figma: Relacja z klientem (pushed screen, 333:3134), phones only: Dziennik's back link, the stage and every note. */
export function RelationshipScreen({ clientName, ...props }: RelationshipProps & { clientName: string }) {
    const { t } = useTranslation();

    const back = (event: MouseEvent) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        closeRelationshipScreen();
    };

    return (
        <section className={styles.pushed} aria-labelledby="relationship-title">
            <PhoneNavBar back={{ href: clients.show({ client: props.clientId, tab: 'dziennik' }).url, label: t('Journal'), onClick: back }} />
            <div className={styles.pushedTitle}>
                <h1 id="relationship-title" className={styles.pushedName}>
                    {t('Client relationship')}
                </h1>
                <p className={styles.pushedClient}>{clientName}</p>
            </div>
            <div className={styles.pushedBody}>
                <RelationshipBody {...props} />
            </div>
        </section>
    );
}

/** Figma: Journal entry. A stage change is the headline; a note is body text, folded when long. An entry with no author is the owner's. */
function JournalEntry({ note, owner, locale }: { note: JournalEntryNote; owner: string | null; locale: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const text = note.note ?? '';
    const folded = !open && text.length > FOLD_AT;

    return (
        <li className={styles.entry}>
            <div className={styles.entryMeta}>
                <ActorMark via={note.via} name={note.author ?? owner} agent={AGENT} />
                <span className={styles.entryDate}>{shortDate(note.date, locale)}</span>
                {note.stage_change && <span className={styles.entryKind}>{t('Stage')}</span>}
            </div>
            {note.stage_change && (
                <p className={styles.entryHeadline}>
                    {note.from_stage !== null && `${t(lifecycleStageLabelKey(note.from_stage))} → `}
                    {t(lifecycleStageLabelKey(note.to_stage))}
                </p>
            )}
            {text !== '' && (
                <p className={styles.entryText} data-folded={folded ? true : undefined}>
                    {text}
                </p>
            )}
            {folded && (
                <button type="button" className={styles.entryMore} onClick={() => setOpen(true)}>
                    {t('more')}
                </button>
            )}
        </li>
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
        source: source !== null ? t(source === 'report' ? 'journal_source_report' : 'journal_source_entries') : '',
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

function SettlementEntry({ entry, owner, locale }: { entry: JournalSettlement; owner: string | null; locale: string }) {
    const { t } = useTranslation();

    return (
        <li className={styles.entry}>
            <div className={styles.entryMeta}>
                <ActorMark via={null} name={entry.author ?? owner} agent={AGENT} />
                <span className={styles.entryDate}>{shortDate(entry.date, locale)}</span>
                <span className={styles.entryKind}>{t('Settlement')}</span>
            </div>
            <p className={styles.entryText}>{settlementText(entry, locale, t)}</p>
        </li>
    );
}

/** A field change in the timeline: "Wizja: do umówienia → umówiona". */
function FieldEntry({ entry, owner, locale }: { entry: JournalFieldChange; owner: string | null; locale: string }) {
    const { t } = useTranslation();
    const line = useEventLine();

    return (
        <li className={styles.entry}>
            <div className={styles.entryMeta}>
                <ActorMark via={null} name={entry.author ?? owner} agent={AGENT} />
                <span className={styles.entryDate}>
                    {shortDate(entry.date, locale)} · {entry.time}
                </span>
                <span className={styles.entryKind}>{t('Field')}</span>
            </div>
            <p className={styles.entryHeadline}>{line({ ...entry, id: 0, day: entry.date, user: entry.author, key: entry.field_key ?? '' })}</p>
        </li>
    );
}
