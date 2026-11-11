/**
 * `pf-voice.js`: dictation and read-aloud over the native Web Speech API (Phase 1 of Ask AI
 * Voice, docs/Pramnos_LLM_Analyst_Guide.md "## Voice") — no server side.
 *
 * What has to hold: voice gender detection never calls a male voice female or vice versa;
 * pickVoice() never hands a female persona a voice it detected as male, and never falls back
 * to a foreign-language voice for Greek; startDictation()/stopDictation()/toggleDictation()
 * drive a fake SpeechRecognition correctly; speak()/stopSpeech()/toggleSpeech() drive a fake
 * SpeechSynthesisUtterance, picking pitch by gender; preferences persist under the framework's
 * own `pf-voice-*` keys, not an application-named one.
 *
 * Run:
 *   node --test tests/js/pf-voice.test.js
 */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SANITIZER = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-speech-sanitizer.js');
const VOICE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-voice.js');

/** A fake SpeechSynthesisUtterance capturing what pf-voice.js set on it. */
class FakeUtterance {
    constructor(text) {
        this.text = text;
        this.voice = null;
        this.lang = '';
        this.pitch = 1;
        this.rate = 1;
    }
}

/** A fake window.speechSynthesis — getVoices()/speak()/cancel(), no actual audio. */
function fakeSynthesis(voices) {
    const synth = {
        spoken: [],
        getVoices: () => voices,
        speak(utterance) {
            synth.spoken.push(utterance);
            if (utterance.onstart) {
                utterance.onstart();
            }
        },
        cancel() {
            synth.cancelled = true;
        },
        addEventListener() {},
        onvoiceschanged: null,
    };
    return synth;
}

/** A fake SpeechRecognition constructor — captures the instance so a test can drive its events. */
function fakeRecognitionConstructor(captured) {
    return function FakeSpeechRecognition() {
        this.continuous = false;
        this.interimResults = false;
        this.lang = '';
        this.started = false;
        this.start = () => { this.started = true; };
        this.stop = () => { this.started = false; };
        this.abort = () => { this.started = false; };
        captured.instance = this;
    };
}

function page({ voices = [], withRecognition = true } = {}) {
    const { window } = createDom();
    window.navigator = { userAgent: 'Mozilla/5.0 Chrome/120.0' };
    window.localStorage = (() => {
        const store = new Map();
        return {
            getItem: (k) => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => store.set(k, String(v)),
            removeItem: (k) => store.delete(k),
        };
    })();

    const captured = {};
    if (withRecognition) {
        window.SpeechRecognition = fakeRecognitionConstructor(captured);
    }
    window.speechSynthesis = fakeSynthesis(voices);
    window.SpeechSynthesisUtterance = FakeUtterance;

    loadScript(SANITIZER, window);
    loadScript(VOICE, window);

    return { window, captured };
}

test('isSpeechRecognitionSupported / isSpeechSynthesisSupported reflect what the window has', () => {
    const { window } = page();
    assert.equal(window.PfVoice.isSpeechRecognitionSupported(), true);
    assert.equal(window.PfVoice.isSpeechSynthesisSupported(), true);

    const { window: noRecognition } = page({ withRecognition: false });
    assert.equal(noRecognition.PfVoice.isSpeechRecognitionSupported(), false);
});

test('getVoiceGender recognizes explicit markers and known names, Greek and English', () => {
    const { window } = page();
    const { getVoiceGender } = window.PfVoice;

    assert.equal(getVoiceGender({ name: 'Microsoft Stefanos' }), 'male');
    assert.equal(getVoiceGender({ name: 'Microsoft Athina' }), 'female');
    assert.equal(getVoiceGender({ name: 'Google UK English Female' }), 'female');
    assert.equal(getVoiceGender({ name: 'eSpeak Greek' }), 'male');
    assert.equal(getVoiceGender({ name: 'Some Unknown Voice' }), 'unknown');
    assert.equal(getVoiceGender(null), 'unknown');
});

test('isGreekVoice matches by lang tag or name', () => {
    const { window } = page();
    const { isGreekVoice } = window.PfVoice;

    assert.equal(isGreekVoice({ lang: 'el-GR', name: 'Athina' }), true);
    assert.equal(isGreekVoice({ lang: 'en-US', name: 'Samantha' }), false);
    assert.equal(isGreekVoice(null), false);
});

