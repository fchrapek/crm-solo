import * as Dialog from '@radix-ui/react-dialog';
import type { Meta, StoryObj } from '@storybook/react-vite';
import { useTranslation } from 'react-i18next';
import { HelperPanel } from './panel';
import { helperTopics } from './topics';

function Panel({ page, topic, docs = null, props = {} }: { page: string; topic: string | null; docs?: string | null; props?: Record<string, unknown> }) {
    const { t } = useTranslation();

    return (
        <Dialog.Root open modal={false}>
            <div style={{ width: 360, padding: '12px 8px', borderRadius: 24, background: 'var(--role-tile)', boxShadow: 'var(--role-shadow)' }}>
                <HelperPanel topics={helperTopics(page, props, t)} topic={topic} docs={docs} onTopic={() => undefined} onAction={() => undefined} />
            </div>
        </Dialog.Root>
    );
}

const meta = {
    title: 'Redesign/Helper panel',
    component: Panel,
    args: { page: 'today/index', topic: null },
} satisfies Meta<typeof Panel>;

export default meta;

type Story = StoryObj<typeof meta>;

/** The screen's fixed topics; the docs link stays hidden while no docs site is set. */
export const Topics: Story = {};

/** One answer, its one action and the way back. */
export const Answer: Story = { args: { topic: 'today-picks' } };

/** An answer whose action asks the page to open its own dialog (Log time on a task). */
export const PageDialog: Story = { args: { page: 'tasks/show', topic: 'task-time' } };

/** An answer without an action, with the docs link once the docs are published. */
export const WithDocs: Story = { args: { page: 'clients/show', topic: 'waiting-for-you', docs: 'https://docs.example.com/t/waiting-for-you' } };
