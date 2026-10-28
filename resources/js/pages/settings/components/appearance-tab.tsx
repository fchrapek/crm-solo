import { BottomSheet } from '@/components/solo/bottom-sheet';
import { ListRow } from '@/components/solo/list-row';
import { SectionHead } from '@/components/solo/primitives';
import { Icon } from '@/components/ui/icon';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { ACCENTS, type Accent, THEMES, type Theme, useAccent, useTheme } from '@/hooks/use-theme';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './tab.module.css';

export const LANGUAGES = [
    { code: 'pl', name: 'Polski' },
    { code: 'en', name: 'English' },
    { code: 'fr', name: 'Français' },
] as const;

export const APPEARANCE_LABEL: Record<Appearance, string> = { light: 'Light', dark: 'Dark', system: 'Follows the system' };

export const THEME_SUB: Record<Theme, string> = { paper: 'Warm paper, poster headings', 'boring-saas': 'A calm panel, as in SaaS apps' };

interface Choice {
    value: string;
    label: string;
    sub?: string;
}

/** Figma: Ustawienia / Wygląd (A2f 663:1553, phones 663:35494); a theme can keep the phone sheet per setting (473:13353). Stored per browser, as before. */
export function AppearanceTab() {
    const { t, i18n } = useTranslation();
    const { appearance, updateAppearance } = useAppearance();
    const { theme, updateTheme } = useTheme();
    const { accent, updateAccent } = useAccent();
    const language = LANGUAGES.find((option) => i18n.language.startsWith(option.code))?.code ?? i18n.language;

    return (
        <section className={styles.section}>
            <SectionHead kind="body" label={t('Appearance')} meta={<span className={styles.wide}>{t('saved in this browser')}</span>} />
            <div className={styles.settings}>
                <ChoiceSetting
                    label={t('Language')}
                    value={language}
                    options={LANGUAGES.map((option) => ({ value: option.code, label: option.name }))}
                    onChange={(code) => void i18n.changeLanguage(code)}
                />
                <ChoiceSetting
                    label={t('Mode')}
                    value={appearance}
                    options={(['light', 'dark', 'system'] as const).map((mode) => ({ value: mode, label: t(APPEARANCE_LABEL[mode]) }))}
                    onChange={(mode) => updateAppearance(mode as Appearance)}
                />
                <ChoiceSetting
                    label={t('Theme')}
                    value={theme}
                    options={THEMES.map((option) => ({ value: option.id, label: option.label, sub: t(THEME_SUB[option.id]) }))}
                    onChange={(value) => updateTheme(value as Theme)}
                />
                <ChoiceSetting
                    label={t('Accent')}
                    value={accent}
                    options={ACCENTS.map((option) => ({ value: option.id, label: option.id === 'default' ? t('Theme default') : option.label }))}
                    onChange={(value) => updateAccent(value as Accent)}
                    wide="select"
                />
            </div>
            <p className={`${styles.note} ${styles.phone}`}>{t('Saved in this browser')}</p>
        </section>
    );
}

/** One setting: caps choices or a pill select from 768 px, a row opening a sheet of the same choices below it. */
function ChoiceSetting({
    label,
    value,
    options,
    onChange,
    wide = 'choices',
}: {
    label: string;
    value: string;
    options: Choice[];
    onChange: (value: string) => void;
    wide?: 'choices' | 'select';
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);

    return (
        <>
            <div className={styles.setting}>
                <p className={styles.settingLabel}>{label}</p>
                {wide === 'select' ? (
                    <Select value={value} onValueChange={onChange}>
                        <SelectTrigger look="solo-pill" aria-label={label}>
                            <SelectValue>{selected?.label}</SelectValue>
                        </SelectTrigger>
                        <SelectContent look="solo-menu" align="start" sideOffset={8}>
                            {options.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : (
                    <div className={styles.choices} role="group" aria-label={label}>
                        {options.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                className={styles.choice}
                                aria-pressed={option.value === value}
                                title={option.sub}
                                onClick={() => onChange(option.value)}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
            <div className={`${styles.phone} ${styles.settingSheet}`}>
                <BottomSheet
                    open={open}
                    onOpenChange={setOpen}
                    title={label}
                    action="done"
                    trigger={<ListRow title={label} value={selected?.label} />}
                >
                    {open && (
                        <ul className={styles.list}>
                            {options.map((option) => {
                                const active = option.value === value;

                                return (
                                    <li key={option.value}>
                                        <ListRow
                                            title={option.label}
                                            sub={option.sub}
                                            trailing={active ? <Icon name="check" size={16} /> : null}
                                            aria-pressed={active}
                                            className={active ? styles.selected : undefined}
                                            onClick={() => {
                                                onChange(option.value);
                                                setOpen(false);
                                            }}
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </BottomSheet>
            </div>
        </>
    );
}
