import { router } from '@inertiajs/react';
import { CANONICAL_LANES, isCanonicalLane, listLaneLabel } from '@/lib/list-lane-label';
import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

import styles from './list-mapping-dialog.module.css';


export interface TrelloList {
    id: string;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    lists: TrelloList[];
    /** Current mapping: trello_list_id → CRM lane (canonical or custom). Missing entries default to Backlog. */
    mapping: Record<string, string>;
    /** Additional CRM lanes beyond the canonical 5, defined per project. */
    customLanes?: string[];
}



export function ListMappingDialog({ open, onOpenChange, projectId, lists, mapping: initialMapping, customLanes: initialCustomLanes }: Props) {
    const { t } = useTranslation();
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [customLanes, setCustomLanes] = useState<string[]>([]);
    const [newLaneName, setNewLaneName] = useState('');
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            const customs = (initialCustomLanes ?? []).filter((l) => l.trim() !== '' && !isCanonicalLane(l));
            const allowed = new Set<string>([...CANONICAL_LANES, ...customs]);
            const next: Record<string, string> = {};
            for (const list of lists) {
                const current = initialMapping[list.id];
                next[list.id] = current && allowed.has(current) ? current : 'Backlog';
            }
            setMapping(next);
            setCustomLanes(customs);
            setNewLaneName('');
        }
    }, [open, lists, initialMapping, initialCustomLanes]);

    const updateLane = (listId: string, lane: string) => {
        setMapping((prev) => ({ ...prev, [listId]: lane }));
    };

    const addCustomLane = () => {
        const trimmed = newLaneName.trim();
        if (trimmed === '') return;
        if (isCanonicalLane(trimmed)) {
            setNewLaneName('');
            return;
        }
        if (customLanes.includes(trimmed)) {
            setNewLaneName('');
            return;
        }
        setCustomLanes((prev) => [...prev, trimmed]);
        setNewLaneName('');
    };

    const removeCustomLane = (lane: string) => {
        setCustomLanes((prev) => prev.filter((l) => l !== lane));
        // Any Trello list mapped to the removed lane falls back to Backlog.
        setMapping((prev) => {
            const next: Record<string, string> = {};
            for (const [listId, currentLane] of Object.entries(prev)) {
                next[listId] = currentLane === lane ? 'Backlog' : currentLane;
            }
            return next;
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        router.put(
            `/projects/${projectId}/trello-list-mapping`,
            { mapping, custom_lanes: customLanes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setProcessing(false);
                    onOpenChange(false);
                },
                onError: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Configure list mapping')}</DialogTitle>
                    <DialogDescription>
                        {t('Map each Trello list to a CRM lane. Cards in lists mapped to "Done" are marked completed on sync.')}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className={styles.form}>
                    {lists.length === 0 ? (
                        <p className={styles.empty}>{t('Sync the board first to load its lists.')}</p>
                    ) : (
                        <div className={styles.table}>
                            <div className={`${styles.row} ${styles.headerRow}`}>
                                <span>{t('Trello list')}</span>
                                <span>{t('CRM lane')}</span>
                            </div>
                            {lists.map((list) => (
                                <div key={list.id} className={styles.row}>
                                    <span className={styles.listName}>{list.name}</span>
                                    <Select value={mapping[list.id] ?? 'Backlog'} onValueChange={(v) => updateLane(list.id, v)} disabled={processing}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {CANONICAL_LANES.map((lane) => (
                                                <SelectItem key={lane} value={lane}>
                                                    {listLaneLabel(t, lane)}
                                                </SelectItem>
                                            ))}
                                            {customLanes.map((lane) => (
                                                <SelectItem key={lane} value={lane}>
                                                    {lane}
                                                    <span className={styles.customBadge}>{t('custom')}</span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className={styles.customLanesSection}>
                        <div className={styles.customLanesHeader}>
                            <span className={styles.customLanesTitle}>{t('Custom CRM lanes')}</span>
                        </div>
                        <p className={styles.customLanesHint}>
                            {t('Add extra columns beyond the canonical five - useful for client-specific stages.')}
                        </p>

                        {customLanes.map((lane) => (
                            <div key={lane} className={styles.customLaneRow}>
                                <span className={styles.listName}>{lane}</span>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label={t('Remove lane')}
                                    onClick={() => removeCustomLane(lane)}
                                    disabled={processing}
                                >
                                    <X size={14} />
                                </Button>
                            </div>
                        ))}

                        <div className={styles.addLaneRow}>
                            <Input
                                type="text"
                                value={newLaneName}
                                onChange={(e) => setNewLaneName(e.target.value)}
                                placeholder={t('New lane name (e.g. Awaiting client)')}
                                disabled={processing}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addCustomLane();
                                    }
                                }}
                            />
                            <Button type="button" variant="outline" onClick={addCustomLane} disabled={processing || newLaneName.trim() === ''}>
                                <Plus size={14} />
                                {t('Add lane')}
                            </Button>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={processing || lists.length === 0}>
                            {processing ? t('Saving...') : t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
