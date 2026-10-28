import { ToggleGroup, ToggleGroupItem, segmentedLinkClasses } from '@/components/ui/toggle-group';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';

interface SegmentedOption<V extends string> {
    value: V;
    label: ReactNode;
    /** With an href on every option the control navigates: links in a nav, the current one marked by aria-current. */
    href?: InertiaLinkProps['href'];
    /** Width=Full: this segment hugs its label while the others share the rest. */
    hug?: boolean;
}

interface SegmentedControlProps<V extends string> {
    label: string;
    value: V | undefined;
    onChange?: (value: V) => void;
    options: ReadonlyArray<SegmentedOption<V>>;
    /** full: the edged track across the column (Figma Width=Full, the phone day trail). */
    width?: 'hug' | 'full';
    /** aria-current for the selected link. */
    current?: 'page' | 'step';
    /** trail: from 768 px the links read as a caps text trail with arrows between them (the day trail). */
    wide?: 'trail';
    className?: string;
}

/** Figma: A2f / Segmented control. Pressing the active segment keeps it on. */
export function SegmentedControl<V extends string>({
    label,
    value,
    onChange,
    options,
    width = 'hug',
    current = 'page',
    wide,
    className,
}: SegmentedControlProps<V>) {
    if (options.every((option) => option.href !== undefined)) {
        return (
            <nav aria-label={label} data-width={width} data-wide={wide} className={[segmentedLinkClasses.group, className].filter(Boolean).join(' ')}>
                {options.map((option) => (
                    <Link
                        key={option.value}
                        href={option.href!}
                        className={segmentedLinkClasses.item}
                        data-hug={option.hug || undefined}
                        aria-current={option.value === value ? current : undefined}
                    >
                        {option.label}
                    </Link>
                ))}
            </nav>
        );
    }

    return (
        <ToggleGroup
            type="single"
            look="segmented"
            aria-label={label}
            data-width={width}
            value={value ?? ''}
            onValueChange={(next) => next !== '' && onChange?.(next as V)}
            className={className}
        >
            {options.map((option) => (
                <ToggleGroupItem key={option.value} value={option.value} data-hug={option.hug || undefined}>
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
