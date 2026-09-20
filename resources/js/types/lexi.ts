export type LexiTheme =
    'ocean' | 'forest' | 'plum' | 'clay' | 'slate' | 'garden';

export type LexiNavItem = {
    title: string;
    href: string;
    icon: string; // Material Symbols ligature name
    badge?: number;
};

export type LexiNavSection = {
    label: string;
    items: LexiNavItem[];
};
