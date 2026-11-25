import { plain } from '@/components/solo/command-palette';
import { SoloInput } from '@/components/solo/field';
import { pillClass } from '@/components/solo/shell/pills';
import { Icon } from '@/components/ui/icon';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { type KeyboardEvent, type ReactNode, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './add-or-pick.module.css';

export interface ProjectChoice {
    id: number;
    name: string;
    client_id: number | null;
    client: string | null;
}

export interface ProjectChoices {
    /** The projects worked on last, newest first. */
    recent: number[];
    /** Every live project, by client then name. */
    projects: ProjectChoice[];
}

/** Where a new task goes, as the pill and the toast name it. */
export const projectLabel = (project: Pick<ProjectChoice, 'name' | 'client'>) => [project.client, project.name].filter(Boolean).join(' · ');

interface Group {
    key: string;
    head: string;
    /** Rows that name their client: the recent ones, which mix clients. */
    named: boolean;
    projects: ProjectChoice[];
}

/** Recent projects first while nothing is typed, then every client as a heading over its projects; typed words narrow both. */
export function projectGroups(choices: ProjectChoices, query: string, labels: { recent: string; noClient: string }): Group[] {
    const words = plain(query).split(/\s+/).filter(Boolean);
    const matches = (project: ProjectChoice) => words.every((word) => plain(`${project.client ?? ''} ${project.name}`).includes(word));
    const shown = choices.projects.filter(matches);
    const byClient = new Map<string, ProjectChoice[]>();
    for (const project of shown) {
        const key = project.client_id === null ? 'none' : String(project.client_id);
        byClient.set(key, [...(byClient.get(key) ?? []), project]);
    }
    const recent =
        words.length > 0
            ? []
            : choices.recent
                  .map((id) => choices.projects.find((project) => project.id === id))
                  .filter((project): project is ProjectChoice => project !== undefined);

    return [
        ...(recent.length > 0 ? [{ key: 'recent', head: labels.recent, named: true, projects: recent }] : []),
        ...Array.from(byClient, ([key, projects]) => ({ key, head: projects[0].client ?? labels.noClient, named: false, projects })),
    ];
}

/**
 * Figma: 1F, picking a project (1412:23165). The Goes to pill and its search: recent projects, then the clients
 * as quiet headings over their projects. A popover from 768 px; in the phone sheet the list opens under the pill.
 */
export function ProjectPicker({
    choices,
    value,
    onChange,
    open,
    onOpenChange,
    inline,
}: {
    choices: ProjectChoices | null;
    value: number | null;
    onChange: (projectId: number) => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The phone sheet: the list opens in place instead of a popover. */
    inline: boolean;
}) {
    const { t } = useTranslation();
    const chosen = choices?.projects.find((project) => project.id === value);
    const trigger = (
        <button
            type="button"
            className={`${pillClass} ${styles.targetPill}`}
            aria-expanded={open}
            onClick={inline ? () => onOpenChange(!open) : undefined}
        >
            <span className={styles.targetLabel}>{chosen ? projectLabel(chosen) : t('Choose a project')}</span>
            <Icon name="chevron-down" size={16} className={styles.chevron} />
        </button>
    );
    const body = open && choices !== null && (
        <PickerBody
            choices={choices}
            value={value}
            onChoose={(id) => {
                onChange(id);
                onOpenChange(false);
            }}
            onClose={() => onOpenChange(false)}
        />
    );

    if (inline) {
        return (
            <>
                {trigger}
                {body && <div className={styles.pickerInline}>{body}</div>}
            </>
        );
    }

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent look="solo-menu" align="start" sideOffset={8} className={styles.picker} aria-label={t('Choose a project')}>
                {body}
            </PopoverContent>
        </Popover>
    );
}

function PickerBody({
    choices,
    value,
    onChoose,
    onClose,
}: {
    choices: ProjectChoices;
    value: number | null;
    onChoose: (id: number) => void;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const id = useId();
    const [query, setQuery] = useState('');
    const groups = projectGroups(choices, query, { recent: t('Recent projects'), noClient: t('No client') });
    const rows = groups.flatMap((group) => group.projects.map((project) => ({ group: group.key, project })));
    const [active, setActive] = useState(0);
    const at = Math.min(active, Math.max(rows.length - 1, 0));
    const rowId = (index: number) => `${id}-project-${index}`;

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (rows.length === 0) return;
            const next = (at + (event.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
            setActive(next);
            document.getElementById(rowId(next))?.scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (rows[at]) onChoose(rows[at].project.id);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            onClose();
        }
    }

    const starts = groups.map((_, n) => groups.slice(0, n).reduce((sum, group) => sum + group.projects.length, 0));
    const option = (project: ProjectChoice, named: boolean, mine: number): ReactNode => (
        <div
            key={`${named ? 'r' : 'c'}-${project.id}`}
            id={rowId(mine)}
            role="option"
            aria-selected={project.id === value}
            data-highlighted={mine === at ? '' : undefined}
            data-chosen={project.id === value ? '' : undefined}
            className={[styles.option, styles.pickerOption, named ? '' : styles.nested].filter(Boolean).join(' ')}
            onMouseMove={() => setActive(mine)}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onChoose(project.id)}
        >
            <span className={styles.optionTitle}>{named ? projectLabel(project) : project.name}</span>
            {project.id === value && <Icon name="check" size={16} />}
        </div>
    );

    return (
        <div className={styles.pickerBody}>
            <SoloInput
                type="search"
                value={query}
                placeholder={t('Search for a project')}
                aria-label={t('Search for a project')}
                role="combobox"
                aria-expanded="true"
                aria-controls={`${id}-list`}
                aria-activedescendant={rows[at] ? rowId(at) : undefined}
                autoComplete="off"
                autoFocus
                className={styles.pickerSearch}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActive(0);
                }}
                onKeyDown={onKeyDown}
            />
            <div id={`${id}-list`} role="listbox" aria-label={t('Projects')} className={styles.pickerList}>
                {groups.map((group, groupIndex) => (
                    <div key={group.key} role="group" aria-labelledby={`${id}-${group.key}`}>
                        <p id={`${id}-${group.key}`} className={styles.head}>
                            {group.head}
                        </p>
                        {group.projects.map((project, offset) => option(project, group.named, starts[groupIndex] + offset))}
                    </div>
                ))}
                {rows.length === 0 && <p className={styles.empty}>{t('No project matches.')}</p>}
            </div>
        </div>
    );
}
