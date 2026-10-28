import { KvRow } from '@/components/solo/kv-row';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { monthGenitive, monthName } from '@/lib/dates';
import clientMonths from '@/routes/client-months';
import { router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, localeOf } from '../client-list/format';
import { entriesDiffer, sourceKey } from './format';
import styles from './month-settlement.module.css';
import type { ClosePreview } from './types';

type Loaded = { state: 'loading' } | { state: 'failed' } | { state: 'ready'; preview: ClosePreview };

async function fetchPreview(url: string, signal: AbortSignal): Promise<ClosePreview> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
    if (!response.ok || !(response.headers.get('content-type') ?? '').includes('application/json')) {
        throw new Error(`close preview ${response.status}`);
    }

    return (await response.json()) as ClosePreview;
}

/** Figma: D3 flow F3. One dialog for Przegląd and the month-close page; it posts back the hash of the figures it showed. */
export function CloseMonthDialog({
    clientId,
    period,
    open,
    onOpenChange,
}: {
    clientId: number;
    period: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [loaded, setLoaded] = useState<Loaded>({ state: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const [processing, setProcessing] = useState(false);

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setLoaded({ state: 'loading' });
        }
    }

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        fetchPreview(clientMonths.preview([clientId, period]).url, controller.signal)
            .then((preview) => setLoaded({ state: 'ready', preview }))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setLoaded({ state: 'failed' });
                }
            });

        return () => controller.abort();
    }, [open, clientId, period, attempt]);

    const preview = loaded.state === 'ready' ? loaded.preview : null;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (preview === null || preview.refusal !== null) {
            return;
        }
        setProcessing(true);
        router.post(
            clientMonths.close([clientId, period]).url,
            { preview_hash: preview.hash },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = page.props.flash as { error?: string | null } | undefined;
                    if (flash?.error) {
                        // Refused (the figures moved, a timer started): read the month again instead of closing on stale numbers.
                        setAttempt((n) => n + 1);
                        return;
                    }
                    onOpenChange(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Close {{month}}', { month: monthName(period, locale) })}
            description={
                preview !== null
                    ? t('close_month_lead', { client: preview.client, month: monthName(period, locale) })
                    : loaded.state === 'loading'
                      ? t('Reading the figures…')
                      : t('Could not read the figures. Close the dialog and try again.')
            }
            onSubmit={submit}
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing || preview === null || preview.refusal !== null}>
                        {t('Close the month')}
                    </SoloButton>
                </>
            )}
        >
            {preview !== null && <CloseMonthSummary preview={preview} locale={locale} />}
        </ResponsiveDialog>
    );
}

/** The figures a close freezes, the source of the used hours, and what refuses it, without the dialog around them. */
export function CloseMonthSummary({ preview, locale }: { preview: ClosePreview; locale: string }) {
    const { t } = useTranslation();
    // Frozen figures keep the precision they are stored with, as the ledger and the toast print them.
    const h = (value: number) => `${hoursNumber(value, locale, true)}\u00a0h`;
    const month = monthName(preview.period, locale);

    return (
        <>
            <dl className={styles.ledger}>
                <KvRow layout="ledger" label={t('Package')}>
                    {h(preview.contracted ?? 0)}
                </KvRow>
                <KvRow layout="ledger" label={t('From previous months')} tone={preview.opening < 0 ? 'accent' : undefined}>
                    {h(preview.opening)}
                </KvRow>
                {preview.forfeited > 0 && (
                    <KvRow layout="ledger" label={t('Over the rollover cap')}>
                        {h(-preview.forfeited)}
                    </KvRow>
                )}
                <KvRow layout="ledger" label={`${t('Worked')} · ${t(sourceKey(preview))}`}>
                    {h(-preview.used)}
                </KvRow>
                <KvRow
                    layout="ledger"
                    label={
                        <span className={styles.total}>{t('Balance → opening of {{month}}', { month: monthGenitive(preview.next, locale) })}</span>
                    }
                >
                    <span className={styles.total}>{h(preview.closing)}</span>
                </KvRow>
            </dl>
            {preview.refusal !== null ? (
                <p className={styles.refusal} role="alert">
                    {preview.refusal}
                </p>
            ) : (
                <p className={styles.info}>
                    {entriesDiffer(preview) && `${t('Entries today: {{hours}} h.', { hours: hoursNumber(preview.entries, locale) })} `}
                    {t('You can reopen {{month}} in Rozliczenia.', { month })}
                </p>
            )}
        </>
    );
}