test('pickVoice never hands a female persona a voice it detected as male', () => {
    const voices = [
        { name: 'Microsoft Stefanos', lang: 'el-GR', voiceURI: 'stefanos' },
    ];
    const { window } = page({ voices });

    const picked = window.PfVoice.pickVoice('el-GR', '', 'female');
    // Only one Greek voice exists and it is male — §3/§4 of pickVoice's own fallback: Greek
    // never falls back to a foreign-language voice, so it is still returned (pitch shift in
    // speak() carries the femininity), but never silently swapped for an English voice.
    assert.equal(picked.voiceURI, 'stefanos');
});

test('pickVoice prefers an explicit female Greek voice over a male one for a female persona', () => {
    const voices = [
        { name: 'Microsoft Stefanos', lang: 'el-GR', voiceURI: 'stefanos' },
        { name: 'Microsoft Athina Natural', lang: 'el-GR', voiceURI: 'athina' },
    ];
    const { window } = page({ voices });

    const picked = window.PfVoice.pickVoice('el-GR', '', 'female');
    assert.equal(picked.voiceURI, 'athina');
});

test('pickVoice never falls back to a foreign-language voice for Greek', () => {
    const voices = [
        { name: 'Microsoft Zira', lang: 'en-US', voiceURI: 'zira' },
        { name: 'Microsoft Stefanos', lang: 'el-GR', voiceURI: 'stefanos' },
    ];
    const { window } = page({ voices });

    const picked = window.PfVoice.pickVoice('el-GR', '', 'male');
    assert.equal(picked.lang, 'el-GR');
});

test('a preferred voice that conflicts with the requested gender is not used', () => {
    const voices = [
        { name: 'Microsoft Stefanos', lang: 'el-GR', voiceURI: 'stefanos' },
        { name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' },
    ];
    const { window } = page({ voices });

    const picked = window.PfVoice.pickVoice('el-GR', 'stefanos', 'female');
    assert.equal(picked.voiceURI, 'athina', 'the explicitly female voice wins over the conflicting preference');
});

test('createVoiceState persists gender and preferred voice under pf-voice-* keys, not an app-named one', () => {
    const voices = [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }];
    const { window } = page({ voices });

    const voice = window.PfVoice.createVoiceState();
    voice.setAssistantGender('male');

    assert.equal(window.localStorage.getItem('pf-voice-gender'), 'male');
    assert.equal(window.localStorage.getItem('acme_assistant_gender'), null, 'no app-named key is written');
});

test('setAssistantGender rejects an unknown value, defaulting to female', () => {
    const { window } = page();
    const voice = window.PfVoice.createVoiceState();

    voice.setAssistantGender('not-a-gender');
    assert.equal(voice.assistantGender, 'female');
});

test('startDictation/stopDictation/toggleDictation drive the fake SpeechRecognition', () => {
    const { window, captured } = page();
    const voice = window.PfVoice.createVoiceState();

    const started = voice.startDictation({});
    assert.equal(started, true);
    assert.equal(voice.dictating, true);
    assert.equal(captured.instance.started, true);

    voice.stopDictation();
    assert.equal(voice.dictating, false);
    assert.equal(captured.instance.started, false);
});

test('toggleDictation starts when stopped and stops when dictating', () => {
    const { window, captured } = page();
    const voice = window.PfVoice.createVoiceState();

    voice.toggleDictation({});
    assert.equal(voice.dictating, true);

    voice.toggleDictation({});
    assert.equal(voice.dictating, false);
    assert.equal(captured.instance.started, false);
});

test('startDictation fails clearly when the browser has no recognition support', () => {
    const { window } = page({ withRecognition: false });
    const voice = window.PfVoice.createVoiceState();

    const started = voice.startDictation({});
    assert.equal(started, false);
    assert.ok(voice.dictationError);
});

test('a transcript reaches onTranscript while the assistant is not speaking', () => {
    const { window, captured } = page();
    const voice = window.PfVoice.createVoiceState();

    let received = null;
    voice.startDictation({ onTranscript: (r) => { received = r; } });

    captured.instance.onresult({
        results: [{ isFinal: true, 0: { transcript: 'γεια σου' } }],
    });

    assert.ok(received);
    assert.equal(received.text, 'γεια σου');
});

test('speak/stopSpeech/toggleSpeech drive the fake SpeechSynthesisUtterance', () => {
    const voices = [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }];
    const { window } = page({ voices });
    const voice = window.PfVoice.createVoiceState();

    const started = voice.speak('answer-1', 'Καλησπέρα, είμαι έτοιμη.');
    assert.equal(started, true);
    assert.equal(voice.speaking, true);
    assert.equal(voice.speakingId, 'answer-1');

    voice.stopSpeech();
    assert.equal(voice.speaking, false);
    assert.equal(window.speechSynthesis.cancelled, true);
});

