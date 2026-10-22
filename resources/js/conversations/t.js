/**
 * The words the conversations show, as the server translated them for the reader
 * (`Client::strings()`); a word it did not send is shown as written, with `%s` filled in.
 */
let words = {};

/** The reader's words, once, at mount. */
export function setStrings(strings) {
    words = strings || {};
}

/** A word in the reader's language, each `%s` replaced by the next value. */
export function t(key, ...values) {
    let i = 0;

    return String(words[key] ?? key).replace(/%s/g, () => String(values[i++] ?? ''));
}

/** "1 reply" / "3 replies": the singular key for one, the plural for the rest. */
export function tn(count, one, many) {
    return count === 1 ? t(one, count) : t(many, count);
}

/** A time as the reader's clock has it: `14:05`. */
export function clock(unix) {
    return new Date(unix * 1000).toLocaleTimeString(lang(), { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
}

/** A day line: today, yesterday, or the date in words. */
export function dayLabel(unix) {
    const d = new Date(unix * 1000);
    const today = new Date();
    const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) {
        return t('Today');
    }
    if (d.toDateString() === yesterday.toDateString()) {
        return t('Yesterday');
    }

    return d.toLocaleDateString(lang(), { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
}

/** A date in words: `22 Οκτωβρίου 2026`. */
export function longDate(unix) {
    return new Date(unix * 1000).toLocaleDateString(lang(), { day: 'numeric', month: 'long', year: 'numeric' });
}

/** The page's language. */
function lang() {
    return (typeof document !== 'undefined' && document.documentElement.lang) || undefined;
}
