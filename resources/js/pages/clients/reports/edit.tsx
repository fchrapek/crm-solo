import { MarkdownDiff } from '@/components/markdown-diff';
import { MarkdownView } from '@/components/markdown-view';
import { ActionMenu, ActionMenuItem } from '@/components/solo/action-menu';
import { Chip } from '@/components/solo/chip';
import { useInHeaderMenu } from '@/components/solo/client-header';
import { SoloField, SoloInput } from '@/components/solo/field';
import { Fold } from '@/components/solo/fold';
import { IconButton } from '@/components/solo/icon-button';
import { KvRow } from '@/components/solo/kv-row';
import { Notice } from '@/components/solo/notice';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { RecordFrame } from '@/components/solo/record-frame';
import { Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileActions, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import { capitalize, monthLocative, monthName, shortDate } from '@/lib/dates';
import { hoursText, localeOf } from '@/lib/hours-text';
import clientReports from '@/routes/client-reports';
import clients from '@/routes/clients';
import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { billedTip } from '../components/client-billing/billed-format';
import { BilledTimeDialog } from '../components/client-billing/billed-time-dialog';
import { monthUrl } from '../components/client-billing/format';
import type { BilledFigures } from '../components/client-billing/types';
import { afterBilledSave } from './billed-save';
import styles from './edit.module.css';
import { isEditing, modeUrl, StatusAction, statusChip, statusLabelKey, statusLine, type Confirm, type ReportStatus } from './status-actions';

interface ReportData {
    id: number;
    period_type: 'week' | 'month';
    period_start: string;
    period_end: string;
    contracted_hours: number | null;
    actual_hours: number;
    opening_balance_hours: number | null;
    rollover_cap_hours: number | null;
    /** Both values of the month while the client has billed time on. */
    billed: BilledFigures | null;
    /** An earlier month's figures changed under this draft: it regenerates before it can be finalized or its month closed. */
    needs_recalculation: boolean;
    currency: string | null;
    composer_key: string;
    body_markdown: string;
    version: string;
    status: ReportStatus;
    generated_at: string | null;
    finalized_at: string | null;
    sent_at: string | null;
    sent_on: string | null;
    finalized_on: string | null;
    generated_on: string | null;
}

interface Composer {
    key: string;
    label: string;
}

interface Revision {
    id: number;
    reason: 'update' | 'regenerate' | 'finalize' | 'sent' | 'reopen' | 'opening_balance' | 'billed_hours';
    status_before: ReportStatus;
    finalized_at_before: string | null;
    sent_at_before: string | null;
    composer_key_before: string | null;
    body_markdown_before: string | null;
    opening_balance_hours_before: number | null;
    rollover_cap_hours_before: number | null;
    created_at: string;
    created_on: string;
    user: { id: number; name: string } | null;
}

interface Props {
    client: { id: number; name: string; short_name: string };
    report: ReportData;
    composers: Composer[];
    revisions: Revision[];
}

const RECENT_REVISIONS = 3;

/** The mode pills change the place, not the page: the text in the editor stays as it is. */
const KEEP = { preserveState: true, preserveScroll: true, replace: true };

/**
 * Figma: Report (1092:25793, phone 1092:25989). The text for the client first, with Delivery, Hours and Activity
 * beside it; Preview and Edit are the page's pills. Every write keeps the version guard of the text it was based on.
 */
/** A change's diff, worked out the first time it is opened and kept for the closing motion. */
function RevisionDiff({ id, open, before, after }: { id: string; open: boolean; before: string; after: string }) {
    const [seen, setSeen] = useState(open);
    if (open && !seen) setSeen(true);

    return (
        <Fold id={id} open={open}>
            {seen && (
                <div className={styles.diff}>
                    <MarkdownDiff before={before} after={after} />
                </div>
            )}
        </Fold>
    );
}

export default function ReportEdit({ client, report, composers, revisions }: Props) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const editing = isEditing(usePage().url);
    // The text in the editor travels with the version it was based on: a save sends that
    // version, so the server refuses text written against a report that has since changed.
    const [draft, setDraft] = useState(() => draftFrom(report));
    const [saving, setSaving] = useState(false);
    // The editor is read-only while a balance save is in flight, because the reply replaces its text.
    const [savingBalance, setSavingBalance] = useState(false);
    const [regenerating, setRegenerating] = useState(false);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [allRevisions, setAllRevisions] = useState(false);
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    const [billedOpen, setBilledOpen] = useState(false);
    const [opening, setOpening] = useState<string>(report.opening_balance_hours != null ? String(report.opening_balance_hours) : '');

    const base = clientReports.edit([client.id, report.id]).url;
    const show = (edit: boolean) => router.get(modeUrl(base, edit), {}, KEEP);
    const isDirty = draft.body !== draft.base;
    // Another tab (or a refused write's reply) has moved the stored report past this text.
    const isStale = draft.version !== report.version;
    const hours = (value: number, signed = false) => `${hoursText(value, locale, signed)} h`;

    // A write the server took replaces the text, its version and the balance field together.
    function adopt(page: { props: unknown }) {
        if (refused(page)) {
            return false;
        }
        const fresh = (page.props as unknown as Props).report;
        setDraft(draftFrom(fresh));
        setOpening(fresh.opening_balance_hours != null ? String(fresh.opening_balance_hours) : '');
        return true;
    }

    function loadCurrent() {
        setDraft(draftFrom(report));
        setOpening(report.opening_balance_hours != null ? String(report.opening_balance_hours) : '');
    }

    // Hours bank: what the client may draw on is the balance carried in plus
    // this period's pool, capped at the agreed ceiling. Overspill is forfeited,
    // so it is shown rather than quietly dropped.
    const openingNum = opening.trim() === '' ? 0 : Number(opening);
    const uncappedNum = openingNum + (report.contracted_hours ?? 0);
    const availableNum = report.rollover_cap_hours !== null ? Math.min(uncappedNum, report.rollover_cap_hours) : uncappedNum;
    const forfeitedNum = Math.max(0, uncappedNum - availableNum);
    const closingNum = availableNum - report.actual_hours;

    function saveOpening() {
        const value = opening.trim() === '' ? null : Number(opening);
        if (value === report.opening_balance_hours || (value === null && report.opening_balance_hours === null)) {
            return;
        }
        // The server rewrites the body's billing summary from the new balance, so unsaved
        // edits travel with it and the editor takes back the body it stored.
        setSavingBalance(true);
        router.patch(
            clientReports.openingBalance([client.id, report.id]).url,
            isDirty
                ? { opening_balance_hours: value, body_markdown: draft.body, version: draft.version }
                : { opening_balance_hours: value, version: draft.version },
            { preserveScroll: true, preserveState: true, onFinish: () => setSavingBalance(false), onSuccess: (page) => void adopt(page) },
        );
    }

    function save() {
        setSaving(true);
        router.put(
            clientReports.update([client.id, report.id]).url,
            { body_markdown: draft.body, version: draft.version },
            {
                preserveScroll: true,
                // Kept so a refused save leaves the text in the editor.
                preserveState: true,
                onFinish: () => setSaving(false),
                onSuccess: (page) => {
                    if (adopt(page)) {
                        toast.success(t('Report saved.'));
                        show(false);
                    }
                },
            },
        );
    }

    function regenerate() {
        setConfirm(null);
        setRegenerating(true);
        // A hand-written report's key ('manual') has no composer behind it, so the server picks its default.
        const runnable = composers.some((c) => c.key === report.composer_key);
        router.post(clientReports.regenerate([client.id, report.id]).url, runnable ? { composer_key: report.composer_key } : {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setRegenerating(false),
            onSuccess: (page) => {
                if (adopt(page)) {
                    toast.success(t('Report regenerated.'));
                }
            },
        });
    }

    // Status changes toast through the server's flash, which also says when nothing changed or the move was refused.
    function transition(action: 'finalize' | 'markSent' | 'reopen') {
        setConfirm(null);
        router.post(clientReports[action]([client.id, report.id]).url, {}, { preserveScroll: true });
    }

    function cancelEdit() {
        setDraft((current) => ({ ...current, body: current.base }));
        show(false);
    }

    function copyMarkdown() {
        void navigator.clipboard.writeText(draft.body).then(() => toast.success(t('Copied markdown to clipboard.')));
    }

    const period = report.period_start.slice(0, 7);
    const month = report.period_type === 'month' ? monthName(period, locale) : null;
    const title = month !== null ? `${t('Report')} ${month}` : t('Week of {{date}}', { date: shortDate(report.period_start, locale) });
    const dates = `${shortDate(report.period_start, locale)} ${t('to')} ${shortDate(report.period_end, locale)}`;
    const status = statusLine(report, locale, t);
    const chip = statusChip(report, locale, t);
    const billing = monthUrl(client.id, period);
    const shown = allRevisions ? revisions : revisions.slice(0, RECENT_REVISIONS);
    const composer = composers.find((candidate) => candidate.key === report.composer_key)?.label ?? report.composer_key;
    const frozen = report.billed !== null && report.status !== 'draft';

    const menu = (
        <ReportMenu
            status={report.status}
            regenerating={regenerating}
            onEdit={() => show(true)}
            onConfirm={setConfirm}
            onCopy={copyMarkdown}
        />
    );

    return (
        <SoloSurface day="paper">
            <Head title={`${title} - ${client.name}`} />
            <main className={styles.page}>
                <div className={styles.frame}>
                    <RecordFrame
                        title={t('Report')}
                        noteKind="line"
                        note={[month !== null ? `${capitalize(month)} ${period.slice(0, 4)}` : null, status, dates].filter(Boolean).join(' · ')}
                        actions={menu}
                        main={<StatusAction status={report.status} onConfirm={setConfirm} />}
                    />
                </div>
                <TileGrid>
                    <TileStack>
                        <Tile kind="plain" className={styles.text}>
                            <TileHeader label={t('Text for the client')} end={<span className={styles.caption}>{editing ? t('report_mode_editing') : t('Preview')}</span>} />
                            {isStale && (
                                <>
                                    <Notice kind="warn" role="status">
                                        {t('This report changed after your text was loaded, so it cannot be saved over the newer version.')}
                                    </Notice>
                                    <SoloButton variant="secondary" size="control" onClick={loadCurrent}>
                                        {t('Load the current text')}
                                    </SoloButton>
                                </>
                            )}
                            {editing ? (
                                <div className={styles.editor} data-fill="">
                                    <MarkdownTextarea
                                        value={draft.body}
                                        onChange={(body) => setDraft((current) => ({ ...current, body }))}
                                        rows={24}
                                        look="solo"
                                        disabled={savingBalance}
                                    />
                                    <div className={styles.editorActions}>
                                        <SoloButton variant="quiet" size="control" onClick={cancelEdit} disabled={saving}>
                                            {t('Cancel')}
                                        </SoloButton>
                                        <SoloButton variant="primary" size="control" onClick={save} disabled={!isDirty || saving || savingBalance}>
                                            {saving ? t('Saving…') : t('Save')}
                                        </SoloButton>
                                    </div>
                                </div>
                            ) : (
                                <div className={styles.document} data-fill="">
                                    <MarkdownView>{draft.body}</MarkdownView>
                                </div>
                            )}
                            <TileNote className={styles.footnote}>
                                {report.billed !== null
                                    ? t('The CRM adds the hours summary from billed time.')
                                    : t('The CRM adds the hours summary from the time entries.')}
                            </TileNote>
                        </Tile>
                    </TileStack>

                    <TileStack as="aside" className={styles.side}>
                        <Tile kind="plain">
                            <TileHeader label={t('Delivery')} end={<Chip status={chip.status}>{chip.label}</Chip>} />
                            <dl className={styles.list}>
                                <KvRow layout="tile" label={t('Status')}>
                                    {status}
                                </KvRow>
                                <KvRow layout="tile" label={t('Period')}>
                                    {dates}
                                </KvRow>
                                <KvRow layout="tile" label={t('Composer')}>
                                    {composer}
                                </KvRow>
                            </dl>
                            {report.needs_recalculation && (
                                <>
                                    <Notice kind="warn">{t('report_tile_stale')}</Notice>
                                    <TileActions>
                                        <SoloButton variant="primary" size="control" disabled={regenerating} onClick={() => setConfirm('regenerate')}>
                                            {regenerating ? t('Regenerating…') : t('Regenerate')}
                                        </SoloButton>
                                    </TileActions>
                                </>
                            )}
                        </Tile>

                        <Tile kind="plain">
                            <TileHeader
                                label={t('Hours')}
                                end={
                                    report.billed !== null && report.status === 'draft' ? (
                                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => setBilledOpen(true)}>
                                            {t('Change')}
                                        </SoloButton>
                                    ) : frozen ? (
                                        <span className={styles.caption}>{t('read only')}</span>
                                    ) : undefined
                                }
                            />
                            <div data-fill="">
                                <SoloField label={t('Opening balance (h)')} htmlFor="report-opening">
                                    <SoloInput
                                        id="report-opening"
                                        type="number"
                                        step="0.5"
                                        inputMode="decimal"
                                        value={opening}
                                        placeholder="0"
                                        onChange={(e) => setOpening(e.target.value)}
                                        onBlur={saveOpening}
                                        disabled={savingBalance}
                                    />
                                </SoloField>
                            </div>
                            <dl className={styles.list}>
                                <KvRow layout="tile" label={t('Package')} tone={report.contracted_hours === null ? 'muted' : undefined}>
                                    {report.contracted_hours !== null ? hours(report.contracted_hours) : t('None')}
                                </KvRow>
                                <KvRow layout="tile" label={t('Available')}>
                                    {hours(availableNum)}
                                </KvRow>
                                {report.billed !== null ? (
                                    <>
                                        <KvRow layout="tile" label={t('Real time')}>
                                            {hours(report.billed.real)}
                                        </KvRow>
                                        <KvRow layout="tile" label={t('Billed time')}>
                                            <span className={styles.billedValue}>
                                                {billedTip(report.billed, locale, t) !== null && (
                                                    <InfoHint label={t('Your figure')}>{billedTip(report.billed, locale, t)}</InfoHint>
                                                )}
                                                {hours(report.actual_hours)}
                                            </span>
                                        </KvRow>
                                    </>
                                ) : (
                                    <KvRow layout="tile" label={t('Used')}>
                                        {hours(report.actual_hours)}
                                    </KvRow>
                                )}
                                <KvRow
                                    layout="tile"
                                    total
                                    label={month !== null ? t('Balance after {{month}}', { month: monthLocative(period, t) }) : t('Closing balance')}
                                    tone={closingNum < 0 ? 'over' : undefined}
                                >
                                    {hours(closingNum, true)}
                                </KvRow>
                            </dl>
                            {forfeitedNum > 0 && (
                                <TileNote>
                                    {t('{{hours}} over the {{cap}} cap', {
                                        hours: hours(forfeitedNum),
                                        cap: hours(report.rollover_cap_hours ?? 0),
                                    })}
                                </TileNote>
                            )}
                            {frozen && <Notice kind="lock">{report.status === 'sent' ? t('billed_report_locked_sent') : t('billed_report_locked_finalized')}</Notice>}
                            <SoloButtonLink variant="quiet" size="control" href={billing}>
                                {t('Hours bank in Billing')} →
                            </SoloButtonLink>
                        </Tile>

                        <Tile kind="list">
                            <TileHeader
                                label={t('Activity')}
                                count={revisions.length > 0 ? revisions.length : undefined}
                                end={
                                    revisions.length > RECENT_REVISIONS && !allRevisions ? (
                                        <span className={styles.caption}>{t('report_recent', { count: RECENT_REVISIONS })}</span>
                                    ) : undefined
                                }
                            />
                            {revisions.length === 0 ? (
                                <TileInset>
                                    <TileNote>{t('No changes yet.')}</TileNote>
                                </TileInset>
                            ) : (
                                <TileRows>
                                    {shown.map((r, index) => {
                                        const i = revisions.indexOf(r);
                                        // The body after this change is the body before the newer one, or the current body for the newest.
                                        const after = i === 0 ? (report.body_markdown ?? '') : (revisions[i - 1].body_markdown_before ?? '');
                                        const before = r.body_markdown_before ?? '';
                                        const canDiff =
                                            (r.reason === 'update' || r.reason === 'regenerate' || r.reason === 'opening_balance' || r.reason === 'billed_hours') &&
                                            before !== after;
                                        const open = expanded.has(r.id);

                                        return (
                                            <Fragment key={r.id}>
                                                <Row
                                                    rule={index > 0}
                                                    wrap
                                                    title={
                                                        <>
                                                            {t(reasonLabelKey(r.reason))}{' '}
                                                            <InfoHint label={t('Details')}>
                                                                {[r.user?.name, t('was {{status}}', { status: t(statusLabelKey(r.status_before)).toLocaleLowerCase(locale) })]
                                                                    .filter(Boolean)
                                                                    .join(' · ')}
                                                            </InfoHint>
                                                        </>
                                                    }
                                                    meta={shortDate(r.created_on, locale)}
                                                    action={
                                                        canDiff ? (
                                                            <SoloButton
                                                                variant="secondary"
                                                                size="control"
                                                                aria-expanded={open}
                                                                aria-controls={`revision-${r.id}`}
                                                                aria-label={open ? t('Hide changes') : t('Show changes')}
                                                                onClick={() => setExpanded((current) => toggled(current, r.id))}
                                                            >
                                                                {t('Changes')}
                                                            </SoloButton>
                                                        ) : undefined
                                                    }
                                                />
                                                {canDiff && (
                                                    <li className={styles.diffRow} aria-hidden={!open}>
                                                        <RevisionDiff id={`revision-${r.id}`} open={open} before={before} after={after} />
                                                    </li>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </TileRows>
                            )}
                            {revisions.length > RECENT_REVISIONS && (
                                <TileInset>
                                    <SoloButton variant="quiet" size="control" onClick={() => setAllRevisions((all) => !all)}>
                                        {allRevisions ? t('Recent only') : t('Whole history')} →
                                    </SoloButton>
                                </TileInset>
                            )}
                        </Tile>
                    </TileStack>
                </TileGrid>
            </main>

            {report.billed !== null && (
                <BilledTimeDialog
                    clientId={client.id}
                    period={period}
                    open={billedOpen}
                    onOpenChange={setBilledOpen}
                    onSaved={(page) => {
                        const fresh = (page.props as unknown as Props).report;
                        setDraft((current) => afterBilledSave(current, fresh).draft);
                    }}
                    onReopen={(locked) => {
                        setBilledOpen(false);
                        if (locked.closure_id === null) {
                            setConfirm('reopen');
                        }
                    }}
                />
            )}
            <ConfirmDialog
                open={confirm === 'regenerate'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('Regenerate the report?')}
                description={t(
                    'Your current edits to the body will be archived as a revision and replaced. The hours snapshot also refreshes from current time entries.',
                )}
                confirmLabel={t('Regenerate')}
                onConfirm={regenerate}
            />
            <ConfirmDialog
                open={confirm === 'finalize'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('Finalize the report?')}
                description={t(
                    'Finalizing marks the report as ready to send; marking it as sent is a separate step. You can still edit later - every change is tracked under Change history.',
                )}
                confirmLabel={t('Finalize')}
                onConfirm={() => transition('finalize')}
            />
            <ConfirmDialog
                open={confirm === 'sent'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('Mark the report as sent?')}
                description={t('Records today as the day the report went to the client. Reopening it as a draft clears that date.')}
                confirmLabel={t('Mark as sent')}
                onConfirm={() => transition('markSent')}
            />
            <ConfirmDialog
                open={confirm === 'reopen'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('Reopen as draft?')}
                description={t('Flips status back to draft. The current body stays as-is; the status change is logged.')}
                confirmLabel={t('Reopen')}
                onConfirm={() => transition('reopen')}
            />
            <ConfirmDialog
                open={confirm === 'delete'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('Delete this report?')}
                description={t('The report and its change history are gone for good.')}
                confirmLabel={t('Delete')}
                onConfirm={() => {
                    setConfirm(null);
                    router.delete(clientReports.destroy([client.id, report.id]).url);
                }}
                variant="destructive"
            />
        </SoloSurface>
    );
}

/** The report's "more": in the frame the forward step stands beside it as the ink button; a phone's menu holds everything else. */
function ReportMenu({
    status,
    regenerating,
    onEdit,
    onConfirm,
    onCopy,
}: {
    status: ReportStatus;
    regenerating: boolean;
    onEdit: () => void;
    onConfirm: (confirm: Confirm) => void;
    onCopy: () => void;
}) {
    const { t } = useTranslation();
    const inMenu = useInHeaderMenu();

    const items = (
        <>
            <ActionMenuItem icon={<Icon name="pencil" size={16} />} onSelect={onEdit}>
                {t('Edit text')}
            </ActionMenuItem>
            <ActionMenuItem icon={<Icon name="refresh" size={16} />} disabled={regenerating} onSelect={() => onConfirm('regenerate')}>
                {regenerating ? t('Regenerating…') : t('Regenerate')}
            </ActionMenuItem>
            <ActionMenuItem icon={<Icon name="copy" size={16} />} onSelect={onCopy}>
                {t('Copy markdown')}
            </ActionMenuItem>
            <ActionMenuItem onSelect={() => window.print()}>{t('Print')}</ActionMenuItem>
            {status !== 'draft' && (
                <ActionMenuItem icon={<Icon name="undo" size={16} />} onSelect={() => onConfirm('reopen')}>
                    {t('Reopen as draft')}
                </ActionMenuItem>
            )}
            <ActionMenuItem icon={<Icon name="trash" size={16} />} onSelect={() => onConfirm('delete')}>
                {t('Delete report')}
            </ActionMenuItem>
        </>
    );

    if (inMenu) return items;

    return (
        <>
            <StatusAction status={status} onConfirm={onConfirm} />
            <ActionMenu trigger={<IconButton icon="more" look="outline" label={t('More actions')} />}>{items}</ActionMenu>
        </>
    );
}

function toggled(set: Set<number>, id: number): Set<number> {
    const next = new Set(set);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    return next;
}

function draftFrom(report: ReportData): { body: string; base: string; version: string } {
    const body = report.body_markdown ?? '';
    return { body, base: body, version: report.version };
}

/** A redirect carrying an error flash means the server refused the write. */
function refused(page: { props: unknown }): boolean {
    return Boolean((page.props as { flash?: { error?: string | null } }).flash?.error);
}

function reasonLabelKey(reason: Revision['reason']): string {
    switch (reason) {
        case 'update':
            return 'Edited';
        case 'regenerate':
            return 'Regenerated';
        case 'finalize':
            return 'Finalized';
        case 'sent':
            return 'Marked as sent';
        case 'reopen':
            return 'Reopened';
        case 'opening_balance':
            return 'Balance changed';
        case 'billed_hours':
            return 'Billed hours changed';
    }
}

ReportEdit.place = (({ client, report }, { t, url, locale }) => {
    const period = report.period_start.slice(0, 7);
    const base = clientReports.edit([client.id, report.id]).url;
    const editing = isEditing(url);

    return {
        tokens: [
            { label: client.short_name, href: clients.show({ client: client.id, tab: 'overview' }).url },
            { label: capitalize(monthName(period, locale)), href: monthUrl(client.id, period) },
            { label: t('Report') },
        ],
        pills: [
            { label: t('Preview'), href: modeUrl(base, false), active: !editing, visit: KEEP },
            { label: t('Edit'), href: modeUrl(base, true), active: editing, visit: KEEP },
        ],
    };
}) satisfies PlaceResolver<Props>;
