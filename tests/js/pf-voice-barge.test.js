/**
 * `pf-voice.js`: barge-in by voice level (FW-439) and the level of the voice for a mouth (FW-440).
 *
 * What has to hold: while the voice speaks with `bargeIn`, the microphone is open with echo
 * cancellation; a sound over the threshold for its minimum time stops the voice, tells onBargeIn
 * and starts dictation when asked; a short sound does not; the microphone is let go when the
 * voice ends. `onLevel` pulses at each word of the browser's voice and ends at 0; through the
 * speech server it follows the audio itself.
 *
 * Run (bounded):
 *   timeout 60 node --max-old-space-size=512 --test --test-force-exit tests/js/pf-voice-barge.test.js
 */
'use strict';

const { test, beforeEach, afterEach, mock } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const JS = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js');
const LIMIT = { timeout: 5000 };

let micLevel;  // what the microphone hears
let outLevel;  // what the voice's own audio holds
let released;  // microphone tracks stopped

/** Let promises settle. */
const settle = async () => {
    for (let i = 0; i < 6; i++) {
        await new Promise((done) => setImmediate(done));
    }
};

/** Move the clock in steps, letting each step's work run. */
async function tick(ms) {
    for (let done = 0; done < ms; done += 50) {
        mock.timers.tick(50);
        await settle();
    }
}

/** A page with the browser's voice (or a speech server), a microphone and audio analysers. */
function page({ server = null, listen = null } = {}) {
    const { window } = createDom();
    window.setInterval = setInterval;
    window.clearInterval = clearInterval;
    window.Date = Date;
    window.Float32Array = Float32Array;
    window.Blob = class { constructor(parts, opts) { this.type = opts.type; } };
    const synth = {
        current: null,
        cancelled: 0,
        getVoices: () => [{ name: 'Athina', lang: 'el-GR', voiceURI: 'athina' }],
        speak(u) { synth.current = u; if (u.onstart) { u.onstart(); } },
        cancel() { synth.cancelled++; },
        addEventListener() {},
    };
    window.speechSynthesis = synth;
    window.SpeechSynthesisUtterance = class { constructor(text) { this.text = text; } };
    window.navigator = {
        userAgent: 'Mozilla/5.0 Firefox/120.0',
        mediaDevices: { getUserMedia: (c) => { (window.asks = window.asks || []).push(c); window.asked = c; return Promise.resolve({ getTracks: () => [{ stop: () => { released++; } }] }); } },
    };
    window.MediaRecorder = class {
        constructor() { this.state = 'inactive'; this.mimeType = 'audio/webm'; }
        start() { this.state = 'recording'; }
        stop() { this.state = 'inactive'; this.onstop(); }
    };
    window.AudioContext = class {
        constructor() { this.destination = {}; }
        createAnalyser() { return { fftSize: 0, connect() {}, getFloatTimeDomainData: (buf) => buf.fill(this.reading()) }; }
        createMediaStreamSource() { this.reading = () => micLevel; return { connect() {}, disconnect() {} }; }
        createMediaElementSource() { this.reading = () => outLevel; return { connect() {}, disconnect() {} }; }
        close() {}
    };
    window.Audio = class { constructor(src) { this.src = src; } play() { return Promise.resolve(); } pause() {} };
    window.URL.createObjectURL = () => 'blob:x';
    loadScript(path.join(JS, 'pf-speech-sanitizer.js'), window);
    loadScript(path.join(JS, 'pf-voice.js'), window);
    const voice = window.PfVoice.createVoiceState({ server, listen });

    return { window, voice, synth };
}

beforeEach(() => {
    mock.timers.enable({ apis: ['setTimeout', 'setInterval', 'Date'] });
    micLevel = 0;
    outLevel = 0;
    released = 0;
});

afterEach(() => {
    mock.timers.reset();
});

test('barge-in: a sound long enough stops the voice, tells it, and starts dictation; a short one does not', LIMIT, async () => {
    // Arrange
    const { window, voice, synth } = page({ listen: () => Promise.resolve('σταμάτα') });
    const told = [];
    const dictated = [];

    // Act
    voice.speak('m1', 'Καλημέρα σε όλους, σήμερα έχει ήλιο.', {
        bargeIn: { threshold: 0.1, minMs: 300, onBargeIn: (e) => told.push(e.byLevel), dictate: { onEnd: () => dictated.push('end') } },
    });
    await settle();
    micLevel = 0.5;               // a cough
    await tick(150);
    micLevel = 0;
    await tick(300);
    const afterCough = voice.speaking;
    micLevel = 0.5;               // the person speaks
    await tick(400);

    // Assert
    assert.equal(JSON.stringify(window.asks[0].audio), JSON.stringify({ echoCancellation: true, noiseSuppression: true, autoGainControl: true }), 'the barge-in microphone, with echo cancellation');
    assert.equal(afterCough, true, 'a short sound does not stop it');
    assert.deepEqual(told, [true], 'told once, by the level');
    assert.ok(synth.cancelled > 0, 'the voice stopped');
    assert.equal(voice.speaking, false);
    assert.ok(released >= 1, 'the barge-in microphone let go');
    assert.equal(voice.dictating, true, 'dictation started for what the person says');
});

test('without barge-in the microphone is never opened; when the voice ends it is let go', LIMIT, async () => {
    // Arrange
    const { window, voice, synth } = page();

    // Act
    voice.speak('m1', 'Γεια σου.');
    await settle();
    const askedWithout = window.asked;
    voice.speak('m2', 'Γεια σου ξανά.', { bargeIn: {} });
    await settle();
    synth.current.onend();
    await settle();

    // Assert
    assert.equal(askedWithout, undefined);
    assert.equal(released, 1, 'let go when it ended');
});

test('onLevel: a pulse at each word of the browser\'s voice, and 0 at its end', LIMIT, async () => {
    // Arrange
    const { voice, synth } = page();
    const levels = [];

    // Act
    voice.speak('m1', 'Ένα δύο τρία.', { onLevel: (l) => levels.push(l) });
    synth.current.onboundary();
    await tick(200);
    synth.current.onboundary();
    synth.current.onend();

    // Assert
    assert.deepEqual(levels, [0.8, 0, 0.8, 0]);
});

test('onLevel through the speech server follows the audio itself', LIMIT, async () => {
    // Arrange
    const { voice } = page({ server: () => Promise.resolve({ audio: 'AAAA', type: 'audio/mpeg' }) });
    const levels = [];

    // Act
    voice.speak('m1', 'Μία πρόταση.', { onLevel: (l) => levels.push(l) });
    await settle();
    outLevel = 0.1;
    await tick(100);
    outLevel = 0.5;
    await tick(100);
    voice.stopSpeech();

    // Assert
    assert.ok(levels.some((l) => Math.abs(l - 0.4) < 1e-6), 'the audio\'s own loudness, scaled');
    assert.ok(levels.includes(1), 'held to 1');
    assert.equal(levels[levels.length - 1], 0, 'quiet when it stops');
});
