import React from 'react';
import { useTranslation } from 'react-i18next';

import styles from './demo-cli-terminal.module.css';

interface TerminalLine {
    kind: 'cmd' | 'out';
    text: string;
}

const WELCOME = [
    'CRM Solo demo terminal - the same crm verbs the agents speak.',
    'Type "help" to list them. The database resets nightly, so play freely.',
].join('\n');

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

/**
 * Terminal-look prompt for the hosted demo. Visually a terminal; technically
 * an input that POSTs each line to /demo/cli, where a closed whitelist of crm
 * verbs runs in-process - no PTY, no shell, nothing to break out of.
 */
export function DemoCliTerminal() {
    const { t } = useTranslation();
    const [lines, setLines] = React.useState<TerminalLine[]>([{ kind: 'out', text: WELCOME }]);
    const [input, setInput] = React.useState('');
    const [busy, setBusy] = React.useState(false);
    const [history, setHistory] = React.useState<string[]>([]);
    const [historyCursor, setHistoryCursor] = React.useState(-1);
    const scrollRef = React.useRef<HTMLDivElement>(null);
    const inputRef = React.useRef<HTMLInputElement>(null);

    React.useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
    }, [lines]);

    const run = (raw: string) => {
        const command = raw.trim();
        if (!command || busy) return;

        setLines((prev) => [...prev, { kind: 'cmd', text: command }]);
        setHistory((prev) => [command, ...prev]);
        setHistoryCursor(-1);
        setInput('');
        setBusy(true);

        fetch('/demo/cli', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ command }),
        })
            .then(async (res) => {
                const data = (await res.json().catch(() => null)) as { output?: string } | null;
                const text = res.status === 429 ? t('Slow down - too many commands.') : (data?.output ?? t('Command failed.'));
                setLines((prev) => [...prev, { kind: 'out', text }]);
            })
            .catch(() => setLines((prev) => [...prev, { kind: 'out', text: t('Command failed.') }]))
            .finally(() => setBusy(false));
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter') {
            run(input);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            const next = Math.min(historyCursor + 1, history.length - 1);
            if (next >= 0 && history[next] !== undefined) {
                setHistoryCursor(next);
                setInput(history[next]);
            }
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            const next = historyCursor - 1;
            setHistoryCursor(next);
            setInput(next >= 0 ? (history[next] ?? '') : '');
        }
    };

    return (
        <div className={styles.terminal} onClick={() => inputRef.current?.focus()}>
            <div ref={scrollRef} className={styles.scroll}>
                {lines.map((line, index) => (
                    <pre key={index} className={line.kind === 'cmd' ? styles.cmdLine : styles.outLine}>
                        {line.kind === 'cmd' ? `$ ${line.text}` : line.text}
                    </pre>
                ))}
            </div>
            <div className={styles.inputRow}>
                <span className={styles.prompt}>$</span>
                {/* Never disabled: disabling a focused input drops focus, and
                    a terminal must keep the caret. run() ignores Enter while
                    a command is in flight. */}
                <input
                    ref={inputRef}
                    className={styles.input}
                    value={input}
                    onChange={(event) => setInput(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder="crm today"
                    spellCheck={false}
                    autoComplete="off"
                    aria-label={t('Demo terminal command')}
                />
            </div>
        </div>
    );
}
