/**
 * Evaluate a simple arithmetic expression string (e.g. "100+50*2").
 * Only allows digits, operators (+−×÷), dots, and parentheses — no function calls.
 * Returns null if the expression is invalid or empty.
 */
export function evaluateArithmetic(expr: string | number): number | null {
    // Currency symbols and thousands separators are how people actually write
    // amounts — "₱1,200" and "1,200" should both land on 1200 rather than being
    // thrown away. Stripping them here cannot widen what the allowlist below
    // permits, since neither character can form an identifier or a call.
    const cleaned = String(expr).replace(/[\s,₱$€£]/g, '');

    if (!cleaned) {
        return null;
    }

    if (!/^[\d+\-*/().]+$/.test(cleaned)) {
        return null;
    }

    try {
        const result = new Function(`return (${cleaned})`)() as unknown;

        if (typeof result === 'number' && isFinite(result)) {
            return Math.round(result * 100) / 100;
        }

        return null;
    } catch {
        return null;
    }
}

/**
 * Returns true when the string contains arithmetic operators (not just a plain number).
 * A leading minus on a negative number (e.g. "-500") is NOT an operator.
 */
export function hasArithmetic(expr: string | number): boolean {
    const s = String(expr).trim();

    return /[+*/]/.test(s) || /\d-/.test(s);
}
