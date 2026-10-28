import { durationText, minutesSince, runningMinutesToday, shortDate } from '@/lib/dates';
import type { TFunction } from 'i18next';
import type { ActivityRow, Actor, BriefField, Stamp, TaskResources, TaskTime } from './types';

const HOUR_MS = 60 * 60_000;

const SKEW_MS = 5 * 60_000;

/** "12 min temu" inside the last hour, the local time later today, the date before that. */
export function whenLabel(stamp: Stamp, now: number, today: string, locale: string, t: TFunction): string {
    const { at } = stamp;
    const age = now - new Date(at).getTime();
    // The page's clock ticks every few seconds, so a write it just reloaded can be a little ahead of it.
    if (age > -SKEW_MS && age < HOUR_MS) {
        const minutes = Math.max(0, Math.floor(age / 60_000));

        return minutes < 1 ? t('just now') : t('{{minutes}} min ago', { minutes });
    }
    if (stamp.day === today) {
        return stamp.time;
    }

    return shortDate(stamp.day, locale, Number(today.slice(0, 4)));
}

/**
 * Today's and all-time minutes with each running timer's time so far. An
 * entry counts on the day it started, running or stopped, as reports count it.
 */
export function liveMinutes(time: TaskTime, now: number): { today: number; total: number } {
    return {
        today: time.today_minutes + runningMinutesToday(time.day_start, time.running, now),
        total: time.running.reduce((sum, started) => sum + minutesSince(started, now), time.total_minutes),
    };
}

export function actorName(actor: Actor, t: TFunction): string {
    if (actor.kind === 'owner') return t('You');

    return actor.name ?? t('Person');
}

const BRIEF_LABEL: Record<BriefField, string> = { where: 'Where', done_when: 'Done when', constraints: 'Constraints', notes: 'Notes' };

export function briefFieldLabel(field: BriefField, t: TFunction): string {
    return t(BRIEF_LABEL[field]);
}

const TASK_FIELD = ['name', 'description', 'due', 'list', 'project', 'archive', 'priority', 'parent', 'reportable'] as const;

function joined(items: string[], locale: string): string {
    return new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(items);
}

/** One sentence for an Aktywność row: the agent's in the third person, the owner's addressed to them. */
export function activitySentence(row: ActivityRow, locale: string, t: TFunction): string {
    const own = row.actor.kind === 'owner';

    switch (row.kind) {
        case 'time': {
            const time = durationText(row.minutes ?? 0, t);
            if (row.text) {
                return own
                    ? t('You logged {{time}} of work: {{text}}', { time, text: row.text })
                    : t('Logged {{time}} of work: {{text}}', { time, text: row.text });
            }

            return own ? t('You logged {{time}} of work.', { time }) : t('Logged {{time}} of work.', { time });
        }
        case 'note':
            return t('Note: {{text}}', { text: row.text ?? '' });
        case 'brief': {
            const fields = row.fields.filter((f): f is BriefField => f in BRIEF_LABEL).map((f) => briefFieldLabel(f, t));

            return t('Changed the brief: {{fields}}.', { count: fields.length, fields: joined(fields, locale) });
        }
        case 'task': {
            const fields = row.fields.filter((f) => (TASK_FIELD as readonly string[]).includes(f)).map((f) => t(`task_field_${f}`));

            return t('Changed the task: {{fields}}.', { fields: joined(fields, locale) });
        }
        case 'task_created':
            return t('Created the task.');
        case 'finished':
            return own ? t('You marked it done in the CRM.') : t('Marked it done in the CRM.');
        case 'reopened':
            return own ? t('You reopened it in the CRM.') : t('Reopened it in the CRM.');
    }
}

/** The sentence as the tail of a one-line row: "12 min temu · Codex · zmienił pola briefu". */
export function activityLine(row: ActivityRow, locale: string, t: TFunction): string {
    const sentence = activitySentence(row, locale, t).replace(/\.$/, '');

    return sentence.charAt(0).toLocaleLowerCase(locale) + sentence.slice(1);
}

/** "6 · 2 pliki, 2 obrazy, 2 linki"; only the kinds there are. */
export function resourcesSummary(resources: TaskResources, t: TFunction): string {
    const parts = [
        resources.files.length > 0 ? t('{{count}} files', { count: resources.files.length }) : null,
        resources.images.length > 0 ? t('{{count}} images', { count: resources.images.length }) : null,
        resources.links.length > 0 ? t('{{count}} links', { count: resources.links.length }) : null,
    ].filter((part): part is string => part !== null);
    const total = resources.files.length + resources.images.length + resources.links.length;

    return total === 0 ? t('none yet') : `${total} · ${parts.join(', ')}`;
}

/** About four lines on a phone; a longer description is clamped there behind Więcej. */
const LONG_CHARS = 200;
const LONG_LINES = 4;

export function isLongDescription(markdown: string): boolean {
    return markdown.length > LONG_CHARS || markdown.split('\n').filter((line) => line.trim() !== '').length > LONG_LINES;
}

/** "do 15.10": the due day read off its date string, the year only when it is not this one. */
export function dueText(day: string, today: string, locale: string, t: TFunction): string {
    return t('due {{date}}', { date: shortDate(day, locale, Number(today.slice(0, 4))) });
}

/** What "Kopiuj dla agenta" puts on the clipboard: the task's id and the verb that opens it. */
export function agentPrompt(taskId: number, t: TFunction): string {
    return t('Work on task {{id}} from CRM Solo (crm task {{id}})', { id: taskId });
}
