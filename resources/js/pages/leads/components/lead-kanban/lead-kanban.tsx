import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { KanbanBoard, type KanbanLane } from '@/components/ui/kanban-board';
import leads from '@/routes/leads';
import { useLeadLabel, type LabelMap } from '../../lib/labels';
import { TierBadge } from '../tier-badge';

import styles from './lead-kanban.module.css';

export interface LeadCard {
    id: number;
    pipeline: string;
    name: string;
    company: string | null;
    email: string | null;
    phone: string | null;
    source: string;
    stage: string;
    client_id: number | null;
    client_name?: string | null;
    captured_at: string | null;
    score_total: number;
    tier: string;
}

interface Props {
    leads: LeadCard[];
    /**
     * Lanes come from the server's rowStages() - never the raw config stage
     * list. That is why kiwwwi's analytics-only 'visitor' can never appear as
     * a column: there is no lane to drop a card onto.
     */
    stages: string[];
    /** Optional config-declared labels (slug => label) for lanes and source chips. */
    stageLabels?: LabelMap;
    sourceLabels?: LabelMap;
    /**
     * pipeline key => resolved brand label. Only provided when more than one
     * brand is configured - single-brand boards never surface the concept.
     */
    brandLabels?: LabelMap;
}

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

export function LeadKanban({ leads: initialLeads, stages, stageLabels, sourceLabels, brandLabels }: Props) {
    const label = useLeadLabel();
    const [rows, setRows] = useState<LeadCard[]>(initialLeads);
    useEffect(() => setRows(initialLeads), [initialLeads]);

    const handleMove = (cardId: string | number, _from: string, to: string) => {
        const id = Number(cardId);
        const lead = rows.find((entry) => entry.id === id);
        if (!lead) return;

        const previousStage = lead.stage;
        setRows((prev) => prev.map((entry) => (entry.id === id ? { ...entry, stage: to } : entry)));

        fetch(`/leads/${id}/stage`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ stage: to }),
        })
            .then((res) => {
                if (!res.ok) throw new Error('stage move rejected');
                // The move is recorded as an append-only stage event server
                // side; reload so the funnel counts reflect it.
                router.reload({ only: ['leads', 'flash'] });
            })
            .catch(() => {
                // Roll the card back - the history is the measurement, so a
                // card must never sit in a lane the server did not record.
                setRows((prev) => prev.map((entry) => (entry.id === id ? { ...entry, stage: previousStage } : entry)));
            });
    };

    const lanes: KanbanLane<LeadCard>[] = stages.map((stage) => ({
        id: stage,
        label: label('stage', stage, stageLabels?.[stage]),
        items: rows.filter((lead) => lead.stage === stage),
    }));

    return (
        <div className={styles.board}>
            <KanbanBoard
                lanes={lanes}
                getCardId={(lead) => lead.id}
                onMove={handleMove}
                laneMinHeight={300}
                renderCard={(lead) => (
                    // The whole card is the click target: a "more" menu would only repeat the click the card already owns.
                    <Link href={leads.edit(lead.id).url} className={styles.card} draggable={false}>
                        <span className={styles.name}>{lead.name}</span>
                        {lead.company && <span className={styles.company}>{lead.company}</span>}
                        <span className={styles.foot}>
                            {/* Tier reads before anything else: it is the answer to "who gets my minutes today". */}
                            <TierBadge tier={lead.tier} total={lead.score_total} />
                            <span className={styles.meta}>
                                {[brandLabels ? (brandLabels[lead.pipeline] ?? lead.pipeline) : null, label('source', lead.source, sourceLabels?.[lead.source]), lead.client_name]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </span>
                    </Link>
                )}
            />
        </div>
    );
}