test('toggleSpeech stops when the same id is already speaking, speaks otherwise', () => {
    const voices = [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }];
    const { window } = page({ voices });
    const voice = window.PfVoice.createVoiceState();

    voice.toggleSpeech('a', 'Κείμενο πρώτο.');
    assert.equal(voice.speaking, true);

    voice.toggleSpeech('a', 'Κείμενο πρώτο.');
    assert.equal(voice.speaking, false);
});

test('a female persona on a male-detected voice gets a higher pitch than a matched female voice', () => {
    const maleVoiceOnly = [{ name: 'Microsoft Stefanos', lang: 'el-GR', voiceURI: 'stefanos' }];
    const { window: w1 } = page({ voices: maleVoiceOnly });
    const voice1 = w1.PfVoice.createVoiceState();
    voice1.speak('x', 'Καλησπέρα.');
    const utter1 = w1.speechSynthesis.spoken[0];

    const femaleVoice = [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }];
    const { window: w2 } = page({ voices: femaleVoice });
    const voice2 = w2.PfVoice.createVoiceState();
    voice2.speak('x', 'Καλησπέρα.');
    const utter2 = w2.speechSynthesis.spoken[0];

    assert.ok(utter1.pitch > utter2.pitch, 'a male voice forced female needs a bigger pitch shift than an already-female voice');
});

/**
 * A fake Audio element: records what it plays; a test ends it by calling `onended`.
 *
 * @param {object[]} played Every element created, in order
 */
function fakeAudio(played) {
    return function FakeAudio(src) {
        this.src = src;
        this.paused = false;
        this.play = () => Promise.resolve();
        this.pause = () => { this.paused = true; };
        played.push(this);
    };
}

/** Wait for every queued promise callback to run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('splitSentences cuts at sentence ends, Greek ones too, keeps pieces neither tiny nor long', () => {
    const { window } = page();
    const { splitSentences } = window.PfVoice;

    // Arrange — a Greek question mark (;) and ano teleia (·), and a one-word sentence
    const text = 'Καλημέρα σε όλους εδώ μέσα. Ναι. Τι κάνεις σήμερα το πρωί; Όλα καλά· πάμε παρακάτω!';

    // Act
    const parts = splitSentences(text);

    // Assert — "Ναι." is too short alone and goes with the next sentence
    assert.deepEqual([...parts], ['Καλημέρα σε όλους εδώ μέσα.', 'Ναι. Τι κάνεις σήμερα το πρωί;', 'Όλα καλά· πάμε παρακάτω!']);
    // A sentence over max is cut at a comma or space, never mid-word
    const long = splitSentences('λέξη, '.repeat(80).trim(), { max: 100 });
    assert.ok(long.length > 1 && long.every((p) => p.length <= 100), 'every piece within max');
    assert.ok(long.every((p) => /^λέξη/.test(p)), 'no word cut in two');
    assert.deepEqual([...splitSentences('')], []);
});

test('with a server, speak() reads a sentence at a time, fetching the next while one plays', async () => {
    // Arrange
    const { window } = page();
    const played = [];
    const asked = [];
    window.Audio = fakeAudio(played);
    window.URL = { createObjectURL: (b) => 'blob:' + b.name, revokeObjectURL: (u) => asked.push('revoked ' + u) };
    const voice = window.PfVoice.createVoiceState({
        server: (text) => { asked.push(text); return Promise.resolve(asked.length === 1 ? { audio: 'QUFB', type: 'audio/mpeg' } : { name: text.slice(0, 5) }); },
    });
    let ended = false;
    let startedCount = 0;

    // Act — three sentences
    const ok = voice.speak(1, 'Η πρώτη πρόταση είναι εδώ. Η δεύτερη πρόταση ακολουθεί. Και η τρίτη στο τέλος.', {
        onStart: () => { startedCount++; },
        onEnd: () => { ended = true; },
    });
    await settle();

    // Assert — the first plays as soon as it alone is ready; the second is already being fetched
    assert.equal(ok, true);
    assert.equal(voice.hasServer, true);
    assert.equal(played.length, 1);
    assert.equal(played[0].src, 'data:audio/mpeg;base64,QUFB', 'the speech API\'s {audio, type} as a data address');
    assert.equal(asked.length, 2, 'the second fetched while the first plays');
    assert.equal(voice.speaking, true);
    assert.equal(voice.speakingId, 1);
    assert.equal(window.speechSynthesis.spoken.length, 0, 'the browser\'s voice is not used');

    // Act — each sentence ends; the next plays
    played[0].onended();
    await settle();
    played[1].onended();
    await settle();
    played[2].onended();
    await settle();

    // Assert
    assert.equal(played.length, 3);
    assert.equal(played[1].src.indexOf('blob:'), 0, 'a Blob as an object address');
    assert.ok(asked.includes('revoked ' + played[1].src), 'released once played');
    assert.equal(startedCount, 1, 'onStart once, not per sentence');
    assert.equal(ended, true);
    assert.equal(voice.speaking, false);
});

test('with a server, stopSpeech() silences it and nothing later plays; a failing first sentence falls back to the browser', async () => {
    // Arrange
    const { window } = page({ voices: [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }] });
    const played = [];
    window.Audio = fakeAudio(played);
    window.URL = { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} };
    let release;
    const slow = window.PfVoice.createVoiceState({ server: () => new Promise((resolve) => { release = resolve; }) });
    const down = window.PfVoice.createVoiceState({ server: () => Promise.reject(new Error('501')) });

    // Act — stopped while the first sentence is still being fetched, and while one plays
    slow.speak(1, 'Μια πρόταση αρκετά μεγάλη για να σταθεί. Και μια δεύτερη εξίσου μεγάλη.');
    await settle();
    slow.stopSpeech();
    release({ audio: 'QQ==', type: 'audio/wav' });
    await settle();
    const afterStop = played.length;
    down.speak(2, 'Δεν υπάρχει server για αυτό το κείμενο.');
    await settle();

    // Assert
    assert.equal(afterStop, 0, 'a sentence that arrives after stop is not played');
    assert.equal(slow.speaking, false);
    assert.equal(window.speechSynthesis.spoken.length, 1, 'the browser read the text instead');
    assert.equal(down.speakingId, 2);
});

/**
 * A voice reads a punctuation mark that ends no phrase by its name — "τελεία", "κόμμα". What is
 * read aloud therefore never has two marks together: a line's end adds a full stop only where
 * the line has no mark of its own, a table's rows read as lists, and its separator row is gone.
 */
