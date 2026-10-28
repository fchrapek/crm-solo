import { HeaderAction } from '@/components/solo/client-header';
import { Icon } from '@/components/ui/icon';
import clients from '@/routes/clients';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

export class SessionExpiredError extends Error {}

export class ClipboardUnavailableError extends Error {}

type ClipboardLike = Pick<Clipboard, 'writeText'> & Partial<Pick<Clipboard, 'write'>>;

async function fetchContext(url: string): Promise<string> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const json = (response.headers.get('content-type') ?? '').includes('application/json');

    if (response.status === 401 || response.status === 419 || (response.ok && (response.redirected || !json))) {
        throw new SessionExpiredError();
    }
    if (!response.ok) {
        throw new Error(`agent context ${response.status}`);
    }

    return ((await response.json()) as { context: string }).context;
}

/** Safari drops the click's permission across an await, so the clipboard gets a pending item it can wait on. */
export async function copyFromUrl(url: string, clipboard: ClipboardLike | undefined = globalThis.navigator?.clipboard): Promise<void> {
    if (!clipboard) {
        throw new ClipboardUnavailableError();
    }

    const text = fetchContext(url);
    // Observed here so a write that fails before reading the item leaves no unhandled rejection.
    text.catch(() => undefined);

    if (typeof ClipboardItem !== 'undefined' && clipboard.write) {
        const blob = text.then((value) => new Blob([value], { type: 'text/plain' }));
        blob.catch(() => undefined);
        try {
            await clipboard.write([new ClipboardItem({ 'text/plain': blob })]);

            return;
        } catch {
            await text;
        }
    }

    await clipboard.writeText(await text);
}

export function copyErrorMessage(error: unknown): string {
    return error instanceof SessionExpiredError
        ? 'Your session has expired. Sign in again to copy the context.'
        : 'Could not copy the agent context.';
}

/** Copies what `crm brief` gives an agent; the server builds it from the same brief. */
export function CopyContextAction({ clientId }: { clientId: number }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    async function copy() {
        setBusy(true);
        try {
            await copyFromUrl(clients.agentContext(clientId).url);
            toast.success(t('Agent context copied.'));
        } catch (error) {
            toast.error(t(copyErrorMessage(error)));
        } finally {
            setBusy(false);
        }
    }

    return (
        <HeaderAction onClick={copy} disabled={busy} icon={<Icon name="copy" size={16} />}>
            {t('Copy context for the agent')}
        </HeaderAction>
    );
}
