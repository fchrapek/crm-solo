import { type ClientHeaderData, HeaderActionLink, useInHeaderMenu } from '@/components/solo/client-header';
import { Icon } from '@/components/ui/icon';
import { useTranslation } from 'react-i18next';
import { PinMenuAction } from '../client-pin';
import { reportFlowUrl } from './client-overview';
import { CopyContextAction } from './copy-context-action';

export type OverviewAction = 'copy' | 'report' | 'pin';

/** Desktop reads Kopiuj, Generuj, then the pin menu; the phone ••• menu leads with Generuj raport (Figma 330:1683). An order client has no report (D8). */
export function overviewActionOrder(inMenu: boolean, mode: ClientHeaderData['billing_mode']): OverviewAction[] {
    const order: OverviewAction[] = inMenu ? ['report', 'copy', 'pin'] : ['copy', 'report', 'pin'];

    return mode === 'retainer' ? order : order.filter((action) => action !== 'report');
}

export function OverviewActions({ client }: { client: ClientHeaderData }) {
    const { t } = useTranslation();
    const inMenu = useInHeaderMenu();

    return (
        <>
            {overviewActionOrder(inMenu, client.billing_mode).map((action) =>
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
