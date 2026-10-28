import { ActionMenu, ActionMenuItem } from '@/components/solo/action-menu';
import { BottomSheet } from '@/components/solo/bottom-sheet';
import { fieldControl, SoloField, SoloInput } from '@/components/solo/field';
import { ListRow } from '@/components/solo/list-row';
import { SectionAction, SoloButton, touchTargetClass } from '@/components/solo/primitives';
import { RailLine } from '@/components/solo/rail-line';
import { ResponsiveDialog } from '@/components/solo/responsive-dialog';
import { SectionToggle } from '@/components/solo/section-toggle';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { readXsrfToken } from '@/lib/json-request';
import taskAttachments from '@/routes/task-attachments';
import taskLinks from '@/routes/task-links';
import { router } from '@inertiajs/react';
import { type FormEvent, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { resourcesSummary } from './format';
import styles from './task-page.module.css';
import { LIVE_PROPS, type ResourceFile, type ResourceLink, type TaskResources } from './types';

const FILE_TYPES = '.jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.md,.txt,.doc,.docx,.sql,.gz,.zip';
const IMAGE_TYPES = '.jpg,.jpeg,.png,.gif,.webp';

type Kind = 'file' | 'image' | 'link';

/** "staging.example.test/cennik": a link without its scheme, as the frame lists it. */
export function linkText(link: ResourceLink): string {
    if (link.label) return `${link.label} ↗`;

    return link.url.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

async function upload(taskId: number, file: File): Promise<string | null> {
    const body = new FormData();
    body.append('file', file);
    const response = await fetch(taskAttachments.store(taskId).url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': readXsrfToken() },
        body,
    });
    if (response.ok) return null;
    const data = await response.json().catch(() => ({}));

    return typeof data.message === 'string' ? data.message : `HTTP ${response.status}`;
}

function LinkDialog({ taskId, open, onOpenChange }: { taskId: number; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const [url, setUrl] = useState('');
    const [label, setLabel] = useState('');
    const [errors, setErrors] = useState<{ url?: string; label?: string }>({});
    const [processing, setProcessing] = useState(false);

    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setUrl('');
            setLabel('');
            setErrors({});
        }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setProcessing(true);
        router.post(
            taskLinks.store(taskId).url,
            { url: url.trim(), label: label.trim() },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (e) => setErrors(e),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <ResponsiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('Add a link')}
            onSubmit={submit}
            fields
            sheetAction="save"
            saving={processing}
            actions={(size) => (
                <>
                    <SoloButton variant="pill" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('Cancel')}
                    </SoloButton>
                    <SoloButton type="submit" variant="ink" size={size} disabled={processing}>
                        {processing ? t('Saving…') : t('Save')}
                    </SoloButton>
                </>
            )}
        >
            <SoloField label={t('Address')} htmlFor="task-link-url" error={errors.url}>
                <SoloInput
                    {...fieldControl('task-link-url', errors.url)}
                    type="url"
                    inputMode="url"
                    value={url}
                    onChange={(event) => setUrl(event.target.value)}
                    placeholder="https://"
                    maxLength={2048}
                    required
                    disabled={processing}
                    autoFocus
                />
            </SoloField>
            <SoloField label={t('Link name (optional)')} htmlFor="task-link-label" error={errors.label}>
                <SoloInput
                    {...fieldControl('task-link-label', errors.label)}
                    value={label}
                    onChange={(event) => setLabel(event.target.value)}
                    maxLength={200}
                    disabled={processing}
                />
            </SoloField>
        </ResponsiveDialog>
    );
}

type Removal = { kind: 'file'; item: ResourceFile } | { kind: 'link'; item: ResourceLink };

/**
 * Figma: Zasoby (432:1181), Dodaj (425:1198); on phones Dodaj on the row opens
 * the Dodaj zasób sheet (440:6807). Uploads and links are the CRM's on a
 * card too; files pulled from the card and its link attachments are read only.
 */
