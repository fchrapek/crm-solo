import { getButtonStyles, type ButtonSize, type ButtonVariant } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';
import styles from './anchor-link.module.css';

type LinkType = 'btn' | 'anchor';

interface AnchorLinkProps {
    href: string;
    linkType?: LinkType;
    children: ReactNode;
    className?: string;
    variant?: ButtonVariant;
    size?: ButtonSize;
}

const AnchorLink = ({ href, linkType = 'btn', className, variant = 'default', size = 'default', children }: AnchorLinkProps) => {
    if (linkType === 'anchor') {
        return (
            <Link href={href} className={cn(styles.anchor, className)}>
                {children}
            </Link>
        );
    }

    return (
        <Link href={href} className={getButtonStyles({ variant, size, className })}>
            {children}
        </Link>
    );
};

export default AnchorLink;
