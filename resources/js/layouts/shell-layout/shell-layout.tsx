import FlashMessages from '@/components/flash-messages';
import { CommandBar } from '@/components/solo/shell/command-bar';
import { Helper } from '@/components/solo/shell/helper';
import { Pills } from '@/components/solo/shell/pills';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { Rail } from '@/components/solo/shell/rail';
import { TimerStrip } from '@/components/solo/shell/timer-strip';
import { SoloScope } from '@/components/solo/surface';
import { TabBar } from '@/components/solo/tab-bar';
import { SoloToaster } from '@/components/solo/toaster';
import leads from '@/routes/leads';
import monthClose from '@/routes/month-close';
import revenue from '@/routes/revenue';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import styles from './shell-layout.module.css';

interface ShellLayoutProps {
    children: ReactNode;
    /** The page's own statement of where it is; a page without one shows the bare command bar. */
    place?: PlaceResolver<never>;
}

/**
 * Figma: Shell. Every signed-in page: the rail (the tab bar on phones), the command bar with the page's place,
 * its pills, the timer strip and the helper, in the 1040 px column (main 640 and side 380). A guest gets the page alone.
 */
export default function ShellLayout({ children, place }: ShellLayoutProps) {
    const page = usePage<SharedData>();
    const { t, i18n } = useTranslation();
    if (!page.props.auth?.user) return <>{children}</>;

    const { tokens = [], pills = [], pillsFrom, filter } = place?.(page.props as never, { t, url: page.url, locale: i18n.language }) ?? {};
    const timer = page.props.timer ?? null;
    // Screens the rail has no item for stay one search away.
    const places = [
        { label: t('Leads'), url: leads.index().url },
        ...(page.props.auth.user.account.features.billing !== false
            ? [
                  { label: t('Month close'), url: monthClose.index().url },
                  { label: t('Revenue'), url: revenue.index().url },
              ]
            : []),
    ];

    return (
        <div data-day="paper" data-shell="" className={styles.shell}>
            <SoloScope>
                <Rail timer={timer} />
            </SoloScope>
            <div className={styles.column}>
                <SoloScope>
                    <CommandBar tokens={tokens} filter={filter} places={places} />
                    <Pills items={pills} from={pillsFrom} />
                </SoloScope>
                <div className={styles.content}>{children}</div>
            </div>
            <SoloScope>
                {timer && <TimerStrip timer={timer} />}
                <TabBar />
                <Helper page={page.component} props={page.props} />
            </SoloScope>
            <FlashMessages toaster={<SoloToaster />} />
        </div>
    );
}
