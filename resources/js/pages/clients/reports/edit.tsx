import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ChevronDown, ChevronRight, Copy, History, RefreshCw, RotateCcw, Save, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { MarkdownDiff } from '@/components/markdown-diff';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import { useFormatters } from '@/lib/format';

import styles from './edit.module.css';

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
    status: 'draft' | 'finalized' | 'sent';
    generated_at: string | null;
    finalized_at: string | null;
}

interface Composer {
    key: string;
    label: string;
}

interface Revision {
    id: number;
    reason: 'update' | 'regenerate' | 'finalize' | 'reopen' | 'opening_balance';
    status_before: 'draft' | 'finalized' | 'sent';
    composer_key_before: string | null;
    body_markdown_before: string | null;
    opening_balance_hours_before: number | null;
    rollover_cap_hours_before: number | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface Props {
    client: { id: number; name: string };
    report: ReportData;
    composers: Composer[];
    revisions: Revision[];
}

export default function ReportEdit({ client, report, composers, revisions }: Props) {
    const { t } = useTranslation();
    const f = useFormatters();
    // The text in the editor travels with the version it was based on: a save sends that
    // version, so the server refuses text written against a report that has since changed.
    const [draft, setDraft] = useState(() => draftFrom(report));
    const body = draft.body;
    const setBody = (next: string) => setDraft((current) => ({ ...current, body: next }));
    const [saving, setSaving] = useState(false);
    // The editor is read-only while a balance save is in flight, because the reply replaces its text.
    const [savingBalance, setSavingBalance] = useState(false);
    const [regenerating, setRegenerating] = useState(false);
    const [confirmRegen, setConfirmRegen] = useState(false);
    const [confirmFinalize, setConfirmFinalize] = useState(false);
    const [confirmReopen, setConfirmReopen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [showRevisions, setShowRevisions] = useState(false);
    const [expandedRevisions, setExpandedRevisions] = useState<Set<number>>(new Set());
    const [opening, setOpening] = useState<string>(report.opening_balance_hours != null ? String(report.opening_balance_hours) : '');

    const isDraft = report.status === 'draft';
    const isDirty = draft.body !== draft.base;
    // Another tab (or a refused write's reply) has moved the stored report past this text.
    const isStale = draft.version !== report.version;

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
    const fmtCap = (n: number | null) => (n === null ? t('None') : f.hours(n));
    const fmtBalance = (n: number) => `${n > 0 ? '+' : n < 0 ? '−' : ''}${f.hours(Math.abs(n))}`;

    function saveOpening() {
        const value = opening.trim() === '' ? null : Number(opening);
        if (value === report.opening_balance_hours || (value === null && report.opening_balance_hours === null)) {
            return;
        }
        // The server rewrites the body's billing summary from the new balance, so unsaved
        // edits travel with it and the editor takes back the body it stored. The version
        // makes the server refuse text written against a report that has since changed.
        setSavingBalance(true);
        router.patch(
            `/clients/${client.id}/reports/${report.id}/opening-balance`,
            isDirty
                ? { opening_balance_hours: value, body_markdown: draft.body, version: draft.version }
                : { opening_balance_hours: value, version: draft.version },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSavingBalance(false),
                onSuccess: (page) => {
                    adopt(page);
                },
            },
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
                        toast.success(t('Report saved.'));
                    }
                },
            },
        );
    }

    function regenerate() {
        setConfirmRegen(false);
        setRegenerating(true);
        // A hand-written report's key ('manual') has no composer behind it.
        // Send nothing and let the server pick its default rather than asking
        // for a generator that cannot run.
        const runnable = composers.some((c) => c.key === report.composer_key);
        router.post(
            `/clients/${client.id}/reports/${report.id}/regenerate`,
            runnable ? { composer_key: report.composer_key } : {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setRegenerating(false),
                onSuccess: (page) => {
                    if (adopt(page)) {
                        toast.success(t('Report regenerated.'));
                    }
                },
            },
        );
    }

    function finalize() {
        setConfirmFinalize(false);
        router.post(
            `/clients/${client.id}/reports/${report.id}/finalize`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(t('Report finalized.')),
            },
        );
    }

    function reopen() {
        setConfirmReopen(false);
        router.post(
            `/clients/${client.id}/reports/${report.id}/reopen`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(t('Report reopened as draft.')),
            },
        );
    }

    function deleteReport() {
        setConfirmDelete(false);
        router.delete(`/clients/${client.id}/reports/${report.id}`);
    }

    function copyMarkdown() {
        void navigator.clipboard.writeText(body).then(() => toast.success(t('Copied markdown to clipboard.')));
    }

    const periodTitle = formatPeriodTitle(report.period_type, report.period_start, report.period_end, t);

    return (
        <div className={styles.page}>
            <Head title={`${periodTitle} - ${client.name}`} />

            <Link href={`/clients/${client.id}/edit#reports`} className={styles.backLink}>
                <ArrowLeft size={14} /> {client.name}
            </Link>

            <div className={styles.header}>
                <div className={styles.headerLeft}>
                    <h1 className={styles.title}>{periodTitle}</h1>
                    <span className={styles.statusChip} data-status={report.status}>
                        {t(statusLabelKey(report.status))}
                    </span>
                </div>
                <div className={styles.headerActions}>
                    <Button onClick={save} disabled={!isDirty || saving || savingBalance}>
                        <Save size={14} /> {saving ? t('Saving…') : t('Save')}
                    </Button>
                    <Button variant="outline" onClick={() => setConfirmRegen(true)} disabled={regenerating}>
                        <RefreshCw size={14} /> {regenerating ? t('Regenerating…') : t('Regenerate')}
                    </Button>
                    {isDraft ? (
                        <Button variant="outline" onClick={() => setConfirmFinalize(true)}>
                            <CheckCircle2 size={14} /> {t('Finalize')}
                        </Button>
                    ) : (
                        <Button variant="outline" onClick={() => setConfirmReopen(true)}>
                            <RotateCcw size={14} /> {t('Reopen as draft')}
                        </Button>
                    )}
                    <Button variant="outline" onClick={copyMarkdown}>
                        <Copy size={14} /> {t('Copy')}
                    </Button>
                    <Button variant="outline" onClick={() => window.print()}>
                        {t('Print')}
                    </Button>
                </div>
            </div>

            <div className={styles.summary}>
                <div className={styles.summaryItem}>
                    <span className={styles.summaryLabel}>{t('Opening balance')}</span>
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
                    />
                </div>
                <div className={styles.summaryItem}>
                    <span className={styles.summaryLabel}>{t('Contracted')}</span>
                    {report.contracted_hours !== null ? (
                        <span className={styles.summaryValue}>{f.hours(report.contracted_hours)}</span>
                    ) : (
                        <span className={styles.summaryValueMuted}> - </span>
                    )}
                </div>
                <div className={styles.summaryItem}>
                    <span className={styles.summaryLabel}>{t('Available')}</span>
                    <span className={styles.summaryValue}>{f.hours(availableNum)}</span>
                    {forfeitedNum > 0 && (
                        <span className={styles.summaryValueMuted}>
                            {t('{{hours}} over the {{cap}} cap', { hours: f.hours(forfeitedNum), cap: f.hours(report.rollover_cap_hours ?? 0) })}
                        </span>
                    )}
                </div>
                <div className={styles.summaryItem}>
                    <span className={styles.summaryLabel}>{t('Hours logged')}</span>
                    <span className={styles.summaryValue}>{f.hours(report.actual_hours)}</span>
                </div>
                <div className={styles.summaryItem}>
                    <span className={styles.summaryLabel}>{t('Closing balance')}</span>
                    <span className={closingNum < 0 ? styles.summaryValueNegative : styles.summaryValue}>{fmtBalance(closingNum)}</span>
                </div>
            </div>

            <div className={styles.editor}>
                <span className={styles.editorLabel}>{t('Report body (markdown)')}</span>
                {isStale && (
                    <Alert variant="destructive">
                        <AlertDescription className={styles.staleNotice}>
                            {t('This report changed after your text was loaded, so it cannot be saved over the newer version.')}
                            <Button variant="outline" size="sm" onClick={loadCurrent}>
                                {t('Load the current text')}
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                <MarkdownTextarea value={body} onChange={setBody} rows={24} disabled={savingBalance} />
            </div>

            <div className={styles.dangerZone}>
                <Button variant="ghost" onClick={() => setConfirmDelete(true)}>
                    <Trash2 size={14} /> {t('Delete report')}
                </Button>
            </div>

            {revisions.length > 0 && (
                <div className={styles.revisions}>
                    <button
                        type="button"
                        className={styles.revisionsToggle}
                        onClick={() => setShowRevisions((v) => !v)}
                        aria-expanded={showRevisions}
                    >
                        <History size={14} />
                        {t('Change history')} ({revisions.length})
                    </button>
                    {showRevisions && (
                        <ul className={styles.revisionsList}>
                            {revisions.map((r, i) => {
                                // The body AFTER this revision's edit = the body BEFORE the
                                // newer revision (i-1, since list is desc-by-time), or the
                                // current report body if this is the newest revision (i === 0).
                                const after = i === 0 ? (report.body_markdown ?? '') : (revisions[i - 1].body_markdown_before ?? '');
                                const before = r.body_markdown_before ?? '';
                                const expanded = expandedRevisions.has(r.id);
                                const canDiff = (r.reason === 'update' || r.reason === 'regenerate' || r.reason === 'opening_balance') && before !== after;
                                // Same pairing for the figures: what this change replaced, and what it left.
                                const newer = i === 0 ? null : revisions[i - 1];
                                const openingAfter = newer ? newer.opening_balance_hours_before : report.opening_balance_hours;
                                const capAfter = newer ? newer.rollover_cap_hours_before : report.rollover_cap_hours;
                                const showsFigures = r.reason === 'opening_balance' || r.reason === 'regenerate';
                                const openingChanged = showsFigures && (r.opening_balance_hours_before ?? 0) !== (openingAfter ?? 0);
                                const capChanged = showsFigures && r.rollover_cap_hours_before !== capAfter;
                                return (
                                    <li key={r.id} className={styles.revisionRow}>
                                        <button
                                            type="button"
                                            className={styles.revisionHeader}
                                            onClick={() => canDiff && toggleRevision(r.id, expandedRevisions, setExpandedRevisions)}
                                            aria-expanded={expanded}
                                            data-clickable={canDiff}
                                        >
                                            <span className={styles.revisionChevron}>
                                                {canDiff ? (
                                                    expanded ? (
                                                        <ChevronDown size={12} />
                                                    ) : (
                                                        <ChevronRight size={12} />
                                                    )
                                                ) : (
                                                    <span style={{ width: 12 }} />
                                                )}
                                            </span>
                                            <span className={styles.revisionReason} data-reason={r.reason}>
                                                {t(reasonLabelKey(r.reason))}
                                            </span>
                                            <span className={styles.revisionMeta}>
                                                {new Date(r.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                                {r.user && <> · {r.user.name}</>}
                                                {' · '}
                                                {t('was {{status}}', { status: t(statusLabelKey(r.status_before)) })}
                                            </span>
                                            {(openingChanged || capChanged) && (
                                                <span className={styles.revisionFigures}>
                                                    {openingChanged &&
                                                        `${t('Opening balance')}: ${fmtBalance(r.opening_balance_hours_before ?? 0)} → ${fmtBalance(openingAfter ?? 0)}`}
                                                    {openingChanged && capChanged && ' · '}
                                                    {capChanged &&
                                                        `${t('Carry-over cap')}: ${fmtCap(r.rollover_cap_hours_before)} → ${fmtCap(capAfter)}`}
                                                </span>
                                            )}
                                        </button>
                                        {expanded && canDiff && (
                                            <div className={styles.revisionDiff}>
                                                <MarkdownDiff before={before} after={after} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={confirmRegen}
                onOpenChange={setConfirmRegen}
                title={t('Regenerate the report?')}
                description={t(
                    'Your current edits to the body will be archived as a revision and replaced. The hours snapshot also refreshes from current time entries.',
                )}
                confirmLabel={t('Regenerate')}
                onConfirm={regenerate}
            />
            <ConfirmDialog
                open={confirmFinalize}
                onOpenChange={setConfirmFinalize}
                title={t('Finalize the report?')}
                description={t(
                    'Finalizing marks the report as the version you delivered. You can still edit later - every change is tracked under Change history.',
                )}
                confirmLabel={t('Finalize')}
                onConfirm={finalize}
            />
            <ConfirmDialog
                open={confirmReopen}
                onOpenChange={setConfirmReopen}
                title={t('Reopen as draft?')}
                description={t('Flips status back to draft. The current body stays as-is; the status change is logged.')}
                confirmLabel={t('Reopen')}
                onConfirm={reopen}
            />
            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('Delete this report?')}
                description={t('The report and its change history are gone for good.')}
                confirmLabel={t('Delete')}
                onConfirm={deleteReport}
                variant="destructive"
            />
        </div>
    );
}

function toggleRevision(id: number, set: Set<number>, setSet: (s: Set<number>) => void): void {
    const next = new Set(set);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    setSet(next);
}

function statusLabelKey(status: ReportData['status']): string {
    switch (status) {
        case 'draft':
            return 'Draft';
        case 'finalized':
            return 'Finalized';
        case 'sent':
            return 'Sent';
    }
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
        case 'reopen':
            return 'Reopened';
        case 'opening_balance':
            return 'Balance changed';
    }
}

function formatPeriodTitle(type: 'week' | 'month', start: string, end: string, t: (key: string, opts?: Record<string, unknown>) => string): string {
    if (type === 'month') {
        const date = new Date(start + 'T00:00:00');
        return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }
    return t('Week of {{date}}', { date: start }) + ` → ${end}`;
}
