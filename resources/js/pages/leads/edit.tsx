import { Head, Link, router } from '@inertiajs/react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { ActionMenu, ActionMenuItem } from '@/components/solo/action-menu';
import { Chip } from '@/components/solo/chip';
import { fieldControl, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { IconButton } from '@/components/solo/icon-button';
import { useLinkPrefetch } from '@/components/solo/link-prefetch';
import { SoloButton, soloButtonClass } from '@/components/solo/primitives';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileHeader, TileNote, TileStack } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { localeOf } from '@/lib/hours-text';
import clients from '@/routes/clients';
import leads from '@/routes/leads';
import { SharedData } from '@/types';

import { ScorePanel, type ScoreFactors, type ScoringMap } from './components/score-panel';
import { StageHistory, type StageEvent } from './components/stage-history';
import { StageStepper } from './components/stage-stepper';
import { TierBadge } from './components/tier-badge';
import styles from './edit.module.css';
import { useLeadLabel, type LabelMap, type PipelineLabels } from './lib/labels';
import { leadPlace } from './place';

interface PipelineMeta {
    key: string;
    label: string | null;
    stages: string[];
    won_stage: string;
    scoring: ScoringMap;
    labels?: PipelineLabels;
}

interface LeadData {
    id: number;
    deleted_at: string | null;
    pipeline: string;
    name: string;
    company: string | null;
    email: string | null;
    phone: string | null;
    source: string;
    stage: string;
    client_id: number | null;
    notes: string | null;
    captured_at: string | null;
    client: { id: number; name: string } | null;
    is_won: boolean;
    score_factors: ScoreFactors;
    score_total: number;
    tier: string;
    tier_routing: string | null;
}

interface EditPageProps extends SharedData {
    lead: LeadData;
    pipelines: PipelineMeta[];
    source_labels: LabelMap;
    events: StageEvent[];
    tier_thresholds: { gold_min: number; oak_min: number };
}

interface Derived {
    score_total: number;
    tier: string;
    tier_routing: string | null;
    stage: string;
    is_won: boolean;
}

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-XSRF-TOKEN': readXsrfToken(),
    'X-Requested-With': 'XMLHttpRequest',
});

/**
 * Figma: Leads · lead card (1094:36641, phone 1094:36990). The stage as the primary verb in the head, fields that
 * save on blur (no Save button), score ticks that persist at once, and the append-only stage history.
 */
