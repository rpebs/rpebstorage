export function formatBytes(
    bytes: number | null | undefined,
    precision = 1,
): string {
    if (bytes === null || bytes === undefined) {
        return '-';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : precision)} ${units[unit]}`;
}
