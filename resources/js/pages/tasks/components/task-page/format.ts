import { durationText, minutesSince, runningMinutesToday, shortDate } from '@/lib/dates';
import type { TFunction } from 'i18next';
import type { ActivityRow, Actor, BriefField, Stamp, TaskTime } from './types';

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

/** A history row's title: what happened in a few words, "Czas: 42 min · Tabele kuchni". */
export function activityTitle(row: ActivityRow, locale: string, t: TFunction): string {
    switch (row.kind) {
        case 'time': {
            const time = t('Time: {{time}}', { time: durationText(row.minutes ?? 0, t) });

            return row.text ? `${time} · ${row.text}` : time;
        }
        case 'note':
            return t('Note: {{text}}', { text: row.text ?? '' });
        case 'brief':
            return t('Brief: {{fields}}', {
                fields: row.fields
                    .filter((f): f is BriefField => f in BRIEF_LABEL)
                    .map((f) => briefFieldLabel(f, t))
                    .join(', '),
            });
        case 'task':
            return t('Task: {{fields}}', {
                fields: row.fields
                    .filter((f) => (TASK_FIELD as readonly string[]).includes(f))
                    .map((f) => t(`task_field_${f}`))
                    .join(', '),
            });
        case 'task_created':
            return t('Task created');
        case 'finished':
            return t('Done in the CRM');
        case 'reopened':
            return t('Reopened in the CRM');
    }
}

/** Who and when at a history row's end: "ty · 10:15", "Codex · 26.10". */
export function activityMeta(row: ActivityRow, now: number, today: string, locale: string, t: TFunction): string {
    const who = row.actor.kind === 'owner' ? t('you') : actorName(row.actor, t);

    return `${who} · ${whenLabel(row, now, today, locale, t)}`;
}

/** "1,2 MB", "48 kB": a file's size as a person reads it. */
export function fileSize(bytes: number, locale: string): string {
    const format = (value: number, digits: number) => new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(value);
    if (bytes >= 1_048_576) return `${format(bytes / 1_048_576, 1)} MB`;
    if (bytes >= 1024) return `${format(bytes / 1024, 0)} kB`;

    return `${bytes} B`;
}
