import { format, parseISO } from 'date-fns';

export function parseAnyDate(dateStr: string | null | undefined): Date {
    if (!dateStr) return new Date(NaN);
    
    // Clean string: remove extra spaces and standardize MySQL/ISO
    const cleanStr = String(dateStr).trim().replace(' ', 'T');
    
    // First try parseISO from date-fns
    let date = parseISO(cleanStr);
    
    // Fallback to native Date if parseISO fails
    if (isNaN(date.getTime())) {
        date = new Date(cleanStr);
    }
    
    // Last ditch effort for weird formats
    if (isNaN(date.getTime()) && !cleanStr.includes('T')) {
        // Might be "2024-03-14 21:00:00" without T replacement working for some reason
        date = new Date(cleanStr.replace(/-/g, '/'));
    }
    
    return date;
}

/**
 * Parses "HH:mm:ss" or "HH:mm" into total minutes from start of day
 */
export function getTimeMinutes(timeStr: string | null | undefined): number {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    if (parts.length < 2) return 0;
    
    const h = parseInt(parts[0], 10) || 0;
    const m = parseInt(parts[1], 10) || 0;
    return (h * 60) + m;
}

export function formatSafe(dateStr: string | null | undefined, formatStr: string, fallback = '...'): string {
    if (!dateStr) return fallback;
    try {
        const date = parseAnyDate(dateStr);
        if (isNaN(date.getTime())) return fallback;
        return format(date, formatStr);
    } catch (e) {
        return fallback;
    }
}
