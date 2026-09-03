import { router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { KanbanBoard, KanbanCard, type KanbanLane } from '@/components/ui/kanban-board';
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
                renderCard={(lead) => {
                    const chips = [];
                    if (lead.company) {
                        chips.push({ label: lead.company, tone: 'project' as const, icon: <Building2 size={12} /> });
                    }
                    if (brandLabels) {
                        chips.push({ label: brandLabels[lead.pipeline] ?? lead.pipeline, tone: 'repository' as const });
                    }
                    chips.push({ label: label('source', lead.source, sourceLabels?.[lead.source]), tone: 'label' as const });
                    if (lead.client_name) {
                        chips.push({ label: lead.client_name, tone: 'trello' as const });
                    }

                    return (
                        <KanbanCard
                            title={lead.name}
                            // The whole card is the click target (KanbanCard
                            // href) - no ⋮ menu: its only action would repeat
                            // the click the card already owns.
                            href={leads.edit(lead.id).url}
                            chips={chips}
                            // Tier reads before anything else on a card: it is
                            // the answer to "who gets my minutes today".
                            badge={<TierBadge tier={lead.tier} total={lead.score_total} />}
                            subtitle={lead.email ?? lead.phone ?? null}
                        />
                    );
                }}
            />
        </div>
    );
}
