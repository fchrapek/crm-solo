import { Tile, TileHeader } from '@/components/solo/tile';
import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from 'react-i18next';
import styles from './tab.module.css';

export const LANGUAGES = [
    { code: 'pl', name: 'Polski' },
    { code: 'en', name: 'English' },
    { code: 'fr', name: 'Français' },
] as const;

export const APPEARANCE_LABEL: Record<Appearance, string> = { light: 'Light', dark: 'Dark', system: 'Follows the system' };

interface Choice {
    value: string;
    label: string;
}

/**
 * Figma: Ustawienia · Wygląd (1090:10488, phone 1090:10668). Stored per browser. The accent has no control: the
 * redesign fixes cobalt and vermilion by role, so the stored accent (use-theme) recolours nothing on these screens.
 */
export function AppearanceTab() {
    const { t, i18n } = useTranslation();
    const { appearance, updateAppearance } = useAppearance();
    const language = LANGUAGES.find((option) => i18n.language.startsWith(option.code))?.code ?? i18n.language;

    return (
        <Tile kind="plain">
            <TileHeader label={t('Appearance')} end={t('saved in this browser')} />
            <div className={styles.settings} data-fill="">
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
            </div>
        </Tile>
    );
}

/** Figma: Options (track). One setting: its choices as pills on a page-grey track, under the label on a phone. */
function ChoiceSetting({ label, value, options, onChange }: { label: string; value: string; options: Choice[]; onChange: (value: string) => void }) {
    return (
        <div className={styles.setting}>
            <p className={styles.settingLabel}>{label}</p>
            <div className={styles.choices} role="group" aria-label={label}>
                {options.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        className={styles.choice}
                        aria-pressed={option.value === value}
                        onClick={() => onChange(option.value)}
                    >
                        {option.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
