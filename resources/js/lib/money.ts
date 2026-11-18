const scale = 1_000_000_000_000n;
export function money(value: string, precision = 6): string {
    const amount = BigInt(value);
    const negative = amount < 0n;
    const absolute = negative ? -amount : amount;
    const divisor = 10n ** BigInt(12 - precision);
    const rounded = ((absolute + divisor / 2n) / divisor) * divisor;
    const dollars = (rounded / scale).toLocaleString('en-US');
    const fraction = (rounded % scale)
        .toString()
        .padStart(12, '0')
        .slice(0, precision);
    return `${negative ? '-' : ''}$${dollars}${precision ? `.${fraction}` : ''}`;
}
