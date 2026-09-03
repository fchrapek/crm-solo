import {
    DndContext,
    type DragEndEvent,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import * as React from 'react';

import styles from './kanban-board.module.css';

export interface KanbanLane<T> {
    id: string;
    label: React.ReactNode;
    items: T[];
    /** Optional override for the count chip - defaults to items.length */
    count?: number;
    /** Optional placeholder shown when items.length === 0 */
    emptyPlaceholder?: React.ReactNode;
}

interface Props<T> {
    lanes: KanbanLane<T>[];
    /** Render card content. Drag handle is the whole card unless `dragHandle` is rendered explicitly. */
    renderCard: (item: T) => React.ReactNode;
    getCardId: (item: T) => string | number;
    /** Return false to skip drag for this card (e.g. read-only Trello-source tasks). Default: all draggable. */
    isCardDraggable?: (item: T) => boolean;
    /** Called when a card is dropped onto a different lane. Lane ids match the `lanes` prop. */
    onMove: (cardId: string | number, fromLaneId: string, toLaneId: string) => void;
    /** Min height applied per lane. Default 200px. Set to undefined to size to content. */
    laneMinHeight?: number;
}

/**
 * Generic drag-and-drop kanban board. Layout, lane shell and dnd plumbing live here;
 * card visuals are entirely consumer-controlled via `renderCard`.
 */
export function KanbanBoard<T>({
    lanes,
    renderCard,
    getCardId,
    isCardDraggable,
    onMove,
    laneMinHeight = 200,
}: Props<T>) {
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

    // Build a card-id → lane-id map so onMove can report the source lane.
    const cardLaneMap = React.useMemo(() => {
        const map = new Map<string | number, string>();
        for (const lane of lanes) {
            for (const item of lane.items) {
                map.set(getCardId(item), lane.id);
            }
        }
        return map;
    }, [lanes, getCardId]);

    const handleDragEnd = (event: DragEndEvent) => {
        const cardId = event.active.id as string | number;
        const targetLane = event.over?.id as string | undefined;
        if (!targetLane) return;
        const sourceLane = cardLaneMap.get(cardId);
        if (!sourceLane || sourceLane === targetLane) return;
        onMove(cardId, sourceLane, targetLane);
    };

    return (
        <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <div className={styles.board}>
                {lanes.map((lane) => (
                    <Lane
                        key={lane.id}
                        lane={lane}
                        minHeight={laneMinHeight}
                        renderCard={renderCard}
                        getCardId={getCardId}
                        isCardDraggable={isCardDraggable}
                    />
                ))}
            </div>
        </DndContext>
    );
}

function Lane<T>({
    lane,
    minHeight,
    renderCard,
    getCardId,
    isCardDraggable,
}: {
    lane: KanbanLane<T>;
    minHeight: number;
    renderCard: (item: T) => React.ReactNode;
    getCardId: (item: T) => string | number;
    isCardDraggable?: (item: T) => boolean;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: lane.id });
    const count = lane.count ?? lane.items.length;

    return (
        <div
            ref={setNodeRef}
            className={`${styles.lane} ${isOver ? styles.laneOver : ''}`}
            style={{ minHeight }}
        >
            <div className={styles.laneHeader}>
                <span className={styles.laneName}>{lane.label}</span>
                <span className={styles.laneCount}>{count}</span>
            </div>
            <div className={styles.laneBody}>
                {lane.items.length === 0
                    ? lane.emptyPlaceholder ?? <p className={styles.laneEmpty}> - </p>
                    : lane.items.map((item) => (
                        <DraggableCard
                            key={getCardId(item)}
                            id={getCardId(item)}
                            disabled={isCardDraggable ? !isCardDraggable(item) : false}
                        >
                            {renderCard(item)}
                        </DraggableCard>
                    ))}
            </div>
        </div>
    );
}

function DraggableCard({
    id,
    disabled = false,
    children,
}: {
    id: string | number;
    disabled?: boolean;
    children: React.ReactNode;
}) {
    const { setNodeRef, attributes, listeners, transform, isDragging } = useDraggable({
        id,
        disabled,
    });
    const style: React.CSSProperties = {
        transform: transform ? `translate3d(${transform.x}px, ${transform.y}px, 0)` : undefined,
        opacity: isDragging ? 0.5 : 1,
        cursor: disabled ? 'default' : 'grab',
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={styles.card}
            {...(disabled ? {} : attributes)}
            {...(disabled ? {} : listeners)}
        >
            {children}
        </div>
    );
}
