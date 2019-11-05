/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

interface Icons {
    [k: string]: string;
}

declare global {
    const g_aDrnIcons: Icons;
}

/**
 *  Get the icon with the given name including HTML markup.
 *
 *  Prints a warning in the console and returns an empty string when no icon
 *  with the given name is found.
 */
const getIcon = (name: string): string => {
    if (!g_aDrnIcons.hasOwnProperty(name))
    {
        console.warn('Could not find icon for', name);
        return '';
    }
    return g_aDrnIcons[name];
}

export default getIcon;
