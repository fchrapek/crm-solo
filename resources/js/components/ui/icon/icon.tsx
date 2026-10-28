import {
    Archive,
    ArchiveRestore,
    ArrowLeft,
    ArrowRight,
    Bot,
    Building2,
    Calendar,
    ChartColumn,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Circle,
    CircleCheck,
    Contact,
    Copy,
    Download,
    Ellipsis,
    EllipsisVertical,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    FolderKanban,
    Funnel,
    History,
    Info,
    LayoutGrid,
    Link2,
    LoaderCircle,
    Lock,
    Minus,
    Paperclip,
    Pencil,
    Phone,
    Pin,
    Play,
    Plug,
    Plus,
    Receipt,
    RefreshCw,
    RotateCcw,
    Save,
    Scale,
    Search,
    Send,
    Settings,
    Sparkles,
    Square,
    SquareCheckBig,
    StickyNote,
    Timer,
    Trash2,
    TriangleAlert,
    Unlink,
    Upload,
    UserPlus,
    X,
} from 'lucide-react';
import type { ComponentType, HTMLAttributes, SVGProps } from 'react';

import { cn } from '@/lib/utils';

import { FLUENT } from './fluent';

import styles from './icon.module.css';

type Glyph = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>;

/** Boring SaaS draws Lucide; Paper draws the Fluent paths in ./fluent (scripts/fluent-icons.mjs). */
const LUCIDE = {
    check: Check,
    close: X,
    'chevron-left': ChevronLeft,
    'chevron-right': ChevronRight,
    'chevron-down': ChevronDown,
    'chevron-up': ChevronUp,
    plus: Plus,
    minus: Minus,
    more: Ellipsis,
    search: Search,
    copy: Copy,
    pencil: Pencil,
    play: Play,
    stop: Square,
    'arrow-left': ArrowLeft,
    'arrow-right': ArrowRight,
    'external-link': ExternalLink,
    'more-vertical': EllipsisVertical,
    trash: Trash2,
    eye: Eye,
    'eye-off': EyeOff,
    download: Download,
    upload: Upload,
    lock: Lock,
    link: Link2,
    unlink: Unlink,
    refresh: RefreshCw,
    undo: RotateCcw,
    save: Save,
    history: History,
    send: Send,
    done: CircleCheck,
    circle: Circle,
    restore: ArchiveRestore,
    loader: LoaderCircle,
    info: Info,
    warning: TriangleAlert,
    pin: Pin,
    client: Building2,
    contacts: Contact,
    lead: UserPlus,
    task: SquareCheckBig,
    timer: Timer,
    calendar: Calendar,
    filter: Funnel,
    note: StickyNote,
    file: FileText,
    attachment: Paperclip,
    invoice: Receipt,
    report: ChartColumn,
    settings: Settings,
    agent: Bot,
    integration: Plug,
    phone: Phone,
    ai: Sparkles,
    dashboard: LayoutGrid,
    project: FolderKanban,
    archive: Archive,
    hours: Scale,
} satisfies Record<keyof typeof FLUENT, Glyph>;

export type IconName = keyof typeof LUCIDE;
/** 16 inline and in table rows, 20 in buttons and list items, 24 in navigation and the tab bar. */
export type IconSize = 16 | 20 | 24;

export const ICON_NAMES = Object.keys(LUCIDE) as IconName[];

interface IconProps extends Omit<HTMLAttributes<HTMLSpanElement>, 'children'> {
    name: IconName;
    size?: IconSize;
    /** A filled glyph, such as a pinned pin or the play and stop of a timer. */
    filled?: boolean;
}

/** Both theme glyphs render and the theme's CSS shows one, so the first paint and a theme switch need no state. Decorative unless given a label. */
export function Icon({ name, size = 20, filled = false, className, ...rest }: IconProps) {
    const labelled = rest['aria-label'] !== undefined || rest['aria-labelledby'] !== undefined;
    const Saas: Glyph = LUCIDE[name];
    const paper: { regular: Record<IconSize, string[]>; filled?: Record<IconSize, string[]> } = FLUENT[name];

    return (
        <span
            aria-hidden={labelled ? undefined : true}
            role={labelled ? 'img' : undefined}
            {...rest}
            className={cn(styles.icon, styles[`size${size}`], className)}
            data-icon={name}
        >
            <svg className={styles.paper} width={size} height={size} viewBox={`0 0 ${size} ${size}`} fill="currentColor" aria-hidden="true">
                {((filled && paper.filled) || paper.regular)[size].map((d) => (
                    <path key={d} d={d} />
                ))}
            </svg>
            <Saas className={styles.saas} size={size} fill={filled ? 'currentColor' : 'none'} />
        </span>
    );
}
