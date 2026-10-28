export function nextPeriod(period: string): string {
    const [year, month] = period.split('-').map(Number);

    return month === 12 ? `${year + 1}-01` : `${year}-${String(month + 1).padStart(2, '0')}`;
}