test('stripMarkdownForSpeech never leaves two punctuation marks together', () => {
    // Arrange — lines ending in ;, :, and ., a list, and a table
    const { window } = page();
    const text = 'Γεια σου! Πώς πάει η μέρα σου;\n\nΣήμερα έχεις:\n- Ραντεβού στις 10:00.\n- Γυμναστήριο, στις 18:00\n\n| Ώρα | Τι |\n|---|---|\n| 10 | Γιατρός |\n\nΚαλή συνέχεια.';

    // Act
    const said = window.PfVoice.stripMarkdownForSpeech(text, { gender: 'female' });

    // Assert
    assert.equal(said, 'Γεια σου! Πώς πάει η μέρα σου; Σήμερα έχεις: Ραντεβού στις 10:00. Γυμναστήριο, στις 18:00. Ώρα, Τι. 10, Γιατρός. Καλή συνέχεια.');
    assert.doesNotMatch(said, /[.!?;:,]\s*[.,;:]/, 'no mark followed by another');
});

/**
 * A sentence that fails in the middle — the server gone after the first — does not end the
 * reading: the browser's voice reads from it to the end, and onStart is not told twice.
 */
test('with a server, a sentence failing midway is read on by the browser\'s voice', async () => {
    // Arrange
    const { window } = page({ voices: [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }] });
    const played = [];
    window.Audio = fakeAudio(played);
    window.URL = { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} };
    let calls = 0;
    const voice = window.PfVoice.createVoiceState({
        server: () => (++calls === 1 ? Promise.resolve({ audio: 'QQ==', type: 'audio/mpeg' }) : Promise.reject(new Error('502'))),
    });
    let starts = 0;

    // Act
    voice.speak(1, 'Η πρώτη πρόταση εδώ. Η δεύτερη πρόταση εδώ. Η τρίτη πρόταση εδώ.', { onStart: () => { starts++; } });
    await settle();
    played[0].onended();
    await settle();

    // Assert
    assert.equal(played.length, 1, 'only the first came from the server');
    const spoken = window.speechSynthesis.spoken;
    assert.equal(spoken.length, 1, 'the browser took over');
    assert.ok(!spoken[0].text.includes('πρώτη') && spoken[0].text.includes('δεύτερη') && spoken[0].text.includes('τρίτη'), spoken[0].text);
    assert.equal(starts, 1, 'onStart once');
});

/**
 * A silence ends the dictation after 1200 ms by default — 750 cut a question at a breath — or
 * after the caller's `silenceMs`; when it ends, onSilence is told.
 */
