import { Link, router } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Markdown from 'react-markdown';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { MarkdownTextarea } from '@/components/ui/markdown-textarea';
import { SectionCard } from '@/components/ui/section-card';
import { useFormatters } from '@/lib/format';

import { type ComposerOption, GenerateReportDialog } from '../generate-report-dialog';
import styles from './reports-tab.module.css';

export interface ReportSummary {
    id: number;
    period_type: 'week' | 'month';
    period_start: string;
    period_end: string;
    contracted_hours: number | null;
    actual_hours: number;
    status: 'draft' | 'finalized' | 'sent';
    generated_at: string | null;
    finalized_at: string | null;
}

interface Props {
    clientId: number;
    reports: ReportSummary[];
    composers: ComposerOption[];
    reportBaselineMarkdown: string | null;
}

/**
 * Reports list wide, the baseline scope in the rail. The baseline is the
 * verbatim "Within retainer" section of every report - read at a glance here,
 * edited in a dialog. The offer left this tab entirely: it lives as a PDF
 * document under Dane, and report composers read package terms from the
 * retainer fields, never from a markdown blob.
 */
export function ReportsTab({ clientId, reports, composers, reportBaselineMarkdown }: Props) {
    const { t } = useTranslation();
    const f = useFormatters();
    const [dialogOpen, setDialogOpen] = useState(false);
    const [baselineOpen, setBaselineOpen] = useState(false);
    const hasBaseline = (reportBaselineMarkdown ?? '').trim() !== '';

    return (
        <div className="recordColumns">
            <div className="recordMain">
                <SectionCard
                    title={t('Reports')}
                    actions={
                        <Button onClick={() => setDialogOpen(true)}>
                            <Plus size={14} /> {t('Generate report')}
                        </Button>
                    }
                >
                    {reports.length === 0 ? (
                        <div className={styles.empty}>{t('No reports yet. Generate a draft to see what was done in a period.')}</div>
                    ) : (
                        <div className={styles.list}>
                            {reports.map((report) => (
                                <Link key={report.id} href={`/clients/${clientId}/reports/${report.id}`} className={styles.row}>
                                    <div className={styles.rowMain}>
                                        <span className={styles.rowTitle}>{formatPeriod(report, t)}</span>
                                        <span className={styles.rowMeta}>{formatGeneratedAt(report, t)}</span>
                                    </div>
                                    <span className={styles.hours}>
                                        {report.contracted_hours === null
                                            ? f.hours(report.actual_hours)
                                            : `${f.hours(report.actual_hours)} / ${f.hours(report.contracted_hours)}`}
                                    </span>
                                    <span className={styles.statusChip} data-status={report.status}>
                                        {t(statusLabelKey(report.status))}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}
                </SectionCard>
            </div>

            <aside className="recordRail">
                <SectionCard
                    title={t('Baseline scope')}
                    actions={
                        <Button type="button" variant="ghost" size="sm" onClick={() => setBaselineOpen(true)}>
                            <Pencil size={14} /> {hasBaseline ? t('Edit') : t('Add baseline')}
                        </Button>
                    }
                >
                    {hasBaseline ? (
                        <div className={styles.baselinePreview}>
                            <Markdown>{reportBaselineMarkdown ?? ''}</Markdown>
                        </div>
                    ) : (
                        <p className={styles.baselineEmpty}>
                            {t('No baseline scope. Add one to inject standard monthly coverage into every report.')}
                        </p>
                    )}
                </SectionCard>
            </aside>

            <GenerateReportDialog open={dialogOpen} onOpenChange={setDialogOpen} clientId={clientId} composers={composers} />
            <BaselineDialog open={baselineOpen} onOpenChange={setBaselineOpen} clientId={clientId} value={reportBaselineMarkdown} />
        </div>
    );
}

function BaselineDialog({
    open,
    onOpenChange,
    clientId,
    value,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clientId: number;
    value: string | null;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState(value ?? '');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) setDraft(value ?? '');
    }, [open, value]);

    const save = () => {
        setSaving(true);
        router.put(
            `/clients/${clientId}/report-baseline`,
            { report_baseline_markdown: draft.trim() === '' ? null : draft },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    onOpenChange(false);
                    toast.success(t('Baseline scope saved.'));
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={styles.baselineDialog}>
                <DialogHeader>
                    <DialogTitle>{t('Baseline scope (always in report)')}</DialogTitle>
                </DialogHeader>
                <MarkdownTextarea
                    value={draft}
                    onChange={setDraft}
                    rows={14}
                    placeholder={t("What the retainer always covers, regardless of the cycle's specific work. Listed in every report without hours.")}
                    disabled={saving}
                />
                <div className={styles.dialogActions}>
                    <Button type="button" variant="ghost" onClick={() => onOpenChange(false)} disabled={saving}>
                        {t('Cancel')}
                    </Button>
                    <Button type="button" onClick={save} disabled={saving}>
                        {saving ? t('Saving…') : t('Save')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function statusLabelKey(status: ReportSummary['status']): string {
    switch (status) {
        case 'draft':
            return 'Draft';
        case 'finalized':
            return 'Finalized';
        case 'sent':
            return 'Sent';
    }
}

function formatPeriod(report: ReportSummary, t: (k: string, o?: Record<string, unknown>) => string): string {
    if (report.period_type === 'month') {
        const d = new Date(report.period_start + 'T00:00:00');
        return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    }
    return t('Week of {{date}}', { date: report.period_start });
}

function formatGeneratedAt(report: ReportSummary, t: (k: string, o?: Record<string, unknown>) => string): string {
    const when = report.finalized_at ?? report.generated_at;
    if (when === null) return '';
    const d = new Date(when);
    const label = report.finalized_at !== null ? t('Finalized') : t('Generated');
    return `${label}: ${d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`;
}
