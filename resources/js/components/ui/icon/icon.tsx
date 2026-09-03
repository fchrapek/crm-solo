import { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

import styles from './icon.module.css';

interface IconProps {
    iconNode?: LucideIcon | null;
    className?: string;
}

export function Icon({ iconNode: IconComponent, className }: IconProps) {
    if (!IconComponent) {
        return null;
    }

    return <IconComponent className={cn(styles.icon, className)} />;
}
