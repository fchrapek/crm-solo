import type { Meta, StoryObj } from '@storybook/react-vite';
import { TaskFormDialog } from './task-form-dialog';

const DESKTOP = { viewport: { value: 'desktop', isRotated: false } };

/** Figma: Nowe zadanie. sheet is on for the Solo pages: phones get the Cancel · Save sheet, from 768 px the dialog. */
const meta = {
    title: 'Zadania/Nowe zadanie',
    component: TaskFormDialog,
    args: {
        open: true,
        onOpenChange: () => {},
        mode: 'create',
        projectId: 7,
        sheet: true,
        parentOptions: [{ id: 41, name: 'Sezon jesienny na stronie' }],
    },
} satisfies Meta<typeof TaskFormDialog>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Sheet: Story = {};

export const Dialog: Story = { globals: DESKTOP };

/** From a page that spans projects: Projekt comes first. */
export const PicksAProject: Story = {
    args: {
        projectId: undefined,
        projects: [
            { id: 7, name: 'Strona WWW' },
            { id: 8, name: 'Sklep internetowy' },
        ],
    },
    globals: DESKTOP,
};

export const Edit: Story = {
    args: {
        mode: 'edit',
        initial: {
            id: 42,
            name: 'Aktualizacja cennika PDF',
            description: 'Nowe ceny są w arkuszu. Układ tabel zostaje.',
            list_name: 'Doing',
            due_date: '2026-10-28',
            priority: 'medium',
            parent_task_id: 41,
            is_reportable: true,
        },
    },
    globals: DESKTOP,
};

/** Outside the Solo pages the dialog stays on phones too. */
export const DialogOnPhone: Story = { args: { sheet: false } };
