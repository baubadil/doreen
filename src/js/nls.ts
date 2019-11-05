/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
interface Strings {
    [key: string]: string;
}

declare global {
    const g_nlsStrings: Strings;
}

interface Placeholders {
    [placeholder: string]: string;
}

/**
 *  Check if a string is available.
 */
export const hasString = (key: string): boolean => g_nlsStrings.hasOwnProperty(key);

/**
 *  Get the translation for a specific string. Optionally an object of replacements
 *  can be passed in. The key of the object is used as the placeholder, transformed
 *  to uppercase and enclosed in % and is then replaced by the value. So
 *  { name: 'some name' } would replace "%NAME%" with "some name".
 *
 *  If no translation is found for the given key a warning is logged and the key
 *  is returned instead of the translation.
 *
 *  TODO: handle HTMl escaping of translation strings (with specific opt out?).
 *  TODO: make placeholder replacement a separate function that's available for
 *        non-l10n things.
 */
const _ = (key: string, placeholders?: Placeholders): string => {
    if (!hasString(key)) {
        console.warn("No translation for key", key);
        return key;
    }

    let value = g_nlsStrings[key];
    if (placeholders)
        for (const p in placeholders)
            value = value.replace(`%${p.toUpperCase()}%`, placeholders[p]);

    return value;
};
export default _;

//TODO template tagging function?