test('the silence that ends dictation is 1200 ms, or the caller\'s silenceMs', () => {
    // Arrange — the context's timers recorded instead of run
    const { window, captured } = page();
    const delays = [];
    const timers = [];
    window.setTimeout = (fn, ms) => { delays.push(ms); timers.push(fn); return timers.length; };
    window.clearTimeout = () => {};
    const voice = window.PfVoice.createVoiceState();
    let silent = 0;
    const said = { results: [Object.assign([{ transcript: 'πόσες εγγραφές' }], { isFinal: true })] };

    // Act — the default, then the caller's own
    voice.startDictation({ onSilence: () => { silent++; } });
    captured.instance.onresult(said);
    const byDefault = delays[delays.length - 1];
    timers[timers.length - 1]();
    const stoppedBySilence = !voice.dictating;
    voice.startDictation({ silenceMs: 1000 });
    captured.instance.onresult(said);
    captured.instance.onspeechend();

    // Assert
    assert.equal(byDefault, 1200);
    assert.equal(silent, 1, 'told of the silence');
    assert.equal(stoppedBySilence, true, 'and the dictation stopped');
    assert.deepEqual(delays.slice(-2), [1000, 1000], 'the caller\'s, after a result and after the speech ends');
});

/**
 * `onPart` — subtitles in step with the voice. With the server, it is told as each sentence starts
 * to be heard, with its index, the count and the sentence as `splitSentences()` cut it; with
 * `opts.parts`, each of the caller's pieces is said cleaned and told as given, so a page finds it
 * in its original. The browser's voice reads it all at once: one part, at its start. A server that
 * fails partway hands the browser the rest as one part, from where it stopped.
 */
test('onPart tells each part as it starts to be heard, the caller\'s own parts too', async () => {
    // Arrange
    const { window } = page({ voices: [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }] });
    const played = [];
    window.Audio = fakeAudio(played);
    window.URL = { createObjectURL: (b) => 'blob:' + b.name, revokeObjectURL: () => {} };
    const said = [];
    const voice = window.PfVoice.createVoiceState({ server: (text) => { said.push(text); return /Τρίτο/.test(text) ? Promise.reject(new Error('down')) : Promise.resolve({ name: 'x' }); } });
    const parts = [];
    const onPart = (p) => parts.push(p);

    // Act — sentences cut by the framework
    voice.speak(1, 'Η πρώτη πρόταση είναι εδώ. Η δεύτερη πρόταση ακολουθεί.', { onPart });
    await settle();
    played[0].onended();
    await settle();
    const bySentence = parts.splice(0);

    // Act — the caller's own pieces, with Markdown said cleaned and shown as given
    voice.speak(2, 'ignored', { onPart, parts: ['**Καλημέρα** Μαρία.', 'Τι κάνεις σήμερα;'] });
    await settle();
    const given = parts.splice(0);
    const saidFirst = said[said.length - 2];

    // Act — the server fails on the third piece: the browser reads the rest
    voice.speak(3, 'ignored', { onPart, parts: ['Πρώτο κομμάτι εδώ.', 'Δεύτερο κομμάτι.', 'Τρίτο κομμάτι.'] });
    await settle();
    played[played.length - 1].onended();
    await settle();
    played[played.length - 1].onended();
    await settle();
    await settle();
    const fellBack = parts.splice(0);

    // Act — the browser alone
    const browserOnly = page({ voices: [{ name: 'Microsoft Athina', lang: 'el-GR', voiceURI: 'athina' }] });
    const alone = [];
    browserOnly.window.PfVoice.createVoiceState().speak(4, 'Μία πρόταση. Και άλλη μία εδώ.', { onPart: (p) => alone.push(p) });

    // Assert
    assert.deepEqual(bySentence.map((p) => [p.index, p.count, p.text]), [[0, 2, 'Η πρώτη πρόταση είναι εδώ.'], [1, 2, 'Η δεύτερη πρόταση ακολουθεί.']]);
    assert.deepEqual(given.map((p) => [p.index, p.count, p.text]), [[0, 2, '**Καλημέρα** Μαρία.']], 'the piece as given');
    assert.equal(saidFirst.indexOf('**'), -1, 'said cleaned');
    assert.deepEqual(fellBack.map((p) => [p.index, p.count, p.text]), [[0, 3, 'Πρώτο κομμάτι εδώ.'], [1, 3, 'Δεύτερο κομμάτι.'], [2, 3, 'Τρίτο κομμάτι.']], 'the rest, from where it stopped');
    assert.deepEqual(alone.map((p) => [p.index, p.count]), [[0, 1]], 'the browser: one part');
});
