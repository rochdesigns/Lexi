/**
 * The one content width every blueprint page uses: 1120px of content, with a
 * gutter that stays on at every size so text never touches the sidebar.
 */
export const sheetWidth = {
    wide: 'mx-auto w-full max-w-[1168px] px-4 sm:px-6',
} as const;

/** Form fields on the blueprint pages and in their dialogs. */
export const formField =
    'w-full rounded-[3px] border border-[var(--bp-line)] bg-[var(--bp-card)] px-3 py-2 text-[14px] text-[var(--bp-ink)] outline-none placeholder:text-[var(--bp-faint)] focus:border-[var(--bp-ink)] focus:shadow-[0_0_0_3px_var(--bp-tint)] disabled:opacity-60';

/** A field's label: small mono caps, like the sheet's other captions. */
export const formLabel =
    'bp-mono mb-1.5 block text-[10px] tracking-[0.12em] text-[var(--bp-muted)] uppercase';

export const formError = 'mt-1 text-[12px] text-[#a3311f]';
