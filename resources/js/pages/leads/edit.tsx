import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Building2, Check, Copy, MoreVertical, Phone, Trash2 } from 'lucide-react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePageActions } from '@/contexts/page-context';
import clients from '@/routes/clients';
import leads from '@/routes/leads';
import { BreadcrumbItem, SharedData } from '@/types';

import { ScorePanel, type ScoreFactors, type ScoringMap } from './components/score-panel';
import { StageStepper } from './components/stage-stepper';
import { TierBadge } from './components/tier-badge';
import styles from './edit.module.css';
import { useLeadLabel, type LabelMap, type PipelineLabels } from './lib/labels';

interface PipelineMeta {
    key: string;
    label: string | null;
    stages: string[];
    won_stage: string;
    scoring: ScoringMap;
    labels?: PipelineLabels;
}

interface StageEvent {
    id: number;
    from_stage: string | null;
    to_stage: string;
    note: string | null;
    created_at: string | null;
    user_name: string | null;
}

interface LeadData {
    id: number;
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
 * The lead record view: stage stepper as the primary verb, save-on-blur
 * fields (no monolithic Save), live-persisting score panel, and the
 * append-only stage timeline. the sidebar rework notes.
 */
export default function Edit({ lead, pipelines, source_labels, events, tier_thresholds }: EditPageProps) {
    const { t } = useTranslation();
    const label = useLeadLabel();
    const { setBreadcrumbs } = usePageActions();
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
    const [showAllEvents, setShowAllEvents] = React.useState(false);
    const [emailCopied, setEmailCopied] = React.useState(false);
    const [eventToDelete, setEventToDelete] = React.useState<number | null>(null);
    const lastSavedRef = React.useRef({ ...form, factors });
    const savedTimerRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            { title: 'Leads', href: leads.index().url },
            { title: form.name || lead.name, href: leads.edit(lead.id).url },
        ],
        [lead.id, lead.name, form.name],
    );
    React.useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

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
    const visibleEvents = showAllEvents ? events : events.slice(0, 3);

    return (
        <>
            <Head title={form.name || lead.name} />

            <div className={styles.page}>
                <header className={styles.header}>
                    <div className={styles.titleRow}>
                        <div className={styles.titleBlock}>
                            <h1 className={styles.title}>{form.name || lead.name}</h1>
                            <div className={styles.chips}>
                                <TierBadge tier={derived.tier} total={derived.score_total} routing={derived.tier_routing} />
                                {multiBrand && <span className={styles.chip}>{label('pipeline', lead.pipeline, pipeline?.label)}</span>}
                                <span className={styles.chipMuted}>{label('source', lead.source, source_labels?.[lead.source])}</span>
                                {lead.client && (
                                    <Link className={styles.chipLink} href={clients.edit(lead.client.id).url}>
                                        <Building2 size={12} /> {lead.client.name}
                                    </Link>
                                )}
                                <span className={`${styles.savedFlash} ${savedFlash ? styles.savedFlashVisible : ''}`} aria-live="polite">
                                    <Check size={12} /> {t('Saved')}
                                </span>
                            </div>
                        </div>

                        <div className={styles.headerActions}>
                            {/* One state-dependent primary action: Convert at the
                                terminal stage, once. After that the chip above
                                links to the client. */}
                            {derived.is_won && !lead.client && (
                                <Button onClick={() => setConfirmConvert(true)}>
                                    <ArrowRight size={16} />
                                    {t('Convert to client')}
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" aria-label={t('More actions')}>
                                        <MoreVertical size={16} />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem variant="destructive" onClick={() => setConfirmDelete(true)}>
                                        <Trash2 size={14} /> {t('Delete')}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <StageStepper
                        stages={pipeline?.stages ?? []}
                        current={derived.stage}
                        stageLabels={pipeline?.labels?.stages}
                        onSelect={handleStageSelect}
                        disabled={moving}
                    />
                </header>

                <div className={styles.columns}>
                    <div className={styles.main}>
                        <section className={styles.card}>
                            <h2 className={styles.sectionTitle}>{t('Notes')}</h2>
                            <Textarea rows={5} value={form.notes} onChange={setField('notes')} onBlur={() => handleBlur('notes')} />
                        </section>

                        {/* The append-only history - this is what Month-2 reads to
                            replace assumed conversion rates with measured ones.
                            Accidental/test moves are deletable (the capture event
                            is not); a curated history measures better than a
                            polluted one. */}
                        <section className={styles.card}>
                            <h2 className={styles.sectionTitle}>{t('Stage history')}</h2>
                            <ol className={styles.events}>
                                {visibleEvents.map((event) => (
                                    <li key={event.id} className={styles.event}>
                                        <div className={styles.eventRow}>
                                            <span className={styles.eventStage}>
                                                {event.from_stage === null
                                                    ? t('Captured at {{stage}}', {
                                                          stage: label('stage', event.to_stage, pipeline?.labels?.stages?.[event.to_stage]),
                                                      })
                                                    : `${label('stage', event.from_stage, pipeline?.labels?.stages?.[event.from_stage])} → ${label('stage', event.to_stage, pipeline?.labels?.stages?.[event.to_stage])}`}
                                            </span>
                                            {event.from_stage !== null && (
                                                <button
                                                    type="button"
                                                    className={styles.eventDelete}
                                                    aria-label={t('Delete history entry?')}
                                                    onClick={() => setEventToDelete(event.id)}
                                                >
                                                    <Trash2 size={12} />
                                                </button>
                                            )}
                                        </div>
                                        <span className={styles.eventMeta}>
                                            {event.created_at ? new Date(event.created_at).toLocaleString() : ''}
                                            {event.user_name ? ` · ${event.user_name}` : ''}
                                        </span>
                                        {event.note && <p className={styles.eventNote}>{event.note}</p>}
                                    </li>
                                ))}
                            </ol>
                            {events.length > 3 && (
                                <button type="button" className={styles.eventsToggle} onClick={() => setShowAllEvents((v) => !v)}>
                                    {showAllEvents ? t('Show less') : t('Show full history ({{count}})', { count: events.length })}
                                </button>
                            )}
                        </section>
                    </div>

                    <aside className={styles.rail}>
                        <section className={styles.card}>
                            <h2 className={styles.sectionTitle}>{t('Details')}</h2>

                            <div className={styles.field}>
                                <Label htmlFor="name">{t('Name')}</Label>
                                <Input id="name" value={form.name} onChange={setField('name')} onBlur={() => handleBlur('name')} />
                                {errors.name && <p className={styles.error}>{errors.name}</p>}
                            </div>

                            <div className={styles.field}>
                                <Label htmlFor="company">{t('Company')}</Label>
                                <Input id="company" value={form.company} onChange={setField('company')} onBlur={() => handleBlur('company')} />
                            </div>

                            <div className={styles.field}>
                                <Label htmlFor="email">{t('Email')}</Label>
                                <div className={styles.fieldWithAction}>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.email}
                                        onChange={setField('email')}
                                        onBlur={() => handleBlur('email')}
                                    />
                                    {form.email && (
                                        <button type="button" className={styles.fieldAction} onClick={copyEmail} aria-label={t('Copy email')}>
                                            {emailCopied ? <Check size={14} /> : <Copy size={14} />}
                                        </button>
                                    )}
                                </div>
                                {errors.email && <p className={styles.error}>{errors.email}</p>}
                            </div>

                            <div className={styles.field}>
                                <Label htmlFor="phone">{t('Phone')}</Label>
                                <div className={styles.fieldWithAction}>
                                    <Input id="phone" value={form.phone} onChange={setField('phone')} onBlur={() => handleBlur('phone')} />
                                    {form.phone && (
                                        <a className={styles.fieldAction} href={`tel:${form.phone}`} aria-label={t('Call')}>
                                            <Phone size={14} />
                                        </a>
                                    )}
                                </div>
                            </div>

                            {/* Metadata, compressed: source (immutable channel
                                attribution - the header hint carries the why via
                                title) and capture date are reference, not work,
                                so they get one quiet line each, not full fields. */}
                            <div className={styles.metaBlock} title={t('Set at capture and immutable - it is the channel attribution.')}>
                                <span className={styles.metaLine}>
                                    {t('Source')}: {label('source', lead.source, source_labels?.[lead.source])}
                                </span>
                                <span className={styles.metaLine}>
                                    {t('Captured')}: {lead.captured_at ? new Date(lead.captured_at).toLocaleString() : ' - '}
                                </span>
                            </div>
                        </section>

                        <section className={styles.card}>
                            {/* Score ticks persist immediately; the tier badge in the
                                header re-derives the moment its cause changes. The
                                checklist is collapsed: the DECISION (tier + routing)
                                is primary, its derivation expands on demand. */}
                            <ScorePanel
                                scoring={pipeline?.scoring ?? {}}
                                value={factors}
                                onChange={handleFactorsChange}
                                savedTier={derived.tier}
                                savedRouting={derived.tier_routing}
                                dirty={false}
                                thresholds={tier_thresholds}
                                labels={pipeline?.labels}
                                collapsible
                            />
                        </section>
                    </aside>
                </div>
            </div>

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
        </>
    );
}
