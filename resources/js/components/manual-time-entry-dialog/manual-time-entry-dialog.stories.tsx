import type { Meta, StoryObj } from '@storybook/react-vite';
import { ManualTimeEntryDialog, type RunningEntry, RunningWarning } from './manual-time-entry-dialog';

const DESKTOP = { viewport: { value: 'desktop', isRotated: false } };

const running: RunningEntry[] = [
    { id: 9, description: null, start_time: '2026-10-23T09:14:00Z', source: 'manual', task: { id: 43, name: 'Nowa galeria realizacji' } },
];

/** Answers the dialog's /time-entries/running check: the entries, or never (the checking step). */
function stubRunning(entries: RunningEntry[] | 'pending') {
    return () => {
        const real = globalThis.fetch;
        globalThis.fetch = ((input: RequestInfo | URL, init?: RequestInit) => {
            const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
            if (!url.includes('/time-entries/running')) return real(input, init);
            if (entries === 'pending') return new Promise<Response>(() => {});

            return Promise.resolve(new Response(JSON.stringify({ entries }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }) as typeof fetch;

        return () => {
            globalThis.fetch = real;
        };
    };
}

/** Figma: Zaloguj czas. Phones open the sheet with the Cancel · Save header, from 768 px the dialog. */
const meta = {
    title: 'Zadania/Zaloguj czas',
    component: ManualTimeEntryDialog,
    args: { open: true, onOpenChange: () => {}, task: { id: 42, name: 'Aktualizacja cennika PDF', client: 'Pracownia Mebli' } },
    beforeEach: stubRunning([]),
} satisfies Meta<typeof ManualTimeEntryDialog>;

export default meta;

type Story = StoryObj<typeof meta>;

/** The form in the sheet: Anuluj and Zapisz in the header, the subtitle under the title. */
export const FormSheet: Story = {};

export const FormDialog: Story = { globals: DESKTOP };

/** A timer runs elsewhere: the warning comes first, Kontynuuj mimo to opens the form. */
export const WarningSheet: Story = { beforeEach: stubRunning(running) };

export const WarningDialog: Story = { beforeEach: stubRunning(running), globals: DESKTOP };

/** While /time-entries/running answers. */
export const Checking: Story = { beforeEach: stubRunning('pending'), globals: DESKTOP };

export const WithoutClient: Story = { args: { task: { id: 42, name: 'Aktualizacja cennika PDF', client: null } }, globals: DESKTOP };

/** The warning's body alone, two timers running. */
export const RunningWarningBody: Story = {
    render: () => (
        <div style={{ maxWidth: 472 }}>
            <RunningWarning
                running={[
                    ...running,
                    { id: 10, description: 'Rozmowa o ofercie', start_time: '2026-10-23T10:02:00Z', source: 'terminal_session', task: null },
                ]}
            />
        </div>
    ),
};
