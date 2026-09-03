import { getButtonStyles } from '@/components/ui/button';
import { Pagination, PaginationContent, PaginationEllipsis, PaginationItem } from '@/components/ui/pagination';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import React from 'react';
import { useTranslation } from 'react-i18next';
import styles from './inertia-pagination.module.css';

interface InertiaLinkType {
    url: string | null;
    label: string;
    active: boolean;
}

interface InertiaPaginationProps {
    links: InertiaLinkType[];
    className?: string;
    /**
     * Partial-visit options forwarded to every page link. Set these when the
     * paginated list lives inside a tabbed page (e.g. client Aktywność) so a
     * page flip reloads only its prop and keeps the surrounding React state.
     */
    only?: string[];
    preserveState?: boolean;
    preserveScroll?: boolean;
    replace?: boolean;
}

export default function InertiaPagination({ links, className, only, preserveState, preserveScroll, replace }: InertiaPaginationProps) {
    const { t } = useTranslation();
    const visitProps = { only, preserveState, preserveScroll, replace };

    // Hide pagination if there's only one page
    if (links.length <= 3) return null;

    // First link is "Previous", last link is "Next"
    const prevLink = links[0];
    const nextLink = links[links.length - 1];

    const allPageLinks = links.slice(1, -1);

    // Helper to clean HTML entities in labels
    const cleanLabel = (label: string) => {
        const temp = document.createElement('div');
        temp.innerHTML = label;
        return temp.textContent || temp.innerText || label;
    };

    const activeIndex = allPageLinks.findIndex((link) => link.active);
    const totalPages = allPageLinks.length;

    const getMobileVisibility = (index: number) => {
        if (totalPages <= 5) return true; // Show all if 5 or fewer

        const mobileStart = Math.max(0, Math.min(activeIndex - 1, totalPages - 3));
        return index >= mobileStart && index < mobileStart + 3;
    };

    // Shows: 1, 2, ..., current-1, current, current+1, ..., last-1, last
    const getVisiblePageLinks = () => {
        if (totalPages <= 7) {
            return allPageLinks.map((link, index) => ({ ...link, showEllipsisBefore: false, index }));
        }

        const currentPage = activeIndex + 1; // 1-based
        const result: Array<{ link: InertiaLinkType; showEllipsisBefore: boolean; index: number }> = [];

        result.push({ link: allPageLinks[0], showEllipsisBefore: false, index: 0 });

        // Determine the range around current page
        let rangeStart = Math.max(2, currentPage - 1);
        let rangeEnd = Math.min(totalPages - 1, currentPage + 1);

        // Adjust range to always show at least 3 pages in the middle
        if (rangeStart === 2) {
            rangeEnd = Math.min(totalPages - 1, 4);
        }
        if (rangeEnd === totalPages - 1) {
            rangeStart = Math.max(2, totalPages - 3);
        }

        if (rangeStart > 2) {
            result.push({ link: allPageLinks[rangeStart - 1], showEllipsisBefore: true, index: rangeStart - 1 });
            for (let i = rangeStart; i <= rangeEnd; i++) {
                result.push({ link: allPageLinks[i - 1], showEllipsisBefore: false, index: i - 1 });
            }
        } else {
            for (let i = rangeStart; i <= rangeEnd; i++) {
                result.push({ link: allPageLinks[i - 1], showEllipsisBefore: false, index: i - 1 });
            }
        }

        if (rangeEnd < totalPages - 1) {
            result.push({ link: allPageLinks[totalPages - 1], showEllipsisBefore: true, index: totalPages - 1 });
        } else if (rangeEnd < totalPages) {
            result.push({ link: allPageLinks[totalPages - 1], showEllipsisBefore: false, index: totalPages - 1 });
        }

        return result;
    };

    const pageLinks = getVisiblePageLinks();

    return (
        <Pagination className={className}>
            <PaginationContent>
                <PaginationItem>
                    {prevLink.url ? (
                        <Link
                            href={prevLink.url}
                            {...visitProps}
                            className={cn(getButtonStyles({ variant: 'ghost', size: 'default' }), styles.navButton)}
                            aria-label={t('Go to previous page')}
                        >
                            <ChevronLeft className={styles.icon} />
                            <span className={styles.navText}>{t('Previous')}</span>
                        </Link>
                    ) : (
                        <span
                            className={cn(getButtonStyles({ variant: 'ghost', size: 'default' }), styles.navButton, styles.navButtonDisabled)}
                            aria-disabled="true"
                        >
                            <ChevronLeft className={styles.icon} />
                            <span className={styles.navText}>{t('Previous')}</span>
                        </span>
                    )}
                </PaginationItem>

                {pageLinks.map((item) => {
                    const link = 'link' in item ? item.link : item;
                    const showEllipsisBefore = 'showEllipsisBefore' in item ? item.showEllipsisBefore : false;
                    const index = 'index' in item ? item.index : pageLinks.indexOf(item);

                    return (
                        <React.Fragment key={index}>
                            {showEllipsisBefore && (
                                <PaginationItem className={styles.ellipsisItem}>
                                    <PaginationEllipsis />
                                </PaginationItem>
                            )}
                            <PaginationItem className={!getMobileVisibility(index) ? styles.pageItemHidden : undefined}>
                                {link.url ? (
                                    <Link
                                        href={link.url}
                                        {...visitProps}
                                        className={getButtonStyles({
                                            variant: link.active ? 'outline' : 'ghost',
                                            size: 'icon',
                                        })}
                                        aria-current={link.active ? 'page' : undefined}
                                        aria-label={`${t('Page')} ${cleanLabel(link.label)}`}
                                    >
                                        {cleanLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        className={cn(
                                            getButtonStyles({
                                                variant: link.active ? 'outline' : 'ghost',
                                                size: 'icon',
                                            }),
                                            styles.pageDisabled,
                                        )}
                                        aria-disabled="true"
                                        aria-current={link.active ? 'page' : undefined}
                                    >
                                        {cleanLabel(link.label)}
                                    </span>
                                )}
                            </PaginationItem>
                        </React.Fragment>
                    );
                })}

                <PaginationItem>
                    {nextLink.url ? (
                        <Link
                            href={nextLink.url}
                            {...visitProps}
                            className={cn(getButtonStyles({ variant: 'ghost', size: 'default' }), styles.navButton)}
                            aria-label={t('Go to next page')}
                        >
                            <span className={styles.navText}>{t('Next')}</span>
                            <ChevronRight className={styles.icon} />
                        </Link>
                    ) : (
                        <span
                            className={cn(getButtonStyles({ variant: 'ghost', size: 'default' }), styles.navButton, styles.navButtonDisabled)}
                            aria-disabled="true"
                        >
                            <span className={styles.navText}>{t('Next')}</span>
                            <ChevronRight className={styles.icon} />
                        </span>
                    )}
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
