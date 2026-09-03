import { Link, router, usePage } from '@inertiajs/react';
import { AlarmClock, Check, Monitor, MonitorOff, TerminalSquare } from 'lucide-react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import clients from '@/routes/clients';
import { SharedData } from '@/types';

import styles from './daily-session-card.module.css';
import { DemoCliTerminal } from './demo-cli-terminal';

export interface DailySessionAgent {
    agent: string;
    status: string;
    title: string;
    cwd: string;
    dir: string;
    client: { id: number; name: string } | null;
    project: string | null;
}

export interface DanglingTimer {
    id: number;
    client: { id: number; name: string } | null;
    task: string | null;
    started_at: string | null;
    minutes: number | null;
}

export interface DailySessionState {
    available: boolean;
    running?: boolean;
    agents: DailySessionAgent[];
    attach: { port: number; pid: number } | null;
    /** Hosted demo: swap the ttyd viewport for the whitelisted crm prompt. */
    demo_cli?: boolean;
    dangling_timers?: DanglingTimer[];
}

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

/**
 * Status-first daily-session card: live herdr agent states mapped onto CRM
 * clients, polled every 10s. The browser attach is a viewport onto the same
 * daemon the native terminal drives - closing it stops nothing.
 */
export function DailySessionCard({ session }: { session: DailySessionState }) {
    const { t } = useTranslation();
    const sessionHost = usePage<SharedData>().props.terminal_session_host ?? 'localhost';
    const [busy, setBusy] = React.useState(false);
    // The demo terminal is the exhibit - it opens by default there.
    const [showTerminal, setShowTerminal] = React.useState(() => Boolean(session.demo_cli));

    React.useEffect(() => {
        const timer = setInterval(() => router.reload({ only: ['dailySession'] }), 10_000);
        return () => clearInterval(timer);
    }, []);

    const call = (method: 'POST' | 'DELETE') => {
        setBusy(true);
        fetch('/daily-session/attach', {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': readXsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(() => router.reload({ only: ['dailySession'] }))
            .catch(() => undefined)
            .finally(() => setBusy(false));
    };

    if (!session.available) {
        return null;
    }

    const statusClass = (status: string) => (status === 'working' ? styles.dotWorking : status === 'blocked' ? styles.dotBlocked : styles.dotIdle);

    const blocked = session.agents.filter((a) => a.status === 'blocked').length;
    const working = session.agents.filter((a) => a.status === 'working').length;

    return (
        <section className={styles.card}>
            <div className={styles.header}>
                <h2 className={styles.title}>
                    <TerminalSquare size={15} />
                    {t('Daily session')}
                    <span className={styles.summary}>
                        {session.running === false ? t('herdr not running') : t('{{working}} working · {{blocked}} blocked', { working, blocked })}
                    </span>
                </h2>
                <div className={styles.actions}>
                    {session.demo_cli ? (
                        <Button size="sm" variant="outline" onClick={() => setShowTerminal((v) => !v)}>
                            <Monitor size={13} />
                            {showTerminal ? t('Hide terminal') : t('Show terminal')}
                        </Button>
                    ) : session.attach ? (
                        <>
                            <Button size="sm" variant="outline" onClick={() => setShowTerminal((v) => !v)}>
                                <Monitor size={13} />
                                {showTerminal ? t('Hide terminal') : t('Show terminal')}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => call('DELETE')}
                                disabled={busy}
                                title={t('Closes only this browser view - agents keep running')}
                            >
                                <MonitorOff size={13} />
                                {t('Close view')}
                            </Button>
                        </>
                    ) : (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                call('POST');
                                setShowTerminal(true);
                            }}
                            disabled={busy || session.running === false}
                        >
                            <Monitor size={13} />
                            {t('Attach here')}
                        </Button>
                    )}
                </div>
            </div>

            {(session.dangling_timers?.length ?? 0) > 0 ? (
                <div className={styles.timers}>
                    <span className={styles.timersHeading}>
                        <AlarmClock size={13} /> {t('Running timers - close them to start the day fresh')}
                    </span>
                    {session.dangling_timers!.map((timer) => (
                        <span key={timer.id} className={styles.timerRow}>
                            {timer.client ? (
                                <Link className={styles.timerClient} href={`/clients/${timer.client.id}/edit#activity`} prefetch>
                                    {timer.client.name}
                                </Link>
                            ) : (
                                <span> - </span>
                            )}
                            <span className={styles.timerMeta}>
                                {timer.task ?? t('no task')}
                                {timer.minutes !== null && ` · ${Math.floor(timer.minutes / 60)}h ${timer.minutes % 60}m`}
                            </span>
                        </span>
                    ))}
                </div>
            ) : (
                <span className={styles.freshDay}>
                    <Check size={12} /> {t('No running timers - fresh day')}
                </span>
            )}

            {showTerminal && (session.demo_cli || session.attach) ? (
                /* Terminal active: the viewport takes the width it deserves,
                   the session list moves into a narrow rail beside it. */
                <div className={styles.split}>
                    {session.demo_cli ? (
                        <DemoCliTerminal />
                    ) : (
                        <iframe title="daily-session" src={`http://${sessionHost}:${session.attach!.port}`} className={styles.iframe} />
                    )}
                    <aside className={styles.rail}>
                        <AgentsList agents={session.agents} statusClass={statusClass} />
                    </aside>
                </div>
            ) : (
                <AgentsList agents={session.agents} statusClass={statusClass} />
            )}
        </section>
    );
}

function AgentsList({ agents, statusClass }: { agents: DailySessionAgent[]; statusClass: (status: string) => string }) {
    if (agents.length === 0) {
        return null;
    }

    return (
        <ul className={styles.agents}>
            {agents.map((agent, index) => (
                <li key={index} className={styles.agentRow}>
                    <span className={`${styles.dot} ${statusClass(agent.status)}`} title={agent.status} />
                    <span className={styles.agentTitle}>{agent.title || agent.dir}</span>
                    {agent.client ? (
                        <Link className={styles.agentClient} href={clients.edit(agent.client.id).url} prefetch>
                            {agent.client.name}
                        </Link>
                    ) : (
                        <span className={styles.agentDir}>{agent.dir}</span>
                    )}
                </li>
            ))}
        </ul>
    );
}
