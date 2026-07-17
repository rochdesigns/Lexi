export type HabitatTheme = 'ocean' | 'forest' | 'plum' | 'clay' | 'slate';

export type HabitatNavItem = {
    title: string;
    href: string;
    icon: string; // Material Symbols ligature name
    badge?: number;
};

export type HabitatNavSection = {
    label: string;
    items: HabitatNavItem[];
};
