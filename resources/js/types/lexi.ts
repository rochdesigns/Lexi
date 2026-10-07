export type LexiTheme =
    'ocean' | 'forest' | 'plum' | 'clay' | 'slate' | 'garden';

export type LexiNavItem = {
    title: string;
    href: string;
    icon: string; // Material Symbols ligature name
    badge?: number;
};

export type LexiNavSection = {
    /** Two-digit index shown before the label, like a drawing set's section number. */
    code: string;
    label: string;
    items: LexiNavItem[];
};
