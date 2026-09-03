import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

export interface StartSessionLaunchResult {
    session_path: string;
    branch_name: string;
    base_branch: string;
    port: number;
    pid: number;
    mode: 'worktree' | 'in_repo';
}

type SessionCli = 'claude' | 'codex';

// Rolling aliases only - pinned model IDs go stale; aliases track whatever
// the CLI currently maps them to. Free-text input stays available for pins.
const CLI_MODEL_PRESETS: Record<SessionCli, string[]> = {
    claude: ['opus', 'sonnet', 'haiku'],
    codex: ['gpt-5', 'gpt-5-codex'],
};

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    taskId: number;
    /** Which CLI the task runs - drives the model preset list. Null hides
     *  the model picker entirely (defensive: tasks without a CLI shouldn't
     *  reach this dialog, but the prop is optional to avoid breaking
     *  callers that don't know the cli at render time). */
    cli?: SessionCli | null;
    /** Fired with the launch result on a successful start. */
    onLaunched: (result: StartSessionLaunchResult) => void;
    /** Fired when the branches endpoint returns repository_missing - caller
     *  should open the RepositoryFormDialog for this project, then re-open
     *  this dialog after the repo is saved. */
    onRepoMissing?: (projectId: number) => void;
    /** Project id only used to forward to onRepoMissing. */
    projectId?: number;
}

interface BranchesPayload {
    branches: string[];
    default_branch: string | null;
    current_branch: string | null;
}

type SessionMode = 'worktree' | 'in_repo';

/**
 * Forced branch picker: every session start opens this dialog so the user
 * consciously picks the base branch (and the worktree-vs-in-repo mode) to
 * fork the session from. No silent fallback to "main" - keeps repo branch
 * state intentional.
 */
