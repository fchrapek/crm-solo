import { type ClientHeaderData, HeaderActionLink, useInHeaderMenu } from '@/components/solo/client-header';
import { Icon } from '@/components/ui/icon';
import { useTranslation } from 'react-i18next';
import { PinMenuAction } from '../client-pin';
import { reportFlowUrl } from './client-overview';
import { CopyContextAction } from './copy-context-action';

export type OverviewAction = 'copy' | 'report' | 'pin';

/** From 768 px: Copy, Generate, the pin. On a phone Copy is the main button, so the "more" menu holds the rest. An order client has no report, and neither has a viewer without billing. */
export function overviewActionOrder(inMenu: boolean, mode: ClientHeaderData['billing_mode'], billing = true): OverviewAction[] {
    const order: OverviewAction[] = inMenu ? ['report', 'pin'] : ['copy', 'report', 'pin'];

    return mode === 'retainer' && billing ? order : order.filter((action) => action !== 'report');
}

export function OverviewActions({ client }: { client: ClientHeaderData }) {
    const { t } = useTranslation();
    const inMenu = useInHeaderMenu();

    return (
        <>
            {overviewActionOrder(inMenu, client.billing_mode, client.billing !== false).map((action) =>
                action === 'copy' ? (
                    <CopyContextAction key="copy" clientId={client.id} />
                ) : action === 'report' ? (
                    <HeaderActionLink key="report" href={reportFlowUrl(client.id)} icon={<Icon name="file" size={16} />}>
                        {t('Generate a report')}
                    </HeaderActionLink>
                ) : (
                    <PinMenuAction key="pin" clientId={client.id} pinned={client.is_pinned} />
                ),
            )}
        </>
    );
}
