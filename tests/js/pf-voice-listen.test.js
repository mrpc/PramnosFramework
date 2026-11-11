/**
 * `pf-voice.js`: dictation by the server (FW-437), where the browser recognises no speech.
 *
 * What has to hold: without SpeechRecognition but with `listen` and a recorder, dictation works;
 * the microphone is asked for with echo cancellation; a silence after speech ends the recording,
 * and its words come back through the same onTranscript, onSilence and onEnd as the browser's; a
 * recording with no speech is not sent; stopping by hand still sends what was said; the
 * microphone is always let go; a refused microphone says so; a failing server is an error, not
 * silence.
 *
 * Run (bounded):
 *   timeout 60 node --max-old-space-size=512 --test --test-force-exit tests/js/pf-voice-listen.test.js
 */
'use strict';

const { test, beforeEach, afterEach, mock } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const JS = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js');
const LIMIT = { timeout: 5000 };

let level;    // what the fake analyser hears: the amplitude of every sample
let released; // tracks stopped
let sent;     // what reached listen()

/** Let promises settle. */
const settle = async () => {
    for (let i = 0; i < 6; i++) {
        await new Promise((done) => setImmediate(done));
    }
};

/** Move the clock in steps, letting each step's work run. */
async function tick(ms) {
    for (let done = 0; done < ms; done += 100) {
        mock.timers.tick(100);
        await settle();
    }
}

/** A page without SpeechRecognition, with a recorder, a microphone and an analyser. */
function page({ refuse = false, server = (blob) => Promise.resolve({ text: 'Καλημέρα' }) } = {}) {
    const { window } = createDom();
    window.setInterval = setInterval;
    window.clearInterval = clearInterval;
    // The script's own Date would be the real clock: the mocked one, so a silence is measured on it.
    window.Date = Date;
    window.Blob = class { constructor(parts, opts) { this.parts = parts; this.type = opts.type; } };
    window.Float32Array = Float32Array;
    window.navigator = {
        userAgent: 'Mozilla/5.0 Firefox/120.0',
        mediaDevices: {
            getUserMedia: (constraints) => {
                window.asked = constraints;
                return refuse ? Promise.reject(new Error('no')) : Promise.resolve({ getTracks: () => [{ stop: () => { released++; } }] });
            },
        },
    };
    window.MediaRecorder = class {
        constructor() { this.state = 'inactive'; this.mimeType = 'audio/webm;codecs=opus'; }
        start() { this.state = 'recording'; this.ondataavailable({ data: { size: 10 } }); }
        stop() { this.state = 'inactive'; this.onstop(); }
    };
    window.AudioContext = class {
        createAnalyser() { return { fftSize: 0, getFloatTimeDomainData: (buf) => buf.fill(level) }; }
        createMediaStreamSource() { return { connect() {} }; }
        close() {}
    };
    window.speechSynthesis = { getVoices: () => [], speak() {}, cancel() {}, addEventListener() {} };
    loadScript(path.join(JS, 'pf-speech-sanitizer.js'), window);
    loadScript(path.join(JS, 'pf-voice.js'), window);
    const voice = window.PfVoice.createVoiceState({ listen: (blob, info) => { sent.push({ blob, info }); return server(blob); } });

    return { window, voice };
}

beforeEach(() => {
    mock.timers.enable({ apis: ['setTimeout', 'setInterval', 'Date'] });
    level = 0;
    released = 0;
    sent = [];
});

afterEach(() => {
    mock.timers.reset();
});

test('no browser recognition: speech, then a silence, and the words come back as the browser\'s would', LIMIT, async () => {
    // Arrange
    const { window, voice } = page();
    const told = [];

    // Act
    const started = voice.startDictation({
        onTranscript: (t) => told.push(['transcript', t.text]),
        onSilence: () => told.push(['silence']),
        onEnd: () => told.push(['end']),
    });
    await settle();
    level = 0.2;                 // speaking
    await tick(1000);
    level = 0;                   // quiet
    await tick(1500);

    // Assert
    assert.equal(voice.canDictate, true, 'dictation works without the browser\'s recognition');
    assert.equal(started, true);
    assert.equal(JSON.stringify(window.asked), JSON.stringify({ audio: { echoCancellation: true, noiseSuppression: true } }));
    assert.equal(sent.length, 1, 'the recording went to the server once');
    assert.equal(sent[0].blob.type, 'audio/webm;codecs=opus');
    assert.ok(sent[0].info.seconds >= 2, 'how long it was, for the log\'s cost');
    assert.deepEqual(told, [['transcript', 'Καλημέρα'], ['end'], ['silence']]);
    assert.equal(released, 1, 'the microphone let go');
});

test('silence with no speech at all sends nothing; stopping by hand sends what was said', LIMIT, async () => {
    // Arrange
    const { voice } = page();
    const ends = [];

    // Act — nothing said, stopped
    voice.startDictation({ onEnd: () => ends.push('quiet') });
    await tick(3000);
    voice.stopDictation();
    await settle();
    // Act — something said, stopped by hand before the silence
    voice.startDictation({ onEnd: () => ends.push('spoke') });
    await settle();
    level = 0.3;
    await tick(500);
    voice.stopDictation();
    await settle();

    // Assert
    assert.deepEqual(ends, ['quiet', 'spoke']);
    assert.equal(sent.length, 1, 'only the recording with speech in it');
    assert.equal(released, 2, 'the microphone let go both times');
});

test('a refused microphone and a failing server are errors, said', LIMIT, async () => {
    // Arrange
    const { voice } = page({ refuse: true });
    const errors = [];

    // Act
    voice.startDictation({ onError: (e) => errors.push(e.error) });
    await settle();
    const failing = page({ server: () => Promise.reject(new Error('502')) }).voice;
    failing.startDictation({ onError: (e) => errors.push(e.error) });
    await settle();
    level = 0.3;
    await tick(500);
    level = 0;
    await tick(1500);

    // Assert
    assert.deepEqual(errors, ['not-allowed', 'server']);
    assert.match(voice.getDictationError ? voice.getDictationError() : 'Microphone permission was denied', /Microphone/);
});