export function StartSessionDialog({ open, onOpenChange, taskId, cli, onLaunched, onRepoMissing, projectId }: Props) {
    const { t } = useTranslation();
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [branches, setBranches] = useState<BranchesPayload | null>(null);
    const [selected, setSelected] = useState<string>('');
    const [mode, setMode] = useState<SessionMode>('in_repo');
    const [cliModel, setCliModel] = useState<string>('');
    const [error, setError] = useState<string | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const modelPresets = cli ? CLI_MODEL_PRESETS[cli] : [];

    useEffect(() => {
        if (!open) {
            setBranches(null);
            setSelected('');
            setMode('in_repo');
            setCliModel('');
            setError(null);
            setSubmitError(null);
            return;
        }
        let cancelled = false;
        setLoading(true);
        setError(null);
        fetch(`/tasks/${taskId}/session-branches`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (res) => {
                if (cancelled) return;
                if (!res.ok) {
                    const body = await res.json().catch(() => ({ message: t('Failed to load branches'), code: 'unknown' }));
                    if ((body.code === 'repository_missing' || body.code === 'repository_invalid_path') && projectId && onRepoMissing) {
                        // Hand off to the parent's repo dialog flow - and close
                        // ourselves so the user isn't looking at two dialogs.
                        onOpenChange(false);
                        onRepoMissing(projectId);
                        return;
                    }
                    setError(body.message ?? t('Failed to load branches'));
                    return;
                }
                const payload: BranchesPayload = await res.json();
                setBranches(payload);
                // Pre-select default → current → first. The user still has to
                // click Start; this is just a sensible starting point.
                setSelected(payload.default_branch ?? payload.current_branch ?? payload.branches[0] ?? '');
            })
            .catch(() => {
                if (!cancelled) setError(t('Failed to load branches'));
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [open, taskId, projectId, onRepoMissing, onOpenChange, t]);

    const handleStart = () => {
        if (!selected) return;
        setSubmitting(true);
        setSubmitError(null);
        fetch(`/tasks/${taskId}/start-session`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                base_branch: selected,
                mode,
                cli_model: cliModel || null,
            }),
        })
            .then(async (res) => {
                if (!res.ok) {
                    const body = await res.json().catch(() => ({ message: t('Failed to start session') }));
                    if ((body.code === 'repository_missing' || body.code === 'repository_invalid_path') && projectId && onRepoMissing) {
                        onOpenChange(false);
                        onRepoMissing(projectId);
                        return;
                    }
                    // working_tree_dirty / repo_busy: keep the dialog open and
                    // show inline so the user can read the message and either
                    // switch to worktree mode or stop the conflicting session.
                    if (body.code === 'working_tree_dirty' || body.code === 'repo_busy') {
                        setSubmitError(body.message ?? t('Failed to start session'));
                        return;
                    }
                    toast.error(body.message ?? t('Failed to start session'));
                    return;
                }
                const result: StartSessionLaunchResult = await res.json();
                onOpenChange(false);
                onLaunched(result);
            })
            .catch(() => toast.error(t('Failed to start session')))
            .finally(() => setSubmitting(false));
    };

    const branchLabel = (name: string): string => {
        if (branches?.default_branch === name) return `${name} (${t('default')})`;
        if (branches?.current_branch === name) return `${name} (${t('current')})`;
        return name;
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Start session')}</DialogTitle>
                </DialogHeader>

                {loading && <p>{t('Loading…')}</p>}

                {!loading && error && <p style={{ color: 'var(--color-destructive)' }}>{error}</p>}

                {!loading && !error && branches && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-3)' }}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-2)' }}>
                            <p style={{ fontSize: '0.875rem', color: 'var(--color-muted-foreground)' }}>
                                {t(
                                    'Pick the branch to fork the session from. The new branch session/task-{{id}} will be created off the selected base.',
                                    { id: taskId },
                                )}
                            </p>
                            {branches.branches.length === 0 ? (
                                <p>{t('No branches found in this repository.')}</p>
                            ) : (
                                <Select value={selected} onValueChange={setSelected} disabled={submitting}>
                                    <SelectTrigger>
                                        <SelectValue placeholder={t('Pick a branch…')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branches.branches.map((name) => (
                                            <SelectItem key={name} value={name}>
                                                {branchLabel(name)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-2)' }}>
                            <p style={{ fontSize: '0.875rem', color: 'var(--color-muted-foreground)' }}>{t('Where to run the session')}</p>
                            <label style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--spacing-2)', fontSize: '0.875rem' }}>
                                <input
                                    type="radio"
                                    name="session_mode"
                                    value="in_repo"
                                    checked={mode === 'in_repo'}
                                    onChange={() => setMode('in_repo')}
                                    disabled={submitting}
                                    style={{ marginTop: '0.2rem' }}
                                />
                                <span>
                                    <strong>{t('In repo (recommended)')}</strong>
                                    <br />
                                    <span style={{ color: 'var(--color-muted-foreground)' }}>
                                        {t(
                                            'Checkout the session branch in the main repo. Refuses if the working tree is dirty or another in-repo session is live. Task previews are not available in this mode.',
                                        )}
                                    </span>
                                </span>
                            </label>
                            <label style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--spacing-2)', fontSize: '0.875rem' }}>
                                <input
                                    type="radio"
                                    name="session_mode"
                                    value="worktree"
                                    checked={mode === 'worktree'}
                                    onChange={() => setMode('worktree')}
                                    disabled={submitting}
                                    style={{ marginTop: '0.2rem' }}
                                />
                                <span>
                                    <strong>{t('Worktree (isolated)')}</strong>
                                    <br />
                                    <span style={{ color: 'var(--color-muted-foreground)' }}>
                                        {t(
                                            'Separate working tree under .worktrees/. Allows parallel sessions, but env files / dependencies do not carry over. Task previews require this mode.',
                                        )}
                                    </span>
                                </span>
                            </label>
                        </div>
                        {cli && modelPresets.length > 0 && (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-2)' }}>
                                <p style={{ fontSize: '0.875rem', color: 'var(--color-muted-foreground)' }}>
                                    {t('Model (passed via {{flag}})', { flag: cli === 'claude' ? '--model' : '-m' })}
                                </p>
                                <Select
                                    value={cliModel === '' ? '__default__' : cliModel}
                                    onValueChange={(value) => setCliModel(value === '__default__' ? '' : value)}
                                    disabled={submitting}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__default__">{t('Default (no flag)')}</SelectItem>
                                        {modelPresets.map((name) => (
                                            <SelectItem key={name} value={name}>
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        {submitError && (
                            <p style={{ color: 'var(--color-destructive)', fontSize: '0.875rem', whiteSpace: 'pre-wrap' }}>{submitError}</p>
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                        {t('Cancel')}
                    </Button>
                    <Button type="button" onClick={handleStart} disabled={!selected || submitting || loading}>
                        {submitting ? t('Starting…') : t('Start session')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
