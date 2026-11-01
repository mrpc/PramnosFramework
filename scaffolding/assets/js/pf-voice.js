/**
 * Voice for Ask AI: dictation (speech to text) and read-aloud (text to speech), over the
 * browser's native Web Speech API — no server side, per the Roadmap's Phase 1
 * (docs/Pramnos_LLM_Analyst_Guide.md "## Voice"). Chrome/Chromium carries recognition through
 * Google's speech service; Firefox has none.
 *
 * `window.PfVoice.createVoiceState()` returns one controller — dictation start/stop/toggle,
 * speak/stop/toggle, voice picking, and barge-in (the assistant stops talking the instant the
 * user says an interrupt word while it is speaking, and the speaker's own voice leaking back
 * into the microphone is filtered out rather than transcribed as a new question). Depends on
 * `pf-speech-sanitizer.js`, loaded first.
 *
 * Preferences — the assistant's persona gender and the chosen synthesis voice — persist in
 * `localStorage` under `pf-voice-gender` / `pf-voice-preferred`, namespaced to the framework
 * rather than any one application, since two applications can share an origin.
 */
(function () {
    'use strict';

    var sanitizer = window.PfSpeechSanitizer || {};

    function isSpeechRecognitionSupported() {
        return typeof window !== 'undefined' && Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);
    }

    function isSpeechSynthesisSupported() {
        return typeof window !== 'undefined' && typeof window.speechSynthesis !== 'undefined' && typeof window.SpeechSynthesisUtterance !== 'undefined';
    }

    function isChromeBrowser() {
        if (typeof navigator === 'undefined') {
            return false;
        }
        var ua = navigator.userAgent;
        return /Chrome|Chromium/i.test(ua) && !/Edg|OPR|Brave/i.test(ua);
    }

    function isFirefoxBrowser() {
        return typeof navigator !== 'undefined' && /Firefox/i.test(navigator.userAgent);
    }

    /**
     * Strips Markdown so speech synthesis reads naturally rather than spelling out symbols and
     * syntax, then routes through the Greek or English sanitizer by whether the text has Greek
     * characters.
     *
     * @param {string} text
     * @param {{ gender?: string, concise?: boolean }} [options]
     * @returns {string}
     */
    function stripMarkdownForSpeech(text, options) {
        if (!text) {
            return '';
        }
        var opts = options || {};
        var gender = opts.gender || 'female';

        var clean = opts.concise ? sanitizer.formatSpokenDigest(String(text), { gender: gender }) : String(text);
        var hasGreek = /[Ͱ-Ͽἀ-῿]/.test(clean);
        var codeSnippetLabel = hasGreek ? ' [απόσπασμα κώδικα] ' : ' [code snippet] ';

        clean = clean.replace(/```[\s\S]*?```/g, codeSnippetLabel);
        clean = clean.replace(/`([^`]+)`/g, '$1');
        clean = clean.replace(/!\[([^\]]*)\]\([^)]+\)/g, '');
        clean = clean.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
        clean = clean.replace(/^#{1,6}\s+/gm, '');
        clean = clean.replace(/(\*\*|__)(.*?)\1/g, '$2');
        clean = clean.replace(/(\*|_)(.*?)\1/g, '$2');
        clean = clean.replace(/~~(.*?)~~/g, '$1');
        clean = clean.replace(/^>\s+/gm, '');
        clean = clean.replace(/^(?:[-*_]\s*){3,}$/gm, '');
        clean = clean.replace(/^\s*[-*+]\s+/gm, '');
        clean = clean.replace(/^\s*\d+\.\s+/gm, '');
        clean = clean.replace(/\|/g, ', ');
        clean = clean.replace(/^,\s*[-:]+.*$/gm, '');
        clean = clean.replace(/\n+/g, '. ');
        clean = clean.replace(/\s{2,}/g, ' ');

        clean = hasGreek
            ? sanitizer.prepareGreekSpeechText(clean, { gender: gender })
            : sanitizer.prepareEnglishSpeechText(clean);

        return clean.trim();
    }

    /**
     * 'el-GR' when text has Greek characters, else 'en-US'.
     *
     * @param {string} text
     * @returns {string}
     */
    function detectLanguage(text) {
        if (!text) {
            return 'el-GR';
        }
        return /[Ͱ-Ͽἀ-῿]/.test(text) ? 'el-GR' : 'en-US';
    }

    /**
     * Detects whether a synthesis voice is female, male, or unknown — from its name/voiceURI,
     * matching Microsoft/Apple/Google naming conventions, ~60 Greek/English first names, and
     * eSpeak/Festival/Speech-Dispatcher engines (inherently male without a female marker).
     *
     * @param {SpeechSynthesisVoice|{name:string,voiceURI?:string}|null} voice
     * @returns {'female'|'male'|'unknown'}
     */
    function getVoiceGender(voice) {
        if (!voice) {
            return 'unknown';
        }
        var name = (voice.name || '').toLowerCase();
        var uri = (voice.voiceURI || '').toLowerCase();
        var combined = name + ' ' + uri;

        if (/(?<!\p{L})(male|man|boy|άνδρας|ανδρας|άντρας|αντρας)(?!\p{L})/iu.test(combined) || combined.indexOf('+m') !== -1) {
            return 'male';
        }
        if (/(?<!\p{L})(stefanos|στέφανος|στεφανος|nestoras|νέστορας|νεστορας|george|georgios|γιώργος|γιωργος|γεώργιος|γεωργιος|nikos|nikolaos|νίκος|νικος|νικόλαος|νικολαος|dimitris|dimitrios|δημήτρης|δημητρης|δημήτριος|δημητριος|panagiotis|παναγιώτης|παναγιωτης|kostas|konstantinos|κώστας|κωστας|κωνσταντίνος|κωνσταντινος|alex|alexandros|alexis|αλέξανδρος|αλεξανδρος|αλέξης|αλεξης|petros|πέτρος|πετρος|giannis|ioannis|γιάννης|γιαννης|ιωάννης|ιωαννης|christos|χρήστος|χρηστος|vasilis|vasileios|βασίλης|βασιλης|βασίλειος|βασιλειος|antonis|antonios|αντώνης|αντωνης|αντώνιος|αντωνιος|michalis|michael|μιχάλης|μιχαλης|μιχαήλ|μιχαηλ|theodoros|θεόδωρος|θεοδωρος|thodoris|θοδωρής|θοδωρης|elias|ηλίας|ηλιας|evangelos|vangelis|ευάγγελος|ευαγγελος|βαγγέλης|βαγγελης|apostolos|απόστολος|αποστολος|guy|david|ryan|christopher|eric|mark|daniel|tom|oliver|brian|william|richard|fred|john|james|robert|charles|thomas)(?!\p{L})/iu.test(combined)) {
            return 'male';
        }
        if (/(?<!\p{L})(female|woman|girl|γυναίκα|γυναικα)(?!\p{L})/iu.test(combined) || combined.indexOf('+f') !== -1) {
            return 'female';
        }
        if (/(?<!\p{L})(athina|αθηνά|αθηνα|melina|μελίνα|μελινα|eleni|ελένη|ελενη|maria|μαρία|μαρια|sofia|σοφία|σοφια|katerina|κατερίνα|κατερινα|dimitra|δήμητρα|δημητρα|ioanna|ιωάννα|ιωαννα|anna|άννα|αννα|georgia|γεωργία|γεωργια|vasiliki|βασιλική|βασιλικη|despina|δέσποινα|δεσποινα|christina|χριστίνα|χριστινα|evangelia|ευαγγελία|ευαγγελια|zoe|ζωή|ζωη|panagiota|παναγιώτα|παναγιωτα|alexandra|αλεξάνδρα|αλεξανδρα|antonia|αντωνία|αντωνια|theodora|θεοδώρα|θεοδωρα|foteini|φωτεινή|φωτεινη|olga|όλγα|ολγα|konstantina|κωνσταντίνα|κωνσταντινα|samantha|jenny|aria|sonia|sara|ava|emma|michelle|ana|clara|elizabeth|victoria|zira|susan|hazel|karen|fiona|kate|serena|moira|tessa|cathy|helen|stephanie|lisa|laura|alice)(?!\p{L})/iu.test(combined)) {
            return 'female';
        }
        if (/(espeak|speech-dispatcher|festival|mbrola)/i.test(combined)) {
            return 'male';
        }
        return 'unknown';
    }

    /**
     * Whether a voice is Greek, by BCP-47 tag, ISO code, or name.
     *
     * @param {SpeechSynthesisVoice|{name:string,lang:string,voiceURI?:string}|null} voice
     * @returns {boolean}
     */
    function isGreekVoice(voice) {
        if (!voice) {
            return false;
        }
        var l = (voice.lang || '').toLowerCase();
        var n = (voice.name || '').toLowerCase();
        var u = (voice.voiceURI || '').toLowerCase();
        return l.indexOf('el') === 0 || l.indexOf('gr') === 0 || l.indexOf('ell') !== -1
            || n.indexOf('greek') !== -1 || n.indexOf('ελληνικ') !== -1 || n.indexOf('(greece)') !== -1
            || u.indexOf('el-gr') !== -1 || u.indexOf('el_gr') !== -1;
    }

    /**
     * The most suitable available synthesis voice for a language and persona gender — a female
     * persona never gets a voice `getVoiceGender()` reports as male, and vice versa; Greek never
     * falls back to a foreign-language voice.
     *
     * @param {string} lang
     * @param {string} [preferredVoiceURI]
     * @param {string} [gender]
     * @returns {SpeechSynthesisVoice|null}
     */
    function pickVoice(lang, preferredVoiceURI, gender) {
        if (!isSpeechSynthesisSupported()) {
            return null;
        }
        var voices = window.speechSynthesis.getVoices();
        if (!voices || voices.length === 0) {
            return null;
        }

        gender = gender || 'female';
        var targetLang = lang || 'el-GR';
        var prefix = targetLang.slice(0, 2).toLowerCase();

        var langVoices = voices.filter(function (v) {
            return prefix === 'el' ? isGreekVoice(v) : (v.lang || '').toLowerCase().indexOf(prefix) === 0;
        });

        if (langVoices.length === 0) {
            var defaultVoice = voices.find(function (v) { return v.default; });
            if (defaultVoice) {
                var defGender = getVoiceGender(defaultVoice);
                var defConflicting = (gender === 'female' && defGender === 'male') || (gender === 'male' && defGender === 'female');
                if (!defConflicting) {
                    return defaultVoice;
                }
            }
            langVoices = voices;
        }

        var explicitFemaleGreek = (prefix === 'el' && gender === 'female')
            ? langVoices.find(function (v) { return getVoiceGender(v) === 'female'; })
            : null;

        if (preferredVoiceURI) {
            var found = langVoices.find(function (v) { return v.voiceURI === preferredVoiceURI || v.name === preferredVoiceURI; });
            if (found) {
                var vGender = getVoiceGender(found);
                var isConflicting = (gender === 'female' && vGender === 'male') || (gender === 'male' && vGender === 'female');
                var bypassForExplicitFemale = gender === 'female' && explicitFemaleGreek && vGender !== 'female';
                if (!isConflicting && !bypassForExplicitFemale) {
                    return found;
                }
            }
        }

        if (prefix === 'el') {
            if (gender === 'male') {
                var maleGreek = langVoices.find(function (v) { return getVoiceGender(v) === 'male'; });
                if (maleGreek) {
                    return maleGreek;
                }
                var nonFemale = langVoices.find(function (v) { return getVoiceGender(v) !== 'female'; });
                if (nonFemale) {
                    return nonFemale;
                }
            } else if (gender === 'female') {
                if (explicitFemaleGreek) {
                    return explicitFemaleGreek;
                }
                var naturalFemaleGreek = langVoices.find(function (v) { return /natural/i.test(v.name) && getVoiceGender(v) !== 'male'; });
                if (naturalFemaleGreek) {
                    return naturalFemaleGreek;
                }
                var nonMale = langVoices.find(function (v) { return getVoiceGender(v) !== 'male'; });
                if (nonMale) {
                    return nonMale;
                }
            } else {
                var naturalGreek = langVoices.find(function (v) { return /natural/i.test(v.name); });
                if (naturalGreek) {
                    return naturalGreek;
                }
            }
        } else if (prefix === 'en') {
            if (gender === 'male') {
                var maleEnglish = langVoices.find(function (v) { return getVoiceGender(v) === 'male'; });
                if (maleEnglish) {
                    return maleEnglish;
                }
                var enNonFemale = langVoices.find(function (v) { return getVoiceGender(v) !== 'female'; });
                if (enNonFemale) {
                    return enNonFemale;
                }
            } else if (gender === 'female') {
                var femaleEnglish = langVoices.find(function (v) { return getVoiceGender(v) === 'female'; });
                if (femaleEnglish) {
                    return femaleEnglish;
                }
                var enNonMale = langVoices.find(function (v) { return getVoiceGender(v) !== 'male'; });
                if (enNonMale) {
                    return enNonMale;
                }
            }
        }

        var nonConflicting = langVoices.find(function (v) {
            var vg = getVoiceGender(v);
            return gender === 'female' ? vg !== 'male' : (gender === 'male' ? vg !== 'female' : true);
        });
        if (nonConflicting) {
            return nonConflicting;
        }

        // Greek never falls back to a foreign-language voice — pitch modulation in speak()
        // handles feminine/masculine tone on whatever Greek voice is actually installed.
        if (prefix === 'el' && langVoices.length > 0) {
            return langVoices[0];
        }

        if (gender === 'female') {
            var anyNonMaleInLang = langVoices.find(function (v) { return getVoiceGender(v) !== 'male'; });
            if (anyNonMaleInLang) {
                return anyNonMaleInLang;
            }
        }

        return langVoices[0] || voices.find(function (v) { return v.default; }) || voices[0] || null;
    }

    var STORAGE_GENDER = 'pf-voice-gender';
    var STORAGE_PREFERRED = 'pf-voice-preferred';

    function storageGet(key, fallback) {
        try {
            return window.localStorage.getItem(key) || fallback;
        } catch (e) {
            return fallback;
        }
    }

    function storageSet(key, value) {
        try {
            if (value) {
                window.localStorage.setItem(key, value);
            } else {
                window.localStorage.removeItem(key);
            }
        } catch (e) {
            // Private mode: the preference simply doesn't persist.
        }
    }

    /**
     * One voice controller: dictation, read-aloud, voice picking, barge-in. Plain object with
     * getters/setters — no reactivity framework assumed, so it works equally from a vanilla
     * `pf-chat.js` wiring or a Svelte/React component that reads its properties itself.
     *
     * @returns {object}
     */
    function createVoiceState() {
        var dictating = false;
        var interim = '';
        var dictationError = null;
        var speakingId = null;
        var speaking = false;
        var paused = false;
        var autoRead = false;
        var conversationMode = false;
        var lang = 'el-GR';
        var rate = 1.18;
        var assistantGender = storageGet(STORAGE_GENDER, 'female');
        var currentlySpeakingText = '';
        var selectedVoiceURI = storageGet(STORAGE_PREFERRED, '');
        var availableVoices = [];

        var activeRecognition = null;
        var activeSilenceTimer = null;
        var lastSpeechEndTime = 0;

        var hasRecognition = isSpeechRecognitionSupported();
        var hasSynthesis = isSpeechSynthesisSupported();
        var isChrome = isChromeBrowser();
        var isFirefox = isFirefoxBrowser();

        function setAssistantGender(newGender) {
            assistantGender = (newGender === 'male' || newGender === 'neutral') ? newGender : 'female';
            storageSet(STORAGE_GENDER, assistantGender);

            var matchingVoice = pickVoice(lang || 'el-GR', '', assistantGender);
            if (matchingVoice) {
                selectedVoiceURI = matchingVoice.voiceURI || matchingVoice.name;
                storageSet(STORAGE_PREFERRED, selectedVoiceURI);
            }
        }

        function refreshVoices() {
            if (typeof window === 'undefined' || !window.speechSynthesis) {
                return;
            }
            var freshVoices = window.speechSynthesis.getVoices() || [];
            if (freshVoices.length > 0) {
                availableVoices = freshVoices;
            }

            if (selectedVoiceURI) {
                var currentVoiceObj = availableVoices.find(function (v) { return v.voiceURI === selectedVoiceURI || v.name === selectedVoiceURI; });
                if (currentVoiceObj) {
                    var vGender = getVoiceGender(currentVoiceObj);
                    var isConflicting = (assistantGender === 'female' && vGender === 'male') || (assistantGender === 'male' && vGender === 'female');
                    var hasExplicitFemale = availableVoices.some(function (v) { return isGreekVoice(v) && getVoiceGender(v) === 'female'; });
                    var isForeignForGreek = (lang || 'el-GR').indexOf('el') === 0 && !isGreekVoice(currentVoiceObj);

                    if (isConflicting || isForeignForGreek || (assistantGender === 'female' && hasExplicitFemale && vGender !== 'female')) {
                        selectedVoiceURI = '';
                        storageSet(STORAGE_PREFERRED, '');
                    }
                }
            }

            var currentObj = availableVoices.find(function (v) { return v.voiceURI === selectedVoiceURI || v.name === selectedVoiceURI; });
            var currentGender = currentObj ? getVoiceGender(currentObj) : 'unknown';
            var isForeignForGreek2 = (lang || 'el-GR').indexOf('el') === 0 && currentObj && !isGreekVoice(currentObj);
            var hasExplicitFemale2 = availableVoices.some(function (v) { return isGreekVoice(v) && getVoiceGender(v) === 'female'; });
            var needsPick = !selectedVoiceURI || isForeignForGreek2 || (assistantGender === 'female' && hasExplicitFemale2 && currentGender !== 'female');

            if (needsPick) {
                var matchingVoice = pickVoice(lang || 'el-GR', '', assistantGender);
                if (matchingVoice) {
                    selectedVoiceURI = matchingVoice.voiceURI || matchingVoice.name;
                    storageSet(STORAGE_PREFERRED, selectedVoiceURI);
                }
            }
        }

        if (hasSynthesis && typeof window !== 'undefined') {
            refreshVoices();
            if (typeof window.speechSynthesis.addEventListener === 'function') {
                window.speechSynthesis.addEventListener('voiceschanged', refreshVoices);
            }
            window.speechSynthesis.onvoiceschanged = refreshVoices;

            // Chromium often loads network/Google voices asynchronously after 100-2500ms.
            [150, 450, 900, 1800, 3000].forEach(function (delay) {
                setTimeout(refreshVoices, delay);
            });
        }

        function setSelectedVoice(uri) {
            selectedVoiceURI = uri || '';
            storageSet(STORAGE_PREFERRED, selectedVoiceURI);
        }

        function startDictation(options) {
            var opts = options || {};

            if (!hasRecognition) {
                dictationError = isFirefox
                    ? 'Firefox does not support Web Speech Recognition. Use Google Chrome.'
                    : 'Speech recognition is not supported in this browser.';
                return false;
            }

            if (speaking && !opts.onBargeIn && !opts.allowWhileSpeaking) {
                return false;
            }

            stopDictation();

            var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
            var recognition = new SpeechRec();

            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.lang = opts.lang || lang || 'el-GR';

            var SILENCE_MS = 750;

            function resetSilenceTimer() {
                if (activeSilenceTimer) {
                    clearTimeout(activeSilenceTimer);
                }
                activeSilenceTimer = setTimeout(function () {
                    stopDictation();
                    if (opts.onSilence) {
                        opts.onSilence();
                    }
                }, SILENCE_MS);
            }

            recognition.onstart = function () {
                dictating = true;
                dictationError = null;
                interim = '';
            };

            recognition.onspeechend = function () {
                if (speaking || (Date.now() - lastSpeechEndTime < 650)) {
                    return;
                }
                if (activeSilenceTimer) {
                    clearTimeout(activeSilenceTimer);
                }
                activeSilenceTimer = setTimeout(function () {
                    stopDictation();
                    if (opts.onSilence) {
                        opts.onSilence();
                    }
                }, 500);
            };

            recognition.onresult = function (event) {
                if (!speaking && (Date.now() - lastSpeechEndTime < 650)) {
                    return;
                }

                var fullFinal = '';
                var interimPart = '';

                for (var i = 0; i < event.results.length; ++i) {
                    var result = event.results[i];
                    var text = (result[0] && result[0].transcript) || '';
                    if (result.isFinal) {
                        fullFinal += text + ' ';
                    } else {
                        interimPart += text;
                    }
                }

                var candidate = (fullFinal + interimPart).trim();
                if (!candidate) {
                    return;
                }

                if (speaking && currentlySpeakingText) {
                    var hasStop = sanitizer.hasInterruptKeyword(candidate);

                    if (!hasStop || sanitizer.isSpeakerEcho(candidate, currentlySpeakingText)) {
                        return;
                    }

                    var userSpeech = sanitizer.extractUserSpeechFromEcho(candidate, currentlySpeakingText);
                    var isPureStop = sanitizer.isPureInterruptCommand(userSpeech || candidate);

                    lastSpeechEndTime = Date.now();
                    stopSpeech();
                    stopDictation();

                    if (opts.onBargeIn) {
                        opts.onBargeIn({ candidateText: userSpeech, rawCandidate: candidate, isPureStop: isPureStop });
                    }

                    return;
                }

                interim = interimPart;
                if (opts.onTranscript) {
                    opts.onTranscript({ fullFinal: fullFinal, interimPart: interimPart, text: candidate });
                }

                if (candidate) {
                    resetSilenceTimer();
                }
            };

            recognition.onerror = function (event) {
                if (activeSilenceTimer) {
                    clearTimeout(activeSilenceTimer);
                    activeSilenceTimer = null;
                }
                if (event.error === 'not-allowed') {
                    dictationError = 'Microphone permission was denied. Please allow microphone access.';
                } else if (event.error === 'no-speech') {
                    return;
                } else {
                    dictationError = 'Speech recognition error: ' + event.error;
                }
                if (opts.onError) {
                    opts.onError(event);
                }
            };

            recognition.onend = function () {
                if (activeSilenceTimer) {
                    clearTimeout(activeSilenceTimer);
                    activeSilenceTimer = null;
                }
                dictating = false;
                interim = '';
                activeRecognition = null;
                if (opts.onEnd) {
                    opts.onEnd();
                }
            };

            try {
                recognition.start();
                activeRecognition = recognition;
                dictating = true;
                dictationError = null;
                interim = '';
                return true;
            } catch (err) {
                dictationError = (err && err.message) || 'Could not start speech recognition';
                dictating = false;
                return false;
            }
        }

        function stopDictation() {
            if (activeSilenceTimer) {
                clearTimeout(activeSilenceTimer);
                activeSilenceTimer = null;
            }
            if (activeRecognition) {
                try {
                    activeRecognition.onresult = null;
                    activeRecognition.onspeechend = null;
                    activeRecognition.onerror = null;
                    activeRecognition.onend = null;
                    if (typeof activeRecognition.abort === 'function') {
                        activeRecognition.abort();
                    } else {
                        activeRecognition.stop();
                    }
                } catch (e) {
                    // already stopped
                }
                activeRecognition = null;
            }
            dictating = false;
            interim = '';
        }

        function toggleDictation(options) {
            if (dictating) {
                stopDictation();
            } else {
                startDictation(options);
            }
        }

        function speak(id, rawText, options) {
            if (!hasSynthesis) {
                return false;
            }
            var opts = options || {};

            if (window.speechSynthesis && window.speechSynthesis.getVoices) {
                var fresh = window.speechSynthesis.getVoices() || [];
                if (fresh.length > availableVoices.length) {
                    availableVoices = fresh;
                }
            }

            if (!opts.keepDictation) {
                stopDictation();
            }
            stopSpeech();

            var activeGender = opts.gender || assistantGender || 'female';
            var cleaned = stripMarkdownForSpeech(rawText, { gender: activeGender, concise: opts.concise });
            if (!cleaned) {
                return false;
            }

            var hasGreek = /[Ͱ-Ͽἀ-῿]/.test(rawText || cleaned);
            var targetLang = (opts.lang && ((opts.lang.indexOf('el') === 0 && hasGreek) || (opts.lang.indexOf('en') === 0 && !hasGreek)))
                ? opts.lang
                : (hasGreek ? 'el-GR' : 'en-US');

            var utterance = new SpeechSynthesisUtterance(cleaned);
            var voice = pickVoice(targetLang, selectedVoiceURI, activeGender);
            if (voice) {
                utterance.voice = voice;
            }
            utterance.lang = targetLang;

            var voiceGender = voice ? getVoiceGender(voice) : 'unknown';
            var pitch = 1.0;
            var effectiveRate = rate;

            if (activeGender === 'female') {
                if (voiceGender === 'female') {
                    pitch = 1.15;
                } else {
                    // A male or unisex voice shifted high enough into the soprano/alto register
                    // to read as female, never as a male voice speaking female-gendered text.
                    pitch = 1.68;
                    effectiveRate = Math.min(rate, 1.10);
                }
            } else if (activeGender === 'male') {
                if (voiceGender === 'male') {
                    pitch = 0.94;
                } else if (voiceGender === 'female') {
                    pitch = 0.72;
                } else {
                    pitch = 0.84;
                }
            }
            utterance.pitch = pitch;
            utterance.rate = effectiveRate;

            utterance.onstart = function () {
                speaking = true;
                lastSpeechEndTime = 0;
                speakingId = id;
                currentlySpeakingText = cleaned;
                paused = false;
                if (opts.onStart) {
                    opts.onStart();
                }
            };

            utterance.onend = function () {
                speaking = false;
                speakingId = null;
                currentlySpeakingText = '';
                paused = false;
                lastSpeechEndTime = Date.now();
                stopDictation();
                if (opts.onEnd) {
                    opts.onEnd();
                }
            };

            utterance.onerror = function (err) {
                speaking = false;
                speakingId = null;
                currentlySpeakingText = '';
                paused = false;
                lastSpeechEndTime = Date.now();
                stopDictation();
                if (opts.onError) {
                    opts.onError(err);
                }
            };

            utterance.onpause = function () { paused = true; };
            utterance.onresume = function () { paused = false; };

            window.speechSynthesis.speak(utterance);
            speaking = true;
            lastSpeechEndTime = 0;
            speakingId = id;
            currentlySpeakingText = cleaned;
            return true;
        }

        function stopSpeech() {
            if (hasSynthesis) {
                window.speechSynthesis.cancel();
            }
            speaking = false;
            speakingId = null;
            currentlySpeakingText = '';
            paused = false;
            lastSpeechEndTime = Date.now();
        }

        function toggleSpeech(id, rawText, options) {
            if (speaking && speakingId === id) {
                stopSpeech();
            } else {
                speak(id, rawText, options);
            }
        }

        return {
            get dictating() { return dictating; },
            get interim() { return interim; },
            get dictationError() { return dictationError; },
            set dictationError(v) { dictationError = v; },
            get speakingId() { return speakingId; },
            get speaking() { return speaking; },
            get paused() { return paused; },
            get autoRead() { return autoRead; },
            set autoRead(v) { autoRead = v; },
            get conversationMode() { return conversationMode; },
            set conversationMode(v) { conversationMode = v; },
            get lang() { return lang; },
            set lang(v) { lang = v; },
            get rate() { return rate; },
            set rate(v) { rate = v; },
            get assistantGender() { return assistantGender; },
            setAssistantGender: setAssistantGender,
            get currentlySpeakingText() { return currentlySpeakingText; },
            get availableVoices() { return availableVoices; },
            get selectedVoiceURI() { return selectedVoiceURI; },
            setSelectedVoice: setSelectedVoice,
            refreshVoices: refreshVoices,
            hasRecognition: hasRecognition,
            hasSynthesis: hasSynthesis,
            isChrome: isChrome,
            isFirefox: isFirefox,
            startDictation: startDictation,
            stopDictation: stopDictation,
            toggleDictation: toggleDictation,
            speak: speak,
            stopSpeech: stopSpeech,
            toggleSpeech: toggleSpeech,
        };
    }

    window.PfVoice = {
        isSpeechRecognitionSupported: isSpeechRecognitionSupported,
        isSpeechSynthesisSupported: isSpeechSynthesisSupported,
        isChromeBrowser: isChromeBrowser,
        isFirefoxBrowser: isFirefoxBrowser,
        stripMarkdownForSpeech: stripMarkdownForSpeech,
        detectLanguage: detectLanguage,
        getVoiceGender: getVoiceGender,
        isGreekVoice: isGreekVoice,
        pickVoice: pickVoice,
        createVoiceState: createVoiceState,
    };
}());
