import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import FlashMessages from '@/components/flash-messages';
import { MarkdownDiff } from '@/components/markdown-diff';
import { MarkdownView } from '@/components/markdown-view';
import { ActionMenuItem } from '@/components/solo/action-menu';
import { RecordHeader, useInHeaderMenu } from '@/components/solo/client-header';
import { IconButton } from '@/components/solo/icon-button';
import { KvRow } from '@/components/solo/kv-row';
import { Masthead } from '@/components/solo/masthead';
import { BodyLink, SectionAction, SectionHead } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import { monthLocative, monthName, shortDate } from '@/lib/dates';
import { hoursText, localeOf } from '@/lib/hours-text';
import clients from '@/routes/clients';

import styles from './edit.module.css';
import { StatusAction, statusLabelKey, statusLine, type Confirm, type ReportStatus } from './status-actions';

interface ReportData {
    id: number;
    period_type: 'week' | 'month';
    period_start: string;
    period_end: string;
    contracted_hours: number | null;
    actual_hours: number;
    opening_balance_hours: number | null;
    rollover_cap_hours: number | null;
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
    reason: 'update' | 'regenerate' | 'finalize' | 'sent' | 'reopen' | 'opening_balance';
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

/**
 * Figma: Raport klienta / Dokument (558:9974), option A: the text for the client first, Wysyłka, Godziny and Aktywność
 * beside it; phones 584:12911 with the ••• menu (585:13030). Every write keeps the version guard of the text it was based on.
 */
export default function ReportEdit({ client, report, composers, revisions }: Props) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    // The text in the editor travels with the version it was based on: a save sends that
    // version, so the server refuses text written against a report that has since changed.
    const [draft, setDraft] = useState(() => draftFrom(report));
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    // The editor is read-only while a balance save is in flight, because the reply replaces its text.
    const [savingBalance, setSavingBalance] = useState(false);
    const [regenerating, setRegenerating] = useState(false);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [allRevisions, setAllRevisions] = useState(false);
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    const [opening, setOpening] = useState<string>(report.opening_balance_hours != null ? String(report.opening_balance_hours) : '');

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
            `/clients/${client.id}/reports/${report.id}/opening-balance`,
            isDirty
                ? { opening_balance_hours: value, body_markdown: draft.body, version: draft.version }
                : { opening_balance_hours: value, version: draft.version },
            { preserveScroll: true, preserveState: true, onFinish: () => setSavingBalance(false), onSuccess: (page) => void adopt(page) },
        );
    }

    function save() {
        setSaving(true);
        router.put(
            `/clients/${client.id}/reports/${report.id}`,
            { body_markdown: draft.body, version: draft.version },
            {
                preserveScroll: true,
                // Kept so a refused save leaves the text in the editor.
                preserveState: true,
                onFinish: () => setSaving(false),
                onSuccess: (page) => {
                    if (adopt(page)) {
                        setEditing(false);
                        toast.success(t('Report saved.'));
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
        router.post(`/clients/${client.id}/reports/${report.id}/regenerate`, runnable ? { composer_key: report.composer_key } : {}, {
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
    function transition(action: 'finalize' | 'mark-sent' | 'reopen') {
        setConfirm(null);
        router.post(`/clients/${client.id}/reports/${report.id}/${action}`, {}, { preserveScroll: true });
    }

    function cancelEdit() {
        setDraft((current) => ({ ...current, body: current.base }));
        setEditing(false);
    }

    function copyMarkdown() {
        void navigator.clipboard.writeText(draft.body).then(() => toast.success(t('Copied markdown to clipboard.')));
    }

    const month = report.period_type === 'month' ? monthName(report.period_start.slice(0, 7), locale) : null;
    const title = month !== null ? `${t('Report')} ${month}` : t('Week of {{date}}', { date: shortDate(report.period_start, locale) });
    const period = `${shortDate(report.period_start, locale)} ${t('to')} ${shortDate(report.period_end, locale)}`;
    const status = statusLine(report, locale, t);
    const billing = clients.show({ client: client.id, tab: 'rozliczenia' }).url;
    const shown = allRevisions ? revisions : revisions.slice(0, RECENT_REVISIONS);

    const menu = (
        <ReportMenu status={report.status} regenerating={regenerating} onEdit={() => setEditing(true)} onConfirm={setConfirm} onCopy={copyMarkdown} />
    );

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={`${title} - ${client.name}`} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main>
                    <RecordHeader
                        size="poster"
                        back={{ href: billing, label: client.short_name }}
                        trail={[
                            { label: t('Clients'), href: clients.index().url },
                            { label: `${client.short_name} · ${t('Billing')}`, href: billing },
                        ]}
                        title={title}
                        menu={menu}
                    >
                        <div className={styles.metaRow}>
                            <p className={styles.meta}>{[status, period].join(' · ')}</p>
                            <div className={styles.headerActions}>
                                <StatusAction status={report.status} onConfirm={setConfirm} />
                                {menu}
                            </div>
                        </div>
                    </RecordHeader>

                    <div className={`${splitClass} ${styles.body}`}>
                        <section className={splitMainClass}>
                            <SectionHead
                                kind="body"
                                label={t('Text for the client')}
                                action={
                                    <span className={styles.switch} role="tablist" aria-label={t('Text for the client')}>
                                        <button
                                            type="button"
                                            role="tab"
                                            aria-selected={editing}
                                            className={styles.switchTab}
                                            onClick={() => setEditing(true)}
                                        >
                                            {t('Edit')}
                                        </button>
                                        <button
                                            type="button"
                                            role="tab"
                                            aria-selected={!editing}
                                            className={styles.switchTab}
                                            onClick={() => setEditing(false)}
                                        >
                                            {t('Preview')}
                                        </button>
                                    </span>
                                }
                            />
                            {isStale && (
                                <p className={styles.stale}>
                                    {t('This report changed after your text was loaded, so it cannot be saved over the newer version.')}{' '}
                                    <button type="button" className={styles.inlineAction} onClick={loadCurrent}>
                                        {t('Load the current text')}
                                    </button>
                                </p>
                            )}
                            <div className={styles.textBlock}>
                                {editing ? (
                                    <div className={styles.editor}>
                                        <MarkdownTextarea
                                            value={draft.body}
                                            onChange={(body) => setDraft((current) => ({ ...current, body }))}
                                            rows={24}
                                            disabled={savingBalance}
                                        />
                                        <div className={styles.editorActions}>
                                            <SectionAction onClick={cancelEdit} disabled={saving}>
                                                {t('Cancel')}
                                            </SectionAction>
                                            <button
                                                type="button"
                                                className={styles.save}
                                                onClick={save}
                                                disabled={!isDirty || saving || savingBalance}
                                            >
                                                {saving ? t('Saving…') : t('Save')}
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className={styles.document}>
                                        <MarkdownView>{draft.body}</MarkdownView>
                                    </div>
                                )}
                                <p className={styles.footnote}>{t('The CRM adds the hours summary from the time entries.')}</p>
                            </div>
                        </section>

                        <aside className={`${splitSideClass} ${styles.side}`}>
                            <section>
                                <SectionHead kind="body" label={t('Delivery')} meta={t(statusLabelKey(report.status)).toLocaleLowerCase(locale)} />
                                <dl className={styles.list}>
                                    <KvRow layout="ledger" label={t('Status')}>
                                        {status}
                                    </KvRow>
                                    <KvRow layout="ledger" label={t('Period')}>
                                        {period}
                                    </KvRow>
                                </dl>
                            </section>

                            <section>
                                <SectionHead kind="body" label={t('Hours')} meta={month ?? undefined} />
                                <dl className={styles.list}>
                                    <KvRow layout="ledger" label={t('Opening balance')}>
                                        <input
                                            type="number"
                                            step="0.5"
                                            inputMode="decimal"
                                            className={styles.balanceInput}
                                            value={opening}
                                            placeholder="0"
                                            onChange={(e) => setOpening(e.target.value)}
                                            onBlur={saveOpening}
                                            aria-label={t('Opening balance')}
                                            disabled={savingBalance}
                                        />
                                    </KvRow>
                                    <KvRow layout="ledger" label={t('Package')} tone={report.contracted_hours === null ? 'muted' : undefined}>
                                        {report.contracted_hours !== null ? hours(report.contracted_hours) : t('None')}
                                    </KvRow>
                                    <KvRow layout="ledger" label={t('Available')}>
                                        {hours(availableNum)}
                                    </KvRow>
                                    <KvRow layout="ledger" label={t('Used')}>
                                        {hours(report.actual_hours)}
                                    </KvRow>
                                    <KvRow
                                        layout="ledger"
                                        label={
                                            month !== null
                                                ? t('Balance after {{month}}', { month: monthLocative(report.period_start.slice(0, 7), t) })
                                                : t('Closing balance')
                                        }
                                        tone={closingNum < 0 ? 'accent' : undefined}
                                    >
                                        {hours(closingNum, true)}
                                    </KvRow>
                                </dl>
                                {forfeitedNum > 0 && (
                                    <p className={styles.note}>
                                        {t('{{hours}} over the {{cap}} cap', {
                                            hours: hours(forfeitedNum),
                                            cap: hours(report.rollover_cap_hours ?? 0),
                                        })}
                                    </p>
                                )}
                                <BodyLink href={billing} className={styles.more}>
                                    {t('Hours bank in Billing')} →
                                </BodyLink>
                            </section>

                            <section>
                                <SectionHead kind="body" label={t('Activity')} />
                                {revisions.length === 0 ? (
                                    <p className={styles.empty}>{t('No changes yet.')}</p>
                                ) : (
                                    <ul className={styles.revisions}>
                                        {shown.map((r) => {
                                            const i = revisions.indexOf(r);
                                            // The body after this change is the body before the newer one, or the current body for the newest.
                                            const after = i === 0 ? (report.body_markdown ?? '') : (revisions[i - 1].body_markdown_before ?? '');
                                            const before = r.body_markdown_before ?? '';
                                            const canDiff =
                                                (r.reason === 'update' || r.reason === 'regenerate' || r.reason === 'opening_balance') &&
                                                before !== after;
                                            const open = expanded.has(r.id);

                                            return (
                                                <li key={r.id} className={styles.revision}>
                                                    <p className={styles.revisionMeta}>
                                                        {shortDate(r.created_on, locale)}
                                                        {r.user && ` · ${r.user.name}`}
                                                    </p>
                                                    <p className={styles.revisionText}>
                                                        {t(reasonLabelKey(r.reason))},{' '}
                                                        {t('was {{status}}', {
                                                            status: t(statusLabelKey(r.status_before)).toLocaleLowerCase(locale),
                                                        })}
                                                    </p>
                                                    {canDiff && (
                                                        <button
                                                            type="button"
                                                            className={styles.inlineAction}
                                                            aria-expanded={open}
                                                            onClick={() => setExpanded((current) => toggled(current, r.id))}
                                                        >
                                                            {open ? t('Hide changes') : t('Show changes')}
                                                        </button>
                                                    )}
                                                    {open && canDiff && (
                                                        <div className={styles.diff}>
                                                            <MarkdownDiff before={before} after={after} />
                                                        </div>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                                {revisions.length > RECENT_REVISIONS && (
                                    <button
                                        type="button"
                                        className={`${styles.inlineAction} ${styles.more}`}
                                        onClick={() => setAllRevisions((all) => !all)}
                                    >
                                        {allRevisions ? t('Recent only') : t('Whole history')} →
                                    </button>
                                )}
                            </section>
                        </aside>
                    </div>
                </main>
            </div>

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
                onConfirm={() => transition('mark-sent')}
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
                    router.delete(`/clients/${client.id}/reports/${report.id}`);
                }}
                variant="destructive"
            />
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

/** Figma: Menu / ••• raport (206:4986): Edytuj treść, Przegeneruj, Kopiuj markdown, Drukuj, Cofnij do szkicu, Usuń raport. */
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
            {inMenu && <StatusAction status={status} onConfirm={onConfirm} />}
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
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <IconButton icon="more" size="sm" label={t('More actions')} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" sideOffset={8} collisionPadding={16}>
                {items}
            </DropdownMenuContent>
        </DropdownMenu>
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
    }
}
