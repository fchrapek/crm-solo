import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { FormInput, FormLabel, FormMessage } from '@/components/form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

import styles from './project-form-dialog.module.css';

export interface ProjectDialogValues {
    id: number;
    name: string;
    description: string | null;
    preview_command?: string | null;
    preview_working_dir?: string | null;
    preview_url?: string | null;
    include_in_month_close?: boolean;
    backup_path?: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Required for create mode (no `initial`). Ignored on edit. */
    clientId?: number;
    /** Provide an existing project to edit. Omit for create. */
    initial?: ProjectDialogValues;
}

export function ProjectFormDialog({ open, onOpenChange, clientId, initial }: Props) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [previewCommand, setPreviewCommand] = useState('');
    const [previewWorkingDir, setPreviewWorkingDir] = useState('');
    const [previewUrl, setPreviewUrl] = useState('');
    const [inMonthClose, setInMonthClose] = useState(false);
    const [backupPath, setBackupPath] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const isEdit = initial !== undefined;

    useEffect(() => {
        if (open) {
            setName(initial?.name ?? '');
            setDescription(initial?.description ?? '');
            setPreviewCommand(initial?.preview_command ?? '');
            setPreviewWorkingDir(initial?.preview_working_dir ?? '');
            setPreviewUrl(initial?.preview_url ?? '');
            setInMonthClose(initial?.include_in_month_close ?? false);
            setBackupPath(initial?.backup_path ?? '');
            setErrors({});
        }
    }, [open, initial]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        const payload = {
            name,
            description: description || null,
            preview_command: previewCommand.trim() || null,
            preview_working_dir: previewWorkingDir.trim() || null,
            preview_url: previewUrl.trim() || null,
            ...(isEdit ? { include_in_month_close: inMonthClose, backup_path: backupPath.trim() || null } : {}),
        };

        const onSuccess = () => {
            setProcessing(false);
            onOpenChange(false);
        };
        const onError = (errs: Record<string, string>) => {
            setErrors(errs);
            setProcessing(false);
        };

        if (isEdit && initial) {
            router.put(`/projects/${initial.id}`, payload, { preserveScroll: true, onSuccess, onError });
        } else if (clientId) {
            router.post(`/clients/${clientId}/projects`, payload, { preserveScroll: true, onSuccess, onError });
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{isEdit ? t('Edit project') : t('New project')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className={styles.form}>
                    <div>
                        <FormLabel htmlFor="project-name" error={errors.name}>
                            {t('Name')}
                        </FormLabel>
                        <FormInput
                            id="project-name"
                            type="text"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                            autoFocus
                            maxLength={255}
                            disabled={processing}
                            error={errors.name}
                        />
                        <FormMessage error={errors.name} />
                    </div>

                    <div>
                        <FormLabel htmlFor="project-description">{t('Description')}</FormLabel>
                        <Textarea
                            id="project-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            rows={3}
                            disabled={processing}
                        />
                        <FormMessage error={errors.description} />
                    </div>

                    {isEdit && (
                        <fieldset className={styles.section}>
                            <legend className={styles.sectionLegend}>{t('Monthly close')}</legend>
                            <p className={styles.sectionHint}>
                                {t('Sites in the close get their own checklist on the client card: dump, import, updates, verify, commit, deploy.')}
                            </p>
                            <label className={styles.checkRow}>
                                <Checkbox checked={inMonthClose} onCheckedChange={(checked) => setInMonthClose(checked === true)} disabled={processing} />
                                <span>{t('This project is a site in the monthly close')}</span>
                            </label>
                            <div>
                                <FormLabel htmlFor="project-backup-path" error={errors.backup_path}>
                                    {t('Backup folder')}
                                </FormLabel>
                                <FormInput
                                    id="project-backup-path"
                                    type="text"
                                    value={backupPath}
                                    onChange={(e) => setBackupPath(e.target.value)}
                                    placeholder="/path/to/backups/<client>/<site>"
                                    maxLength={1024}
                                    disabled={processing}
                                    error={errors.backup_path}
                                />
                                <FormMessage error={errors.backup_path} />
                                <p className={styles.sectionHint}>
                                    {t('The folder that directly holds this site\'s dated dump folders. Vault names do not follow project names, so it is stored rather than derived.')}
                                </p>
                            </div>
                        </fieldset>
                    )}

                    {isEdit && (
                        <fieldset className={styles.section}>
                            <legend className={styles.sectionLegend}>{t('Preview')}</legend>
                            <p className={styles.sectionHint}>
                                {t(
                                    'Command that serves this project locally. Runs in the task worktree via an embedded terminal. Examples: ddev start, bun run dev, rails s.',
                                )}
                            </p>
                            <div>
                                <FormLabel htmlFor="project-preview-command" error={errors.preview_command}>
                                    {t('Preview command')}
                                </FormLabel>
                                <FormInput
                                    id="project-preview-command"
                                    type="text"
                                    value={previewCommand}
                                    onChange={(e) => setPreviewCommand(e.target.value)}
                                    placeholder="ddev start"
                                    maxLength={500}
                                    disabled={processing}
                                    error={errors.preview_command}
                                />
                                <FormMessage error={errors.preview_command} />
                            </div>
                            <div>
                                <FormLabel htmlFor="project-preview-working-dir" error={errors.preview_working_dir}>
                                    {t('Working directory (relative to repo root)')}
                                </FormLabel>
                                <FormInput
                                    id="project-preview-working-dir"
                                    type="text"
                                    value={previewWorkingDir}
                                    onChange={(e) => setPreviewWorkingDir(e.target.value)}
                                    placeholder="."
                                    maxLength={255}
                                    disabled={processing}
                                    error={errors.preview_working_dir}
                                />
                                <FormMessage error={errors.preview_working_dir} />
                            </div>
                            <div>
                                <FormLabel htmlFor="project-preview-url" error={errors.preview_url}>
                                    {t('Preview URL (optional)')}
                                </FormLabel>
                                <FormInput
                                    id="project-preview-url"
                                    type="text"
                                    value={previewUrl}
                                    onChange={(e) => setPreviewUrl(e.target.value)}
                                    placeholder="https://project.ddev.site"
                                    maxLength={500}
                                    disabled={processing}
                                    error={errors.preview_url}
                                />
                                <FormMessage error={errors.preview_url} />
                            </div>
                        </fieldset>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? t('Saving...') : isEdit ? t('Save') : t('Create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
