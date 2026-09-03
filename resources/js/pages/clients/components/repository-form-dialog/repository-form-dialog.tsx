import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const PROVIDERS = ['github', 'gitlab', 'bitbucket', 'local'] as const;
type Provider = (typeof PROVIDERS)[number];

export interface RepositoryDialogValues {
    id: number;
    name: string;
    local_path: string | null;
    remote_url: string | null;
    provider: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    /** Provide an existing repository to edit. Omit for create. */
    initial?: RepositoryDialogValues;
    /** Fired only on successful save (not on cancel). Lets callers retry the
     *  action that prompted the dialog - e.g. "Start session" after the user
     *  attached a missing repo. */
    onSaved?: () => void;
}

export function RepositoryFormDialog({ open, onOpenChange, projectId, initial, onSaved }: Props) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [localPath, setLocalPath] = useState('');
    const [remoteUrl, setRemoteUrl] = useState('');
    const [provider, setProvider] = useState<Provider>('local');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const isEdit = initial !== undefined;

    useEffect(() => {
        if (open) {
            setName(initial?.name ?? '');
            setLocalPath(initial?.local_path ?? '');
            setRemoteUrl(initial?.remote_url ?? '');
            setProvider((initial?.provider as Provider) ?? 'local');
            setErrors({});
        }
    }, [open, initial]);

    // Auto-infer provider from remote URL host (only when creating)
    useEffect(() => {
        if (isEdit || !remoteUrl) return;
        const lower = remoteUrl.toLowerCase();
        if (lower.includes('github.com')) setProvider('github');
        else if (lower.includes('gitlab.com')) setProvider('gitlab');
        else if (lower.includes('bitbucket.org')) setProvider('bitbucket');
    }, [remoteUrl, isEdit]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = {
            name,
            local_path: localPath || null,
            remote_url: remoteUrl || null,
            provider,
        };

        const onSuccess = () => {
            setProcessing(false);
            onOpenChange(false);
            onSaved?.();
        };
        const onError = (errs: Record<string, string>) => {
            setErrors(errs);
            setProcessing(false);
        };

        if (isEdit && initial) {
            router.put(`/repositories/${initial.id}`, payload, { preserveScroll: true, onSuccess, onError });
        } else {
            router.post(`/projects/${projectId}/repositories`, payload, { preserveScroll: true, onSuccess, onError });
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{isEdit ? t('Edit repository') : t('Add repository')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--spacing-4)' }}>
                    <div>
                        <FormLabel htmlFor="repo-name" error={errors.name}>
                            {t('Name')}
                        </FormLabel>
                        <FormInput
                            id="repo-name"
                            type="text"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                            autoFocus
                            maxLength={255}
                            disabled={processing}
                            error={errors.name}
                            placeholder="my-site"
                        />
                        <FormMessage error={errors.name} />
                    </div>

                    <div>
                        <FormLabel htmlFor="repo-local-path" error={errors.local_path}>
                            {t('Local path')}
                        </FormLabel>
                        <FormInput
                            id="repo-local-path"
                            type="text"
                            value={localPath}
                            onChange={(e) => setLocalPath(e.target.value)}
                            maxLength={500}
                            disabled={processing}
                            error={errors.local_path}
                            placeholder="/Users/you/Sites/my-site"
                        />
                        <FormMessage error={errors.local_path} />
                    </div>

                    <div>
                        <FormLabel htmlFor="repo-remote-url" error={errors.remote_url}>
                            {t('Remote URL')}
                        </FormLabel>
                        <FormInput
                            id="repo-remote-url"
                            type="text"
                            value={remoteUrl}
                            onChange={(e) => setRemoteUrl(e.target.value)}
                            maxLength={500}
                            disabled={processing}
                            error={errors.remote_url}
                            placeholder="https://github.com/you/my-site"
                        />
                        <FormMessage error={errors.remote_url} />
                    </div>

                    <div>
                        <FormLabel htmlFor="repo-provider" error={errors.provider}>
                            {t('Provider')}
                        </FormLabel>
                        <Select value={provider} onValueChange={(v) => setProvider(v as Provider)} disabled={processing}>
                            <SelectTrigger id="repo-provider">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {PROVIDERS.map((p) => (
                                    <SelectItem key={p} value={p}>
                                        {p}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <FormMessage error={errors.provider} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={processing || !name.trim()}>
                            {processing ? t('Saving...') : t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
