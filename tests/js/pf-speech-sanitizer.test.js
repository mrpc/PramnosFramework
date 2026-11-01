/**
 * `pf-speech-sanitizer.js`: prepares Ask AI's answers for natural Greek/English speech
 * synthesis, and reads a barge-in interruption back out of what the microphone heard.
 *
 * What has to hold: a Latin word TTS would otherwise spell letter by letter is transliterated
 * to Greek phonetics instead; emoji and symbols are stripped before anything is spoken; an
 * application's own `addGenderPhrases()`/`addTransliteration()` entries take effect, and the
 * built-in generic title ("ο βοηθός"/"η βοηθός"/"το AI") corrects on its own; the assistant's
 * own speech leaking back into the microphone is told apart from a genuine interruption.
 *
 * Run:
 *   node --test tests/js/pf-speech-sanitizer.test.js
 */
'use strict';

const { test, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-speech-sanitizer.js');

function page() {
    const { window } = createDom();
    loadScript(SOURCE, window);
    return window.PfSpeechSanitizer;
}

let sanitizer;

beforeEach(() => {
    sanitizer = page();
});

test('transliterateLatinWord turns a Latin word into Greek phonetics', () => {
    assert.equal(sanitizer.transliterateLatinWord('test'), 'τεστ');
    assert.equal(sanitizer.transliterateLatinWord(''), '');
});

test('stripEmojisAndSymbols removes emoji and ASCII smileys', () => {
    const result = sanitizer.stripEmojisAndSymbols('Hello 😊 world :) done');
    assert.ok(!result.includes('😊'));
    assert.ok(!result.includes(':)'));
    assert.ok(result.includes('Hello'));
});

test('getAssistantTitle picks the grammatically correct Greek title per gender', () => {
    assert.equal(sanitizer.getAssistantTitle('female'), 'η βοηθός');
    assert.equal(sanitizer.getAssistantTitle('male'), 'ο βοηθός');
    assert.equal(sanitizer.getAssistantTitle('neutral'), 'το AI');
    assert.equal(sanitizer.getAssistantTitle(), 'η βοηθός', 'defaults to female');
});

test('getAssistantSelfIntroduction includes the given name, grammatically agreeing', () => {
    assert.match(sanitizer.getAssistantSelfIntroduction('female', 'Aria'), /η βοηθός σου στο Aria/);
    assert.match(sanitizer.getAssistantSelfIntroduction('male', 'Aria'), /ο βοηθός σου στο Aria/);
    assert.match(sanitizer.getAssistantSelfIntroduction('neutral'), /σύστημα υποστήριξης/);
});

test('prepareGreekSpeechText replaces "Ask AI" with the gendered title', () => {
    const result = sanitizer.prepareGreekSpeechText('Ask AI σου λέει καλημέρα', { gender: 'female' });
    assert.match(result, /η βοηθός σου λέει καλημέρα/);
});

test('prepareGreekSpeechText corrects the built-in generic title noun for a non-male persona', () => {
    const female = sanitizer.prepareGreekSpeechText('Είμαι ο βοηθός σου', { gender: 'female' });
    assert.match(female, /η βοηθός/);

    const male = sanitizer.prepareGreekSpeechText('Είμαι ο βοηθός σου', { gender: 'male' });
    assert.match(male, /ο βοηθός/, 'male form is left as the model already wrote it');
});

test('addGenderPhrases lets an application register its own assistant stock phrases', () => {
    sanitizer.addGenderPhrases('Είμαι ο αναλυτής σου', 'Είμαι η αναλύτρια σου', 'Είμαι το σύστημα ανάλυσης');

    const female = sanitizer.prepareGreekSpeechText('Είμαι ο αναλυτής σου, έτοιμος.', { gender: 'female' });
    assert.match(female, /Είμαι η αναλύτρια σου/);

    const male = sanitizer.prepareGreekSpeechText('Είμαι ο αναλυτής σου, έτοιμος.', { gender: 'male' });
    assert.match(male, /Είμαι ο αναλυτής σου/, 'male form unchanged');
});

test('addGenderPhrases ignores an incomplete registration', () => {
    const before = sanitizer.prepareGreekSpeechText('Είμαι ο βοηθός', { gender: 'female' });
    sanitizer.addGenderPhrases('', '');
    const after = sanitizer.prepareGreekSpeechText('Είμαι ο βοηθός', { gender: 'female' });
    assert.equal(before, after);
});

test('addTransliteration lets an application register its own domain vocabulary', () => {
    sanitizer.addTransliteration(/\bbounce rate\b/gi, 'ποσοστό εγκατάλειψης');
    const result = sanitizer.prepareGreekSpeechText('Η bounce rate είναι χαμηλή', { gender: 'female' });
    assert.match(result, /ποσοστό εγκατάλειψης/);
});

test('prepareGreekSpeechText spells percent/ampersand/plus in Greek', () => {
    const result = sanitizer.prepareGreekSpeechText('50% και 2+2', { gender: 'female' });
    assert.match(result, /50 τοις εκατό/);
    assert.match(result, /και/);
    assert.match(result, /συν/);
});

test('prepareEnglishSpeechText leaves English words untouched but spells symbols', () => {
    const result = sanitizer.prepareEnglishSpeechText('50% done & more');
    assert.match(result, /50 percent done and more/);
});

test('normalizeGreek strips accents, folds final sigma, lowercases', () => {
    // ς (final sigma) folds to plain σ, so "ΑΜΕΣΩΣ" normalizes to "αμεσωσ", not "αμεσως".
    assert.equal(sanitizer.normalizeGreek('Στάσου ΑΜΕΣΩΣ!'), 'στασου αμεσωσ');
    assert.equal(sanitizer.normalizeGreek(''), '');
});

test('hasInterruptKeyword recognizes standalone and multi-word keywords', () => {
    assert.ok(sanitizer.hasInterruptKeyword('σταμάτα'));
    assert.ok(sanitizer.hasInterruptKeyword('άστο αυτό τώρα'));
    assert.ok(!sanitizer.hasInterruptKeyword('πες μου για το κλίμα'));
});

test('isPureInterruptCommand allows polite fillers but not new content', () => {
    assert.ok(sanitizer.isPureInterruptCommand('σταμάτα σε παρακαλώ'));
    assert.ok(!sanitizer.isPureInterruptCommand('σταμάτα και πες μου για τις πωλήσεις'));
});

test('stripLeadingInterruptKeywords removes only the leading keyword', () => {
    assert.equal(sanitizer.stripLeadingInterruptKeywords('Σταμάτα, πες μου για το Facebook'), 'πες μου για το Facebook');
    assert.equal(sanitizer.stripLeadingInterruptKeywords('Δείξε μου τα κλικ'), 'Δείξε μου τα κλικ');
});

test('isSpeakerEcho treats a strong interrupt keyword as never an echo', () => {
    assert.equal(sanitizer.isSpeakerEcho('σταμάτα', 'καλησπέρα είμαι έτοιμος για δουλειά'), false);
});

test('isSpeakerEcho treats a substring of the assistant speech as an echo', () => {
    assert.equal(sanitizer.isSpeakerEcho('καλησπέρα είμαι', 'καλησπέρα είμαι έτοιμος για δουλειά'), true);
});

test('isSpeakerEcho treats novel words as a genuine barge-in', () => {
    assert.equal(sanitizer.isSpeakerEcho('τι ώρα είναι', 'καλησπέρα είμαι έτοιμος για δουλειά'), false);
});

test('extractUserSpeechFromEcho returns only what follows the interrupt keyword', () => {
    const heard = 'καλησπέρα είμαι έτοιμος για δουλειά σταμάτα';
    const assistant = 'Καλησπέρα! Είμαι έτοιμος για δουλειά.';
    assert.equal(sanitizer.extractUserSpeechFromEcho(heard, assistant), 'σταμάτα');
});

test('getPatternAck answers a greeting instantly, and nothing else', () => {
    assert.equal(sanitizer.getPatternAck('Καλημέρα'), 'Καλημέρα!');
    assert.equal(sanitizer.getPatternAck('πες μου τα νέα'), null);
});

test('isDetailRequest recognizes a request for more detail, not a new topical question', () => {
    assert.ok(sanitizer.isDetailRequest('πες μου περισσότερα'));
    assert.ok(!sanitizer.isDetailRequest('πες μου για το Facebook'));
});

test('formatSpokenDigest condenses a long, structured answer to one or two sentences', () => {
    const long = '# Αναφορά επισκεψιμότητας\n\nΑυτή είναι μια πολύ μεγάλη και αναλυτική αναφορά με πολλά δεδομένα, στατιστικά και μετρικές που θα διαβαστούν αναλυτικά.\n\n- κλικ: 10\n- εμφανίσεις: 20\n- μετατροπές: 5\n\nΚαι ακόμα περισσότερο κείμενο ακολουθεί εδώ για να ξεπεράσει το όριο μήκους που θεωρείται εκτενή απάντηση.';
    const digest = sanitizer.formatSpokenDigest(long, { gender: 'female' });
    assert.ok(digest.length < long.length, `expected a shorter digest, got ${digest.length} vs ${long.length}`);
    assert.ok(!digest.includes('- κλικ'), 'the raw bullet list is not read aloud');
});

test('formatSpokenDigest returns the full text unchanged when detail was requested', () => {
    const text = 'Μια σύντομη απάντηση.';
    assert.equal(sanitizer.formatSpokenDigest(text, { isDetailRequest: true }), text);
});

test('formatSpokenDigest leaves a short, simple answer as-is', () => {
    const text = 'Ναι, είναι έτοιμο.';
    assert.equal(sanitizer.formatSpokenDigest(text, { gender: 'female' }), text);
});
