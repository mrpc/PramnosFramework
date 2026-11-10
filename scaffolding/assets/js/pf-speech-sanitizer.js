/**
 * Prepares Ask AI's answers for natural speech synthesis (TTS), and reads the user's spoken
 * interruptions back out of what the microphone heard while the assistant was talking.
 *
 * Two problems this exists to fix:
 * 1. A Greek voice (el-GR) spells English/Latin words out letter by letter ("A-I",
 *    "W-O-R-D-P-R-E-S-S") instead of pronouncing them — `transliterateLatinWord()` turns any
 *    Latin word TTS would otherwise spell into Greek phonetics.
 * 2. Emoji and smileys are read aloud by their accessibility label ("χαμογελαστό πρόσωπο") —
 *    `stripEmojisAndSymbols()` removes them before anything is spoken.
 *
 * Grammatical gender and the assistant's own name are an application's content, not this
 * script's: `getAssistantTitle()`/`getAssistantSelfIntroduction()` take them as arguments, and
 * `addGenderPhrases()`/`addTransliteration()` let an application register its own assistant's
 * stock phrases and domain vocabulary (product names, metric/jargon terms) instead of this file
 * carrying one application's.
 *
 * `window.PfSpeechSanitizer` — loaded before `pf-voice.js`, which calls into it.
 */
