import { Monitor, Moon, Sun } from 'lucide-react';

import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarGroup, SidebarGroupContent, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

import styles from './nav-footer.module.css';

export function NavFooter() {
    const { appearance, updateAppearance } = useAppearance();
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    // Only light/dark lives here now: it is a daily toggle. Language, theme and
    // accent are set-once preferences and live in Settings > Display.
    const tabs = [
        { value: 'light' as Appearance, icon: Sun },
        { value: 'dark' as Appearance, icon: Moon },
        { value: 'system' as Appearance, icon: Monitor },
    ];

    return (
        <SidebarGroup className={styles.group}>
            <SidebarGroupContent>
                <SidebarMenu>
                    <SidebarMenuItem>
                        {state === 'collapsed' && !isMobile ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <SidebarMenuButton className={styles.menuButton}>
                                        {appearance === 'light' && <Sun className={styles.icon} />}
                                        {appearance === 'dark' && <Moon className={styles.icon} />}
                                        {appearance === 'system' && <Monitor className={styles.icon} />}
                                    </SidebarMenuButton>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent className={styles.dropdownContentTheme} align="center" side={isMobile ? 'top' : 'right'}>
                                    {tabs.map(({ value, icon: Icon }) => (
                                        <DropdownMenuItem key={value} onClick={() => updateAppearance(value)}>
                                            <Icon className={styles.dropdownIcon} />
                                            <span>{value.charAt(0).toUpperCase() + value.slice(1)}</span>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : (
                            <div className={styles.themeToggle}>
                                {tabs.map(({ value, icon: Icon }) => (
                                    <button
                                        key={value}
                                        onClick={() => updateAppearance(value)}
                                        className={cn(styles.themeButton, appearance === value && styles.themeButtonActive)}
                                    >
                                        <Icon className={styles.themeButtonIcon} />
                                    </button>
                                ))}
                            </div>
                        )}
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
