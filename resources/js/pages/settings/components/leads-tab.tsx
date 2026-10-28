import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { KvRow } from '@/components/solo/kv-row';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import settings from '@/routes/settings';
import { router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLeadLabel } from '../../leads/lib/labels';
import type { LeadgenSettings } from '../types';
import styles from './tab.module.css';

type Source = { slug: string; label: string };

/** Figma: Ustawienia / Leady (466:7756), phones 473:8510. Every change saves the whole custom list, as before. */
export function LeadsTab({ leadgen }: { leadgen: LeadgenSettings }) {
    const { t } = useTranslation();
    const label = useLeadLabel();
    const custom: Source[] = leadgen.custom_sources.map((slug) => ({ slug, label: leadgen.source_labels[slug] ?? '' }));
    const [editing, setEditing] = useState<Source | 'new' | null>(null);
    const [removing, setRemoving] = useState<Source | null>(null);

    const save = (
        sources: Source[],
        options: { onSuccess?: () => void; onError?: (errors: Record<string, string>) => void; onFinish?: () => void } = {},
    ) =>
        router.patch(
            settings.leadgen.update().url,
            {
                custom_sources: sources.map((source) => source.slug),
                source_labels: Object.fromEntries(
                    sources.filter((source) => source.label.trim() !== '').map((source) => [source.slug, source.label.trim()]),
                ),
            },
            { preserveScroll: true, ...options },
        );

    return (
        <>
            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={`${t('Default sources')} · ${leadgen.default_sources.length}`}
                    meta={<span className={styles.wide}>{t('read only')}</span>}
                />
                <div className={styles.chipBlock}>
                    <div className={styles.chips}>
                        {leadgen.default_sources.map((slug) => (
                            <span key={slug} className={styles.chip}>
                                {label('source', slug)}
                            </span>
                        ))}
                    </div>
                </div>
                <dl className={`${styles.list} ${styles.phone} ${styles.chipList}`}>
                    <KvRow label={t('Sources')}>{leadgen.default_sources.map((slug) => label('source', slug)).join(', ')}</KvRow>
                </dl>
                <p className={`${styles.note} ${styles.phone}`}>{t('From the configuration, read only')}</p>
            </section>

            <section className={styles.section}>
                <SectionHead
                    kind="body"
                    label={`${t('Custom sources')} · ${custom.length}`}
                    action={<SectionAction onClick={() => setEditing('new')}>{t('New source')}</SectionAction>}
                />
                {custom.length === 0 && <p className={`${styles.note} ${styles.emptyBlock}`}>{t('No custom sources yet.')}</p>}
                <ul className={`${styles.list} ${styles.phone}`}>
                    {custom.map((source) => (
                        <li key={source.slug}>
                            <ListRow
                                title={source.label || label('source', source.slug)}
                                sub={<span className={styles.mono}>{source.slug}</span>}
                                onClick={() => setEditing(source)}
                            />
                        </li>
                    ))}
                </ul>
                <ul className={`${styles.list} ${styles.wide}`}>
                    {custom.map((source) => (
                        <li key={source.slug} className={styles.row}>
                            <span className={styles.rowText}>
                                <span className={styles.rowTitle}>{source.label || label('source', source.slug)}</span>
                                <span className={styles.mono}>{source.slug}</span>
                            </span>
                            <span className={styles.rowEnd}>
                                <SectionAction onClick={() => setEditing(source)}>{t('Edit')}</SectionAction>
                                <SectionAction onClick={() => setRemoving(source)}>{t('Remove')}</SectionAction>
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            <SourceDialog
                editing={editing}
                onClose={() => setEditing(null)}
                onRemove={(source) => {
                    setEditing(null);
                    setRemoving(source);
                }}
                onSave={(source, done) => {
                    const next = editing === 'new' ? [...custom, source] : custom.map((row) => (row.slug === source.slug ? source : row));
                    save(next, done);
                }}
            />
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(value) => !value && setRemoving(null)}
                title={t('Remove source')}
                description={t('Leads already tagged {{slug}} keep it; new leads can no longer pick it.', { slug: removing?.slug ?? '' })}
                confirmLabel={t('Remove')}
                variant="destructive"
                onConfirm={() => {
                    if (removing !== null) save(custom.filter((row) => row.slug !== removing.slug));
                    setRemoving(null);
                }}
            />
        </>
    );
}

/** New source: slug and label. An existing one changes its label only, so leads never lose the source they were captured with. */
function SourceDialog({
    editing,
    onClose,
    onRemove,
    onSave,
}: {
    editing: Source | 'new' | null;
    onClose: () => void;
    onRemove: (source: Source) => void;
    onSave: (source: Source, done: { onSuccess: () => void; onError: (errors: Record<string, string>) => void; onFinish: () => void }) => void;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<Source>({ slug: '', label: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [shown, setShown] = useState(editing);
    if (editing !== shown) {
        setShown(editing);
        if (editing !== null) {
            setDraft(editing === 'new' ? { slug: '', label: '' } : editing);
            setErrors({});
        }
    }
    const creating = (editing ?? shown) === 'new';
    const existing = editing !== null && editing !== 'new' ? editing : null;
    const slugError = Object.entries(errors).find(([key]) => key.startsWith('custom_sources'))?.[1];

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        onSave(
            { slug: draft.slug.trim(), label: draft.label },
            { onSuccess: onClose, onError: (errs) => setErrors(errs), onFinish: () => setProcessing(false) },
        );
    }

    return (
        <ResponsiveDialog
            open={editing !== null}
            onOpenChange={(open) => !open && onClose()}
            title={creating ? t('New source') : t('Edit source')}
            onSubmit={submit}
            sheetAction="save"
            fields
            saveLabel={creating ? t('Add') : t('Save')}
            saving={processing}
            footerClassName={styles.dialogActions}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={onClose} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : creating ? t('Add source') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Slug')} htmlFor="source-slug" error={slugError}>
                <SoloInput
                    {...fieldControl('source-slug', slugError)}
                    value={draft.slug}
                    placeholder={t('e.g. conference-booth')}
                    onChange={(e) =>
                        setDraft((was) => ({
                            ...was,
                            slug: e.target.value
                                .toLowerCase()
                                .replace(/[^a-z0-9-]+/g, '-')
                                .replace(/^-+/, ''),
                        }))
                    }
                    required
                    maxLength={64}
                    readOnly={!creating}
                    disabled={processing}
                />
            </SoloField>
            <SoloField label={t('Label (optional)')} htmlFor="source-label" error={errors['source_labels.' + draft.slug]}>
                <SoloInput
                    {...fieldControl('source-label')}
                    value={draft.label}
                    onChange={(e) => setDraft((was) => ({ ...was, label: e.target.value }))}
                    maxLength={100}
                    disabled={processing}
                />
            </SoloField>
            {existing !== null && (
                <div className={styles.sheetEnd}>
                    <SoloButton variant="text" size="touch" onClick={() => onRemove(existing)} disabled={processing}>
                        {t('Remove source')}
                    </SoloButton>
                </div>
            )}
        </ResponsiveDialog>
    );
}