(function () {
    'use strict';

    var ENGLISH_TO_GREEK_MAP = [
        // Technical acronyms and words common enough to belong to every application, not one's
        // own domain vocabulary — an application adds its own with addTransliteration().
        [/\bA\.?I\.?\b/gi, 'τεχνητή νοημοσύνη'],
        [/\bA\.?P\.?I\.?\b/gi, 'έι-πι-άι'],
        [/\bU\.?R\.?L\.?s?\b/gi, 'σύνδεσμοι'],
        [/\bI\.?D\.?s?\b/gi, 'αναγνωριστικό'],
        [/\bO\.?K\.?\b/gi, 'εντάξει'],
        [/\bOkay\b/gi, 'εντάξει'],
    ];

    /** An application's own transliterations, checked after the built-in ones. */
    var extraTransliterations = [];

    /**
     * Register an application-specific English/Latin term or phrase to speak in Greek —
     * product names, domain jargon ("bounce rate", "SEO"), brand names.
     *
     * @param {RegExp} pattern     A global, case-insensitive regex (`/gi` flags).
     * @param {string} replacement
     */
    function addTransliteration(pattern, replacement) {
        extraTransliterations.push([pattern, replacement]);
    }

    /**
     * Register an application's own gender-agreement correction: an LLM answers in the
     * male-grammar form by default ("Είμαι ο βοηθός σου..."), and this maps that exact phrase
     * to its female and neutral equivalents — the male form itself needs no entry, since it is
     * what the model already wrote. An application's assistant says its own stock sentences
     * ("Είμαι ο βοηθός σου στην Acme"); this file does not know them in advance.
     *
     * @param {string} malePhrase  Exact phrase as the model writes it (case-insensitive).
     * @param {string} femalePhrase
     * @param {string} neutralPhrase
     */
    function addGenderPhrases(malePhrase, femalePhrase, neutralPhrase) {
        if (!malePhrase || !femalePhrase) {
            return;
        }
        genderPhrases.push({ male: malePhrase, female: femalePhrase, neutral: neutralPhrase || femalePhrase });
    }

    var genderPhrases = [];

    /**
     * Transliterates a single Latin word into Greek phonetics, so an unmapped English word is
     * pronounced as a continuous word rather than spelled letter by letter.
     *
     * @param {string} word
     * @returns {string}
     */
    function transliterateLatinWord(word) {
        if (!word) {
            return '';
        }

        var digraphs = [
            ['th', 'θ'], ['ch', 'χ'], ['ph', 'φ'], ['ps', 'ψ'], ['sh', 'σ'],
            ['ck', 'κ'], ['nd', 'ντ'], ['ou', 'ου'], ['oo', 'ου'], ['ee', 'ι'],
            ['ea', 'ι'], ['ai', 'αι'], ['ei', 'ει'], ['oi', 'οι'],
        ];
        var singleLetters = {
            a: 'α', b: 'μπ', c: 'κ', d: 'ντ', e: 'ε', f: 'φ', g: 'γκ', h: 'χ',
            i: 'ι', j: 'τζ', k: 'κ', l: 'λ', m: 'μ', n: 'ν', o: 'ο', p: 'π',
            q: 'κ', r: 'ρ', s: 'σ', t: 'τ', u: 'ου', v: 'β', w: 'γου', x: 'ξ',
            y: 'υ', z: 'ζ',
        };

        var lower = word.toLowerCase();
        digraphs.forEach(function (pair) {
            lower = lower.split(pair[0]).join(pair[1]);
        });

        var result = '';
        for (var i = 0; i < lower.length; i++) {
            var ch = lower[i];
            if (singleLetters[ch]) {
                result += (singleLetters[ch] === 'σ' && i === lower.length - 1) ? 'ς' : singleLetters[ch];
            } else {
                result += ch;
            }
        }
        return result;
    }

    /**
     * Strips emoji, pictographs, symbols and ASCII emoticons from text.
     *
     * @param {string} text
     * @returns {string}
     */
    function stripEmojisAndSymbols(text) {
        if (!text) {
            return '';
        }
        var clean = String(text);
        clean = clean.replace(/\p{Extended_Pictographic}/gu, '');
        clean = clean.replace(/[\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}\u{FE0E}\u{FE0F}\u{200D}\u{20E3}]/gu, '');
        clean = clean.replace(/[☀-➿⌀-⏿⭐-⭕⤴⤵■-◿←-⇿①-⓿]/gu, '');
        clean = clean.replace(/(?:[:;=8]['-]?[)D(\/\\pP|oO\]\[}]|<3|;\))/g, '');
        return clean;
    }

    /**
     * The grammatically correct assistant title in Greek for a gender — ο βοηθός / η βοηθός /
     * το AI by default; an application's own `addGenderPhrases()` entries don't change this, it
     * is the built-in fallback title.
     *
     * @param {'female'|'male'|'neutral'} [gender]
     * @returns {string}
     */
    function getAssistantTitle(gender) {
        if (gender === 'male') {
            return 'ο βοηθός';
        }
        if (gender === 'neutral') {
            return 'το AI';
        }
        return 'η βοηθός';
    }

    /**
     * A natural self-introduction for the assistant's name, grammatically agreeing with gender.
     *
     * @param {'female'|'male'|'neutral'} [gender]
     * @param {string} [name] The assistant's own name; omitted, the title alone is used.
     * @returns {string}
     */
    function getAssistantSelfIntroduction(gender, name) {
        var who = name ? ('στο ' + name) : '';
        if (gender === 'male') {
            return 'Καλησπέρα! Είμαι ο βοηθός σου ' + who + '. Πώς μπορώ να σε βοηθήσω;';
        }
        if (gender === 'neutral') {
            return 'Γεια σας! Είμαι το σύστημα υποστήριξης ' + who + '. Πώς μπορώ να βοηθήσω;';
        }
        return 'Καλησπέρα! Είμαι η βοηθός σου ' + who + '. Πώς μπορώ να σε βοηθήσω;';
    }

    /**
     * Cleans and prepares text for natural Greek speech synthesis: strips emoji, maps English
     * technical/marketing terms to Greek (built-in plus an application's own
     * `addTransliteration()` entries), aligns the assistant's self-reference to `gender`
     * (built-in title plus an application's own `addGenderPhrases()` entries), and
     * transliterates any Latin word still left so TTS pronounces it rather than spelling it.
     *
     * @param {string} text
     * @param {{ gender?: 'female'|'male'|'neutral' }} [options]
     * @returns {string}
     */
    function prepareGreekSpeechText(text, options) {
        if (!text) {
            return '';
        }
        var gender = (options && options.gender) || 'female';
        var clean = String(text);

        clean = stripEmojisAndSymbols(clean);
        clean = clean.replace(/\(\s*[a-zA-Z0-9\s,._/-]+\s*\)/g, '');

        // "ό,τι" (whatever) sounds exactly as "ότι" (that), but a voice reads the comma as a
        // letter's name — "όμικρον τι". Without it, the word is said as written.
        clean = clean.replace(/(?<!\p{L})([όΌ]),\s?(τι)(?!\p{L})/giu, '$1$2');

        var assistantTitle = getAssistantTitle(gender);
        clean = clean.replace(/\bAsk\s*AI\b/gi, assistantTitle);
        clean = clean.replace(/\bAskAi\b/gi, assistantTitle);

        // The one universal correspondence, matching getAssistantTitle() itself: an LLM answers
        // in the male-grammar form by default, and a non-male persona needs the noun corrected
        // wherever it refers to itself as "the assistant" — an application's own stock
        // sentences are addGenderPhrases()'s job, this is just the bare title noun.
        if (gender !== 'male') {
            // \b is an ASCII word-boundary in JS regex and does not work on Greek letters —
            // (?<!\p{L})/(?!\p{L}) is the Unicode-safe equivalent, same idiom the gender/voice
            // detection below already uses.
            clean = clean.replace(/(?<!\p{L})ο\s+βοηθός(?!\p{L})/giu, gender === 'neutral' ? 'το AI' : 'η βοηθός');
        }

        if (gender !== 'male') {
            genderPhrases.forEach(function (entry) {
                var pattern = new RegExp(entry.male.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                clean = clean.replace(pattern, gender === 'neutral' ? entry.neutral : entry.female);
            });
        }

        ENGLISH_TO_GREEK_MAP.concat(extraTransliterations).forEach(function (pair) {
            clean = clean.replace(pair[0], pair[1]);
        });

        clean = clean.replace(/(\d+)\s*%/g, '$1 τοις εκατό');
        clean = clean.replace(/%/g, ' τοις εκατό');
        clean = clean.replace(/&/g, ' και ');
        clean = clean.replace(/\+/g, ' συν ');

        clean = clean.replace(/[a-zA-Z]+/g, function (match) { return transliterateLatinWord(match); });

        clean = clean.replace(/\s{2,}/g, ' ');
        clean = clean.replace(/\s+([.,;:!?])/g, '$1');

        return clean.trim();
    }

    /**
     * Cleans and prepares English text for natural speech synthesis: strips emoji, spells
     * out common symbols, leaves English words untouched.
     *
     * @param {string} text
     * @returns {string}
     */
    function prepareEnglishSpeechText(text) {
        if (!text) {
            return '';
        }
        var clean = String(text);
        clean = stripEmojisAndSymbols(clean);
        clean = clean.replace(/(\d+)\s*%/g, '$1 percent');
        clean = clean.replace(/%/g, ' percent');
        clean = clean.replace(/&/g, ' and ');
        clean = clean.replace(/\+/g, ' plus ');
        clean = clean.replace(/\s{2,}/g, ' ');
        clean = clean.replace(/\s+([.,;:!?])/g, '$1');
        return clean.trim();
    }

    /**
     * Normalizes Greek text for comparison: lowercase, no accents/diacritics, final sigma
     * folded, punctuation stripped, whitespace collapsed.
     *
     * @param {string} text
     * @returns {string}
     */
    function normalizeGreek(text) {
        if (!text) {
            return '';
        }
        return String(text)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[άα]/g, 'α')
            .replace(/[έε]/g, 'ε')
            .replace(/[ήη]/g, 'η')
            .replace(/[ίϊΐι]/g, 'ι')
            .replace(/[όο]/g, 'ο')
            .replace(/[ύϋΰυ]/g, 'υ')
            .replace(/[ώω]/g, 'ω')
            .replace(/ς/g, 'σ')
            .replace(/[^\p{L}\p{N}\s]/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    var STRONG_INTERRUPT_KEYWORDS = [
        'σταματα', 'σταματησε', 'σταματηστε', 'σταματατο', 'σταματα το',
        'στοπ', 'stop', 'περιμενε', 'περιμενετε', 'wait', 'αστο', 'αστο αυτο',
        'ησυχια', 'σιωπη', 'παυση', 'pause', 'αρκει', 'φτανει', 'τελος', 'enough',
        'ακυρο', 'ακυρωση', 'ακυρωσε', 'cancel', 'halt', 'κλειστο', 'σωπα',
    ];

    var INTERRUPT_KEYWORDS = STRONG_INTERRUPT_KEYWORDS.concat(['οχι', 'no', 'μη', 'μην']);

    /**
     * Whether text contains an interruption keyword (standalone word, or a multi-word keyword
     * as a substring).
     *
     * @param {string} text
     * @returns {boolean}
     */
    function hasInterruptKeyword(text) {
        var norm = normalizeGreek(text);
        if (!norm) {
            return false;
        }
        var words = norm.split(' ').filter(Boolean);
        for (var i = 0; i < words.length; i++) {
            if (INTERRUPT_KEYWORDS.indexOf(words[i]) !== -1) {
                return true;
            }
        }
        for (var j = 0; j < INTERRUPT_KEYWORDS.length; j++) {
            if (INTERRUPT_KEYWORDS[j].indexOf(' ') !== -1 && norm.indexOf(INTERRUPT_KEYWORDS[j]) !== -1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether speech is purely an interruption command ("Σταμάτα", "Στοπ παρακαλώ") rather than
     * a new question that happens to contain one of those words.
     *
     * @param {string} text
     * @returns {boolean}
     */
    function isPureInterruptCommand(text) {
        var norm = normalizeGreek(text);
        if (!norm) {
            return false;
        }
        var words = norm.split(' ').filter(Boolean);
        if (words.length === 0) {
            return false;
        }
        var fillers = ['σε', 'παρακαλω', 'λιγο', 'ρε', 'συ', 'please', 'μισο', 'λεπτο'];
        return words.every(function (w) { return INTERRUPT_KEYWORDS.indexOf(w) !== -1 || fillers.indexOf(w) !== -1; });
    }

    /**
     * Strips leading interrupt keywords/polite particles from a barge-in utterance, leaving the
     * actual question intact — "Σταμάτα, πες μου για..." becomes "πες μου για...".
     *
     * @param {string} text
     * @returns {string}
     */
    function stripLeadingInterruptKeywords(text) {
        if (!text) {
            return '';
        }
        var result = text.trim();
        for (;;) {
            var match = result.match(/^([^\s,.:;!?]+)[,\s]*/);
            if (!match) {
                break;
            }
            if (INTERRUPT_KEYWORDS.indexOf(normalizeGreek(match[1])) !== -1) {
                result = result.slice(match[0].length).trim();
            } else {
                break;
            }
        }
        return result.trim();
    }

    /**
     * Distinguishes the user's voice interruption from the microphone picking up the
     * assistant's own speech from the speakers (which, left unfiltered, becomes an infinite
     * feedback loop: the assistant's voice gets transcribed and treated as a new question).
     *
     * @param {string} heardText     What the microphone heard.
     * @param {string} assistantText What the assistant is currently speaking.
     * @returns {boolean} True when heardText is the assistant's own voice, to be ignored.
     */
    function isSpeakerEcho(heardText, assistantText) {
        if (!heardText) {
            return true;
        }
        if (!assistantText) {
            return false;
        }

        var normHeard = normalizeGreek(heardText);
        var normAssistant = normalizeGreek(assistantText);
        if (!normHeard) {
            return true;
        }
        if (!normAssistant) {
            return false;
        }

        var heardWords = normHeard.split(' ').filter(Boolean);
        if (heardWords.some(function (w) { return STRONG_INTERRUPT_KEYWORDS.indexOf(w) !== -1; })) {
            return false;
        }

        if (normAssistant.indexOf(normHeard) !== -1) {
            return true;
        }

        var assistantWords = normAssistant.split(' ').filter(Boolean);
        var softKeywords = ['οχι', 'no', 'μη', 'μην'];
        var hasSoftKeyword = heardWords.some(function (w) { return softKeywords.indexOf(w) !== -1; });

        var matchingWordsCount = 0;
        heardWords.forEach(function (hw) {
            var isMatch = assistantWords.some(function (aw) {
                if (aw === hw) {
                    return true;
                }
                if (hw.length >= 4 && aw.length >= 4) {
                    return aw.indexOf(hw.slice(0, 4)) === 0 || hw.indexOf(aw.slice(0, 4)) === 0;
                }
                return false;
            });
            if (isMatch) {
                matchingWordsCount++;
            }
        });

        var matchRatio = matchingWordsCount / heardWords.length;
        if (hasSoftKeyword && matchRatio < 0.75) {
            return false;
        }
        if (matchRatio >= 0.3) {
            return true;
        }
        return false;
    }

    /**
     * Strips the accumulated speaker echo from a barge-in utterance, returning only what the
     * user actually said after the assistant's own words.
     *
     * @param {string} heardText
     * @param {string} assistantText
     * @returns {string}
     */
    function extractUserSpeechFromEcho(heardText, assistantText) {
        if (!heardText) {
            return '';
        }
        var trimmed = heardText.trim();
        if (!assistantText) {
            return trimmed;
        }

        var normAssistant = normalizeGreek(assistantText);
        var tokens = trimmed.split(/\s+/).filter(Boolean);
        if (tokens.length === 0) {
            return '';
        }
        var normTokens = tokens.map(function (t) { return normalizeGreek(t); });

        for (var i = 0; i < normTokens.length; i++) {
            if (INTERRUPT_KEYWORDS.indexOf(normTokens[i]) !== -1) {
                return tokens.slice(i).join(' ');
            }
        }

        var assistantWords = normAssistant.split(/\s+/).filter(Boolean);
        var divergenceIndex = 0;
        for (var k = 0; k < normTokens.length; k++) {
            var token = normTokens[k];
            var inAssistant = assistantWords.some(function (aw) {
                if (aw === token) {
                    return true;
                }
                if (token.length >= 4 && aw.length >= 4) {
                    return aw.indexOf(token.slice(0, 4)) === 0 || token.indexOf(aw.slice(0, 4)) === 0;
                }
                return false;
            });
            if (!inAssistant) {
                divergenceIndex = k;
                break;
            }
        }

        return tokens.slice(divergenceIndex).join(' ');
    }

    /**
     * An instant greeting acknowledgment for a simple conversational opener ("καλησπέρα",
     * "γεια σας"), or null for anything else (silence while the real answer is thought about).
     *
     * @param {string} text
     * @returns {string|null}
     */
    function getPatternAck(text) {
        if (!text) {
            return null;
        }
        var norm = normalizeGreek(text);
        if (!norm) {
            return null;
        }
        if (norm.indexOf('καλημερα') === 0) {
            return 'Καλημέρα!';
        }
        if (norm.indexOf('καλησπερα') === 0) {
            return 'Καλησπέρα!';
        }
        if (norm.indexOf('γεια') === 0 || norm.indexOf('χαιρετε') === 0) {
            return 'Γεια σας!';
        }
        return null;
    }

    var DETAIL_REQUEST_PHRASES = [
        'περισσοτερα', 'περισσοτερες λεπτομερειες', 'πες μου περισσοτερα',
        'πες μου περισσοτερες λεπτομερειες', 'πες μου λεπτομερειες', 'λεπτομερειες',
        'διαβασε το ολο', 'διαβασε ολο το κειμενο', 'διαβασε μου το ολο',
        'διαβασε το αναλυτικα', 'διαβασε αναλυτικα', 'αναλυσε το', 'αναλυτικα',
        'πες μου αναλυτικα', 'θελω λεπτομερειες', 'θελω να ακουσω περισσοτερα',
        'ανοιξε το κειμενο', 'δειξε το κειμενο', 'δειξε μου περισσοτερα',
        'more details', 'tell me more', 'read all', 'read full', 'read everything', 'show details',
    ];

    /**
     * Whether spoken input is a request to hear or see more detail, rather than a new topical
     * question that happens to use similar words.
     *
     * @param {string} text
     * @returns {boolean}
     */
    function isDetailRequest(text) {
        if (!text) {
            return false;
        }
        var norm = normalizeGreek(text);
        if (!norm) {
            return false;
        }
        if (norm.indexOf(' για ') !== -1 || norm.indexOf(' about ') !== -1) {
            return false;
        }

        for (var i = 0; i < DETAIL_REQUEST_PHRASES.length; i++) {
            var normPhrase = normalizeGreek(DETAIL_REQUEST_PHRASES[i]);
            if (norm === normPhrase || norm === ('ναι ' + normPhrase)) {
                return true;
            }
        }

        var words = norm.split(' ').filter(Boolean);
        if (words.length <= 4) {
            if (words.some(function (w) { return w.indexOf('λεπτομερει') === 0 || w.indexOf('αναλυτικ') === 0 || w.indexOf('αναλυσε') === 0; })) {
                return true;
            }
            if (words.some(function (w) { return w.indexOf('περισσοτερ') === 0; })
                && words.indexOf('αρθρα') === -1 && words.indexOf('κλικ') === -1 && words.indexOf('στοιχεια') === -1) {
                return true;
            }
            if (words.indexOf('διαβασε') !== -1 && words.some(function (w) { return w.indexOf('ολο') === 0 || w.indexOf('κειμεν') === 0 || w.indexOf('αναλυτικ') === 0; })) {
                return true;
            }
            if (words.indexOf('more') !== -1 && (words.indexOf('details') !== -1 || words.indexOf('info') !== -1)) {
                return true;
            }
            if (words.indexOf('read') !== -1 && (words.indexOf('all') !== -1 || words.indexOf('full') !== -1 || words.indexOf('everything') !== -1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Condenses an extensive answer (a report, a table, a multi-bullet breakdown) into one or
     * two spoken sentences, leaving the full Markdown on screen untouched — a long answer read
     * verbatim is not how a person would explain it out loud.
     *
     * @param {string} rawText
     * @param {{ gender?: 'female'|'male'|'neutral', isDetailRequest?: boolean }} [options]
     * @returns {string}
     */
    function formatSpokenDigest(rawText, options) {
        if (!rawText) {
            return '';
        }
        var text = String(rawText).trim();
        if (!text) {
            return '';
        }
        var gender = (options && options.gender) || 'female';
        var wantsDetail = Boolean(options && options.isDetailRequest);

        if (wantsDetail) {
            return text;
        }

        var voiceTagMatch = text.match(/<voice>([\s\S]*?)<\/voice>/i) || text.match(/\[voice:\s*([\s\S]*?)\]/i);
        if (voiceTagMatch && voiceTagMatch[1].trim()) {
            return voiceTagMatch[1].trim();
        }

        var hasHeaders = /^#{1,6}\s+/m.test(text);
        var hasTable = /\|.+\|.+\|/.test(text);
        var hasCode = /```[\s\S]*?```/.test(text);
        var hasBullets = /^\s*[-*+]\s+/m.test(text) || /^\s*\d+\.\s+/m.test(text);
        var isLong = text.length > 250;

        if (!hasHeaders && !hasTable && !hasCode && !hasBullets && !isLong) {
            return text;
        }

        var candidateText = text;
        var bulletMatch = text.search(/^\s*[-*+]\s+|^\s*\d+\.\s+/m);
        if (bulletMatch > 15) {
            var leadIn = text.slice(0, bulletMatch).trim();
            leadIn = leadIn.replace(/(?:,\s*)?(?:π\.χ\.|όπως|για παράδειγμα|ενδεικτικά|π\.χ)\s*[:.]?\s*$/i, '');
            leadIn = leadIn.replace(/[:.]\s*$/, '');
            if (leadIn.length >= 20) {
                candidateText = leadIn;
            }
        }

        var clean = candidateText.replace(/```[\s\S]*?```/g, '');
        clean = clean.replace(/^\|.*\|$/gm, '');
        clean = clean.replace(/^#{1,6}\s+(.*)$/gm, '$1.');
        clean = clean.replace(/!\[([^\]]*)\]\([^)]+\)/g, '');
        clean = clean.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
        clean = clean.replace(/(\*\*|__)(.*?)\1/g, '$2');
        clean = clean.replace(/(\*|_)(.*?)\1/g, '$2');
        clean = clean.replace(/\r?\n+/g, '. ');
        clean = clean.replace(/^\s*[-*+]\s+/gm, '');
        clean = clean.replace(/^\s*\d+\.\s+/gm, '');

        var sentences = clean
            .split(/(?<=[.!?;;])\s+/)
            .map(function (s) { return s.replace(/\s+/g, ' ').trim(); })
            .filter(function (s) { return s.length > 0 && s.indexOf('|') !== 0; });

        var digestSentences = [];
        var currentLength = 0;

        for (var i = 0; i < sentences.length; i++) {
            var sentence = sentences[i];
            if (/^[-:|,.\s]+$/.test(sentence)) {
                continue;
            }
            digestSentences.push(sentence);
            currentLength += sentence.length;

            var isQuestion = /[?;]\s*$/.test(sentence);
            if (isQuestion && currentLength >= 35) {
                break;
            }
            if (currentLength >= 75 || digestSentences.length >= 2) {
                break;
            }
        }

        var coreDigest = digestSentences.join(' ').trim();
        if (!coreDigest) {
            coreDigest = clean.slice(0, 140).trim();
        }
        if (!/[.!?;;]$/.test(coreDigest)) {
            coreDigest += '.';
        }

        var isReportOrData = hasTable || (hasHeaders && hasBullets)
            || /\b(αναφορά|στατιστικά|μετρικές|δεδομένα|report|metrics|kpis)\b/i.test(text);
        var endsWithQuestion = /[?;;]\s*$/.test(coreDigest)
            || /\b(πες μου|ρωτάς|θέλεις|πείτε μου|θέλετε|tell me|let me know)\b/i.test(coreDigest);

        if (endsWithQuestion || !isReportOrData) {
            return coreDigest;
        }

        var hasGreek = /[Ͱ-Ͽἀ-῿]/.test(text);
        var promptSuffix = '';
        if (hasGreek) {
            promptSuffix = gender === 'neutral'
                ? ' Η αναλυτική αναφορά εμφανίζεται στην οθόνη. Ζητήστε λεπτομέρειες αν επιθυμείτε.'
                : ' Έχω ετοιμάσει την πλήρη ανάλυση στην οθόνη. Πες μου αν θέλεις να σου πω περισσότερες λεπτομέρειες!';
        } else {
            promptSuffix = " I've prepared the full breakdown on screen. Let me know if you'd like more details!";
        }

        return (coreDigest + promptSuffix).trim();
    }

    window.PfSpeechSanitizer = {
        transliterateLatinWord: transliterateLatinWord,
        stripEmojisAndSymbols: stripEmojisAndSymbols,
        getAssistantTitle: getAssistantTitle,
        getAssistantSelfIntroduction: getAssistantSelfIntroduction,
        prepareGreekSpeechText: prepareGreekSpeechText,
        prepareEnglishSpeechText: prepareEnglishSpeechText,
        normalizeGreek: normalizeGreek,
        hasInterruptKeyword: hasInterruptKeyword,
        isPureInterruptCommand: isPureInterruptCommand,
        stripLeadingInterruptKeywords: stripLeadingInterruptKeywords,
        isSpeakerEcho: isSpeakerEcho,
        extractUserSpeechFromEcho: extractUserSpeechFromEcho,
        getPatternAck: getPatternAck,
        isDetailRequest: isDetailRequest,
        formatSpokenDigest: formatSpokenDigest,
        addTransliteration: addTransliteration,
        addGenderPhrases: addGenderPhrases,
        INTERRUPT_KEYWORDS: INTERRUPT_KEYWORDS,
        STRONG_INTERRUPT_KEYWORDS: STRONG_INTERRUPT_KEYWORDS,
        DETAIL_REQUEST_PHRASES: DETAIL_REQUEST_PHRASES,
    };
}());