export default function Edit({ lead, pipelines, source_labels, events, tier_thresholds }: EditPageProps) {
    const { t, i18n } = useTranslation();
    const prefetch = useLinkPrefetch();
    const clientHref = lead.client ? clients.show({ client: lead.client.id, tab: 'overview' }).url : null;
    const label = useLeadLabel();
    const [confirmDelete, setConfirmDelete] = React.useState(false);
    const [confirmConvert, setConfirmConvert] = React.useState(false);

    const pipeline = pipelines.find((p) => p.key === lead.pipeline);
    const multiBrand = pipelines.length > 1;

    const [form, setForm] = React.useState({
        name: lead.name,
        company: lead.company ?? '',
        email: lead.email ?? '',
        phone: lead.phone ?? '',
        notes: lead.notes ?? '',
    });
    const [factors, setFactors] = React.useState<ScoreFactors>((lead.score_factors ?? {}) as ScoreFactors);
    const [derived, setDerived] = React.useState<Derived>({
        score_total: lead.score_total,
        tier: lead.tier,
        tier_routing: lead.tier_routing,
        stage: lead.stage,
        is_won: lead.is_won,
    });
    const [errors, setErrors] = React.useState<Record<string, string>>({});
    const [savedFlash, setSavedFlash] = React.useState(false);
    const [moving, setMoving] = React.useState(false);
    const [emailCopied, setEmailCopied] = React.useState(false);
    const [eventToDelete, setEventToDelete] = React.useState<number | null>(null);
    const lastSavedRef = React.useRef({ ...form, factors });
    const savedTimerRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

    const flashSaved = () => {
        setSavedFlash(true);
        if (savedTimerRef.current) clearTimeout(savedTimerRef.current);
        savedTimerRef.current = setTimeout(() => setSavedFlash(false), 1600);
    };

    /** Persist the whole record (validation wants the full payload); returns the re-derived numbers. */
    const persist = (nextForm: typeof form, nextFactors: ScoreFactors) => {
        fetch(leads.update(lead.id).url, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify({ ...nextForm, score_factors: nextFactors }),
        })
            .finally(() => router.flushAll())
            .then(async (res) => {
                if (res.status === 422) {
                    const body = (await res.json()) as { errors?: Record<string, string[]> };
                    setErrors(Object.fromEntries(Object.entries(body.errors ?? {}).map(([k, v]) => [k, v[0]])));
                    throw new Error('validation');
                }
                if (!res.ok) throw new Error('save failed');
                return (await res.json()) as Derived;
            })
            .then((next) => {
                lastSavedRef.current = { ...nextForm, factors: nextFactors };
                setErrors({});
                setDerived(next);
                flashSaved();
            })
            .catch(() => undefined);
    };

    const handleBlur = (field: keyof typeof form) => {
        if (form[field] === lastSavedRef.current[field]) return;
        persist(form, factors);
    };

    const handleFactorsChange = (next: ScoreFactors) => {
        setFactors(next);
        persist(form, next);
    };

    const handleStageSelect = (stage: string) => {
        setMoving(true);
        fetch(`/leads/${lead.id}/stage`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify({ stage }),
        })
            .then(async (res) => {
                if (!res.ok) throw new Error('stage move rejected');
                const body = (await res.json()) as { stage: string; is_won: boolean };
                setDerived((prev) => ({ ...prev, stage: body.stage, is_won: body.is_won }));
                // The move is an append-only event - refresh the timeline.
                router.reload({ only: ['events', 'lead', 'flash'] });
            })
            .catch(() => undefined)
            .finally(() => setMoving(false));
    };

    const setField = (field: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
        setForm((prev) => ({ ...prev, [field]: e.target.value }));

    const copyEmail = () => {
        void navigator.clipboard.writeText(form.email).then(() => {
            setEmailCopied(true);
            setTimeout(() => setEmailCopied(false), 1600);
        });
    };

    // Progressive disclosure: the latest moves are the working context; the
    // long tail is reference. Three rows cover "what just happened" without
    // burying the notes and details below.
    const stageName = (stage: string) => label('stage', stage, pipeline?.labels?.stages?.[stage]);
    const title = form.name || lead.name;

    return (
        <SoloSurface day="paper">
            <Head title={title} />
            <TileGrid>
                <TileStack>
                    <Tile kind="plain">
                        <div className={styles.head} data-fill="">
                            <h1 className={styles.title}>{title}</h1>
                            <ActionMenu trigger={<IconButton icon="more" look="outline" label={t('More actions')} />}>
                                {lead.deleted_at ? (
                                    <ActionMenuItem onSelect={() => router.put(leads.restore(lead.id).url, {}, { preserveScroll: true })}>
                                        {t('Restore lead')}
                                    </ActionMenuItem>
                                ) : (
                                    <ActionMenuItem onSelect={() => setConfirmDelete(true)}>{t('Delete lead')}</ActionMenuItem>
                                )}
                            </ActionMenu>
                        </div>
                        <div className={styles.chips}>
                            {lead.deleted_at && <Chip status="open">{t('deleted_lead_chip')}</Chip>}
                            <TierBadge tier={derived.tier} total={derived.score_total} routing={derived.tier_routing} />
                            {multiBrand && <Chip status="agent">{label('pipeline', lead.pipeline, pipeline?.label)}</Chip>}
                            <span className={styles.meta}>{label('source', lead.source, source_labels?.[lead.source])}</span>
                            {lead.client && clientHref && (
                                <Link className={styles.clientLink} href={clientHref} prefetch={prefetch(clientHref)}>
                                    {lead.client.name}
                                </Link>
                            )}
                            <span className={styles.saved} data-shown={savedFlash || undefined} aria-live="polite">
                                {savedFlash && t('Saved')}
                            </span>
                        </div>
                        <StageStepper
                            stages={pipeline?.stages ?? []}
                            current={derived.stage}
                            stageLabels={pipeline?.labels?.stages}
                            onSelect={handleStageSelect}
                            disabled={moving}
                        />
                        {/* One state-dependent primary action: Convert at the terminal stage, once. After that the link above leads to the client. */}
                        {derived.is_won && !lead.client && (
                            <SoloButton size="control" onClick={() => setConfirmConvert(true)}>
                                {t('Convert to client')}
                            </SoloButton>
                        )}
                    </Tile>

                    <Tile kind="plain">
                        <TileHeader label={t('Details')} />
                        <div className={styles.fields} data-fill="">
                            <SoloFieldRow>
                                <SoloField label={t('Name')} htmlFor="name" error={errors.name}>
                                    <SoloInput {...fieldControl('name', errors.name)} value={form.name} onChange={setField('name')} onBlur={() => handleBlur('name')} />
                                </SoloField>
                                <SoloField label={t('Company')} htmlFor="company" error={errors.company}>
                                    <SoloInput {...fieldControl('company', errors.company)} value={form.company} onChange={setField('company')} onBlur={() => handleBlur('company')} />
                                </SoloField>
                            </SoloFieldRow>
                            <SoloField label={t('Email')} htmlFor="email" error={errors.email}>
                                <div className={styles.withAction}>
                                    <SoloInput {...fieldControl('email', errors.email)} type="email" value={form.email} onChange={setField('email')} onBlur={() => handleBlur('email')} />
                                    {form.email && (
                                        <SoloButton variant="secondary" size="control" onClick={copyEmail}>
                                            {emailCopied ? t('Copied') : t('Copy email')}
                                        </SoloButton>
                                    )}
                                </div>
                            </SoloField>
                            <SoloField label={t('Phone')} htmlFor="phone" error={errors.phone}>
                                <div className={styles.withAction}>
                                    <SoloInput {...fieldControl('phone', errors.phone)} value={form.phone} onChange={setField('phone')} onBlur={() => handleBlur('phone')} />
                                    {form.phone && (
                                        <a className={soloButtonClass({ variant: 'secondary', size: 'control' })} href={`tel:${form.phone}`}>
                                            {t('Call')}
                                        </a>
                                    )}
                                </div>
                            </SoloField>
                            {/* Source is the channel attribution and the capture date is reference: shown, never edited. */}
                            <SoloFieldRow>
                                <SoloField label={t('Source')} htmlFor="source">
                                    <SoloInput id="source" value={label('source', lead.source, source_labels?.[lead.source])} readOnly disabled />
                                </SoloField>
                                <SoloField label={t('Captured')} htmlFor="captured">
                                    <SoloInput id="captured" value={lead.captured_at ? new Date(lead.captured_at).toLocaleString(localeOf(i18n.language)) : ''} readOnly disabled />
                                </SoloField>
                            </SoloFieldRow>
                            <TileNote>{t('Set at capture and immutable - it is the channel attribution.')}</TileNote>
                            <SoloField label={t('Notes')} htmlFor="notes">
                                <SoloTextarea id="notes" rows={5} value={form.notes} onChange={setField('notes')} onBlur={() => handleBlur('notes')} />
                            </SoloField>
                        </div>
                    </Tile>
                </TileStack>

                <TileStack as="aside">
                    {/* Score ticks persist at once; the tier chip in the head re-derives the moment its cause changes. */}
                    <ScorePanel
                        scoring={pipeline?.scoring ?? {}}
                        value={factors}
                        onChange={handleFactorsChange}
                        savedTier={derived.tier}
                        savedRouting={derived.tier_routing}
                        dirty={false}
                        thresholds={tier_thresholds}
                        labels={pipeline?.labels}
                    />

                    {/* The append-only history is what replaces assumed conversion rates with measured ones. Accidental or
                        test moves are deletable (the capture event is not): a curated history measures better. */}
                    <StageHistory events={events} stageName={stageName} onDelete={setEventToDelete} />
                </TileStack>
            </TileGrid>

            <ConfirmDialog
                open={eventToDelete !== null}
                onOpenChange={(open) => !open && setEventToDelete(null)}
                title={t('Delete history entry?')}
                description={t('Removes this stage move from the measured history. Use it for accidental or test moves only.')}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => {
                    if (eventToDelete !== null) {
                        router.delete(`/lead-events/${eventToDelete}`, { preserveScroll: true });
                    }
                    setEventToDelete(null);
                }}
            />

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('Delete lead')}
                description={t('The lead and its stage history will be removed.')}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={() => router.delete(leads.destroy(lead.id).url)}
            />

            <ConfirmDialog
                open={confirmConvert}
                onOpenChange={setConfirmConvert}
                title={t('Convert to client')}
                description={t('A client will be created from this lead. The lead stays linked so its source keeps attributing the channel.')}
                confirmLabel={t('Convert')}
                onConfirm={() => router.post(leads.convert(lead.id).url)}
            />
        </SoloSurface>
    );
}

Edit.place = (({ lead }, context) => leadPlace(lead.name, context)) satisfies PlaceResolver<EditPageProps>;
