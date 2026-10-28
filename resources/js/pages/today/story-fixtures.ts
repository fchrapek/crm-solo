import type { DayTask } from '@/components/solo/task-row';

/** The day's picks: two done, one the timer runs on, two open. */
export const picks: DayTask[] = [
    {
        id: 1,
        task_id: 41,
        name: 'Aktualizacja cennika PDF',
        client: 'Pracownia Mebli',
        project: 'Strona WWW',
        done: true,
        can_untick: true,
        minutes: 52,
    },
    {
        id: 2,
        task_id: 43,
        name: 'Nowa galeria realizacji',
        client: 'Pracownia Mebli',
        project: 'Strona WWW',
        done: true,
        can_untick: false,
        minutes: 68,
    },
    { id: 3, task_id: 44, name: 'Formularz kontaktowy z załącznikami', client: 'Fundacja Testowa', project: 'Serwis', done: false },
    { id: 4, task_id: 45, name: 'Raport miesięczny', client: 'Fundacja Testowa', project: null, done: false },
    {
        id: 5,
        task_id: 46,
        name: 'Korekta faktury 14/09: dane odbiorcy, adres korespondencyjny i numer zamówienia',
        client: 'Pracownia Mebli',
        project: null,
        done: false,
    },
];

export const suggestions: DayTask[] = [
    { task_id: 44, name: 'Formularz kontaktowy z załącznikami', client: 'Fundacja Testowa', project: 'Serwis', done: false },
    { task_id: 47, name: 'Kopia zapasowa przed aktualizacją', client: 'Pracownia Mebli', project: 'Strona WWW', done: false },
    { task_id: 48, name: 'Nowe zdjęcia w galerii kuchni', client: 'Pracownia Mebli', project: 'Strona WWW', done: false },
];
