import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

import styles from './connect-trello-dialog.module.css';

type Mode = 'create' | 'link';

type LinkedStatus = 'available' | 'orphan' | 'linked';

interface AvailableBoard {
    id: string;
    name: string;
    url: string | null;
    workspace: string | null;
    linked_status: LinkedStatus;
    linked_client_name: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
}

export function ConnectTrelloDialog({ open, onOpenChange, projectId }: Props) {
    const { t } = useTranslation();
    const [mode, setMode] = useState<Mode>('create');
    const [selectedBoardId, setSelectedBoardId] = useState<string>('');
    const [boards, setBoards] = useState<AvailableBoard[] | null>(null);
    const [loadingBoards, setLoadingBoards] = useState(false);
    const [boardsError, setBoardsError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);

    // Fetch is started imperatively (on mode-switch / dialog-open), not from a
    // useEffect that depends on `loadingBoards` - that earlier shape created a
    // cleanup race where the in-flight fetch's `cancelled` flag flipped before
    // the response landed, leaving the dialog stuck on the loading state.
    const inFlight = useRef(false);

    useEffect(() => {
        if (!open) {
            setMode('create');
            setSelectedBoardId('');
            setBoards(null);
            setBoardsError(null);
            setSubmitError(null);
            inFlight.current = false;
        }
    }, [open]);

    const fetchBoards = async () => {
        if (inFlight.current) return;
        inFlight.current = true;
        setLoadingBoards(true);
        setBoardsError(null);
        try {
            const res = await fetch(`/projects/${projectId}/available-trello-boards`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body.message ?? `HTTP ${res.status}`);
            }
            const data: { boards: AvailableBoard[] } = await res.json();
            setBoards(data.boards);
        } catch (err) {
            setBoardsError(err instanceof Error ? err.message : t('Could not load boards.'));
        } finally {
            setLoadingBoards(false);
            inFlight.current = false;
        }
    };

    const handleModeSelect = (next: Mode) => {
        setMode(next);
        if (next === 'link' && boards === null && !loadingBoards) {
            fetchBoards();
        }
    };

    const canSubmit = !submitting && (mode === 'create' || (mode === 'link' && selectedBoardId !== ''));

    const handleSubmit = () => {
        setSubmitting(true);
        setSubmitError(null);
        const payload = mode === 'link' ? { mode: 'link', trello_board_id: selectedBoardId } : { mode: 'create' };

        router.post(`/projects/${projectId}/connect-trello`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setSubmitting(false);
                onOpenChange(false);
            },
            onError: (errs) => {
                const message = (errs as Record<string, string>).trello ?? Object.values(errs)[0] ?? null;
                setSubmitError(typeof message === 'string' ? message : null);
                setSubmitting(false);
            },
        });
    };

    const hint =
        mode === 'create'
            ? t('Creates a fresh board with default lists (Backlog, To-Do, Doing, Testing, Done) and priority labels.')
            : t('Pick one of your Trello boards. Lists and cards will be pulled in immediately.');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Connect to Trello')}</DialogTitle>
                </DialogHeader>

                <div className={styles.body}>
                    {submitError && <div className={styles.errorBanner}>{submitError}</div>}

                    <div className={styles.modeToggle} role="tablist">
                        <button
                            type="button"
                            role="tab"
                            aria-selected={mode === 'create'}
                            className={`${styles.modeButton} ${mode === 'create' ? styles.modeButtonActive : ''}`}
                            onClick={() => handleModeSelect('create')}
                            disabled={submitting}
                        >
                            {t('Create new board')}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={mode === 'link'}
                            className={`${styles.modeButton} ${mode === 'link' ? styles.modeButtonActive : ''}`}
                            onClick={() => handleModeSelect('link')}
                            disabled={submitting}
                        >
                            {t('Link existing board')}
                        </button>
                    </div>

                    <p className={styles.modeHint}>{hint}</p>

                    {mode === 'link' && (
                        <div className={styles.boardPicker}>
                            {loadingBoards && <span className={styles.boardLoading}>{t('Loading boards…')}</span>}
                            {boardsError && <span className={styles.boardError}>{boardsError}</span>}
                            {!loadingBoards && !boardsError && boards !== null && boards.length === 0 && (
                                <span className={styles.boardEmpty}>{t('No boards on your Trello account.')}</span>
                            )}
                            {!loadingBoards && !boardsError && boards !== null && boards.length > 0 && (
                                <Select value={selectedBoardId} onValueChange={setSelectedBoardId} disabled={submitting}>
                                    <SelectTrigger className={styles.boardSelectTrigger}>
                                        <SelectValue placeholder={t('Select a board')} />
                                    </SelectTrigger>
                                    <SelectContent className={styles.boardSelectContent}>
                                        {boards.map((board) => {
                                            const isLinked = board.linked_status === 'linked';
                                            const workspaceSuffix = board.workspace ? ` (${board.workspace})` : '';
                                            const linkedSuffix =
                                                isLinked && board.linked_client_name
                                                    ? ` - ${t('on {{client}}', { client: board.linked_client_name })}`
                                                    : '';
                                            return (
                                                <SelectItem key={board.id} value={board.id} disabled={isLinked} className={styles.boardSelectItem}>
                                                    <span className={styles.boardOptionText}>
                                                        {board.name}
                                                        {workspaceSuffix}
                                                        {linkedSuffix}
                                                    </span>
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                        {t('Cancel')}
                    </Button>
                    <Button type="button" onClick={handleSubmit} disabled={!canSubmit}>
                        {submitting ? t('Connecting…') : t('Connect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