export function ResourcesSection({ taskId, resources }: { taskId: number; resources: TaskResources }) {
    const { t } = useTranslation();
    const fileInput = useRef<HTMLInputElement>(null);
    const imageInput = useRef<HTMLInputElement>(null);
    const [linking, setLinking] = useState(false);
    const [sheet, setSheet] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [removal, setRemoval] = useState<Removal | null>(null);

    const add = (kind: Kind) => {
        setSheet(false);
        if (kind === 'link') setLinking(true);
        else (kind === 'image' ? imageInput : fileInput).current?.click();
    };

    async function onPick(files: FileList | null) {
        if (!files || files.length === 0) return;
        setUploading(true);
        for (const file of Array.from(files)) {
            const failure = await upload(taskId, file).catch(() => t('Upload failed'));
            if (failure !== null) toast.error(t('{{name}} was not added.', { name: file.name }), { description: failure });
        }
        setUploading(false);
        if (fileInput.current) fileInput.current.value = '';
        if (imageInput.current) imageInput.current.value = '';
        router.reload({ only: LIVE_PROPS });
    }

    function remove() {
        if (removal === null) return;
        const url = removal.kind === 'file' ? taskAttachments.destroy(removal.item.id).url : taskLinks.destroy(removal.item.id ?? 0).url;
        setRemoval(null);
        if (removal.kind === 'file') {
            void fetch(url, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': readXsrfToken() },
            }).then((response) => {
                if (!response.ok) toast.error(t('The file was not removed.'));
                router.reload({ only: LIVE_PROPS });
            });
        } else {
            router.delete(url, { preserveScroll: true });
        }
    }

    const empty = resources.files.length + resources.images.length + resources.links.length === 0;
    const menu = (
        <ActionMenu
            trigger={
                <SectionAction disabled={uploading} aria-haspopup="menu">
                    {uploading ? t('Uploading…') : t('Add')}
                </SectionAction>
            }
        >
            <ActionMenuItem onSelect={() => add('file')}>{t('File')}</ActionMenuItem>
            <ActionMenuItem onSelect={() => add('image')}>{t('Image')}</ActionMenuItem>
            <ActionMenuItem onSelect={() => add('link')}>{t('Link')}</ActionMenuItem>
        </ActionMenu>
    );

    return (
        <>
            <SectionToggle
                label={t('Resources')}
                summary={resourcesSummary(resources, t)}
                phoneSummary={empty ? undefined : null}
                extra={menu}
                phoneExtra={
                    <SectionAction className={touchTargetClass} disabled={uploading} onClick={() => setSheet(true)}>
                        {uploading ? t('Uploading…') : t('Add')}
                    </SectionAction>
                }
                empty={empty}
            >
                <ul className={styles.resources}>
                    {resources.files.map((file) => (
                        <RailLine key={`file-${file.id}`} className={styles.resourceLine}>
                            <a href={file.url} target="_blank" rel="noopener noreferrer" className={styles.resourceLink}>
                                {file.name}
                            </a>
                            {file.from === 'upload' && <RemoveButton label={file.name} onClick={() => setRemoval({ kind: 'file', item: file })} />}
                        </RailLine>
                    ))}
                    {resources.images.length > 0 && (
                        <li className={styles.images}>
                            {resources.images.map((image) => (
                                <figure key={`image-${image.id}`} className={styles.image}>
                                    <a href={image.url} target="_blank" rel="noopener noreferrer" className={styles.thumbLink}>
                                        <img src={image.url} alt={image.name} loading="lazy" className={styles.thumb} width={168} height={112} />
                                    </a>
                                    <figcaption className={styles.imageName}>
                                        <span className={styles.imageLabel}>{image.name}</span>
                                        {image.from === 'upload' && (
                                            <RemoveButton label={image.name} onClick={() => setRemoval({ kind: 'file', item: image })} />
                                        )}
                                    </figcaption>
                                </figure>
                            ))}
                        </li>
                    )}
                    {resources.links.map((link, index) => (
                        <RailLine key={`link-${link.id ?? `card-${index}`}`} className={styles.resourceLine}>
                            <a href={link.url} target="_blank" rel="noopener noreferrer nofollow" className={styles.resourceLink}>
                                {linkText(link)}
                            </a>
                            {link.from === 'crm' && <RemoveButton label={linkText(link)} onClick={() => setRemoval({ kind: 'link', item: link })} />}
                        </RailLine>
                    ))}
                </ul>
            </SectionToggle>

            <input ref={fileInput} type="file" accept={FILE_TYPES} multiple hidden onChange={(event) => void onPick(event.target.files)} />
            <input ref={imageInput} type="file" accept={IMAGE_TYPES} multiple hidden onChange={(event) => void onPick(event.target.files)} />

            <BottomSheet open={sheet} onOpenChange={setSheet} title={t('Add a resource')}>
                {sheet && (
                    <div className={styles.sheetRows}>
                        <ListRow title={t('File')} trailing={null} onClick={() => add('file')} />
                        <ListRow title={t('Image')} trailing={null} onClick={() => add('image')} />
                        <ListRow title={t('Link')} trailing={null} onClick={() => add('link')} />
                    </div>
                )}
            </BottomSheet>

            <LinkDialog taskId={taskId} open={linking} onOpenChange={setLinking} />
            <ConfirmDialog
                open={removal !== null}
                onOpenChange={(open) => {
                    if (!open) setRemoval(null);
                }}
                title={removal?.kind === 'link' ? t('Remove this link?') : t('Remove this file?')}
                description={removal ? (removal.kind === 'file' ? removal.item.name : linkText(removal.item)) : ''}
                confirmLabel={t('Remove')}
                onConfirm={remove}
            />
        </>
    );
}

function RemoveButton({ label, onClick }: { label: string; onClick: () => void }) {
    const { t } = useTranslation();

    return (
        <button type="button" className={`${styles.remove} ${touchTargetClass}`} onClick={onClick} aria-label={t('Remove {{name}}', { name: label })}>
            {t('Remove')}
        </button>
    );
}
