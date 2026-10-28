import { update } from '@/actions/App/Http/Controllers/ClientsController';
import { fieldControl, SoloField, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import clients from '@/routes/clients';
import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './dialog-form.module.css';
import type { ClientDetails } from './types';

const NONE = '__none__';

export const MONTH_CLOSE_LABEL: Record<string, string> = { maintenance: 'Maintenance site', gig: 'Agency gig', [NONE]: 'Not in monthly close' };
export const REPORT_MODE_LABEL: Record<string, string> = { report: 'Full report', summary_email: 'Summary email', none: 'No deliverable' };

export type Draft = { type: string; mode: string; include: string; ssh: string; invoice: string };

function draftOf(details: ClientDetails): Draft {
    return {
        type: details.month_close_type ?? NONE,
        mode: details.report_mode,
        include: details.include_in_month_close ? '1' : '0',
        ssh: details.ssh_config ?? '',
        invoice: details.maintenance_invoice_description ?? '',
    };
}

/** The client update the dialog ends with: the inclusion always goes along, so it is saved as the dialog shows it. */
export function closeSettingsPayload(details: Pick<ClientDetails, 'type' | 'name'>, draft: Pick<Draft, 'include' | 'ssh' | 'invoice'>) {
    return {
        type: details.type,
        name: details.name,
        include_in_month_close: draft.include === '1',
        ssh_config: draft.ssh,
        maintenance_invoice_description: draft.invoice,
    };
}

/**
 * Figma: Zamknięcie miesiąca · Edytuj (579:11685). The type and the deliverable go through their own endpoints, so a client
 * newly typed into the close is included as it is from anywhere else; the rest is the client update.
 */
export function MonthCloseDialog({
    clientId,
    details,
    open,
    onOpenChange,
}: {
    clientId: number;
    details: ClientDetails;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Draft>(() => draftOf(details));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setDraft(draftOf(details));
            setErrors({});
        }
    }

    const set = (key: keyof Draft) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));

    function submit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);
        const original = draftOf(details);
        // Each request either hands over to the next or ends the save; a network failure calls neither onSuccess nor onError.
        const step = (next: () => void) => {
            let advanced = false;

            return {
                preserveScroll: true,
                onSuccess: () => {
                    advanced = true;
                    next();
                },
                onError: setErrors,
                onFinish: () => {
                    if (!advanced) setProcessing(false);
                },
            };
        };
        const done = () => {
            setProcessing(false);
            onOpenChange(false);
        };
        const rest = () => router.put(update(clientId).url, closeSettingsPayload(details, draft), step(done));
        const mode = () =>
            draft.mode !== original.mode ? router.patch(clients.reportMode(clientId).url, { report_mode: draft.mode }, step(rest)) : rest();

        if (draft.type !== original.type) {
            router.patch(clients.monthCloseType(clientId).url, { month_close_type: draft.type === NONE ? null : draft.type }, step(mode));

            return;
        }

        mode();
    }

    /** A client newly typed into the close is included, as the server does it, so the dialog shows what will be saved. */
    function pickType(type: string) {
        setDraft((current) => ({ ...current, type, include: current.type === NONE && type !== NONE ? '1' : current.include }));
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Month close')}
            sheetTitle={t('Month close')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saving={processing}
            footerClassName={styles.actions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Monthly close')} htmlFor="close-type" error={errors.month_close_type}>
                <ResponsiveSelect
                    label={t('Monthly close')}
                    value={draft.type}
                    onValueChange={pickType}
                    disabled={processing}
                    control={fieldControl('close-type', errors.month_close_type)}
                    options={Object.entries(MONTH_CLOSE_LABEL).map(([value, label]) => ({ value, label: t(label) }))}
                />
            </SoloField>
            <SoloField label={t('Deliverable')} htmlFor="close-mode" error={errors.report_mode}>
                <ResponsiveSelect
                    label={t('Deliverable')}
                    value={draft.mode}
                    onValueChange={set('mode')}
                    disabled={processing}
                    control={fieldControl('close-mode', errors.report_mode)}
                    options={Object.entries(REPORT_MODE_LABEL).map(([value, label]) => ({ value, label: t(label) }))}
                />
            </SoloField>
            <SoloField label={t('Include in the monthly close')} htmlFor="close-include" error={errors.include_in_month_close}>
                <ResponsiveSelect
                    label={t('Include in the monthly close')}
                    value={draft.include}
                    onValueChange={set('include')}
                    disabled={processing}
                    control={fieldControl('close-include', errors.include_in_month_close)}
                    options={[
                        { value: '1', label: t('Yes, every month') },
                        { value: '0', label: t('No') },
                    ]}
                />
            </SoloField>
            <SoloField label="SSH" htmlFor="close-ssh" error={errors.ssh_config}>
                <SoloInput
                    {...fieldControl('close-ssh', errors.ssh_config)}
                    value={draft.ssh}
                    onChange={(e) => set('ssh')(e.target.value)}
                    maxLength={1024}
                    disabled={processing}
                />
            </SoloField>
            <SoloField label={t('Invoice line')} htmlFor="close-invoice" error={errors.maintenance_invoice_description}>
                <SoloTextarea
                    {...fieldControl('close-invoice', errors.maintenance_invoice_description)}
                    value={draft.invoice}
                    onChange={(e) => set('invoice')(e.target.value)}
                    maxLength={500}
                    rows={2}
                    disabled={processing}
                />
            </SoloField>
        </ResponsiveDialog>
    );
}
