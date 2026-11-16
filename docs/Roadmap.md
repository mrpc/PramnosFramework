---
use_cases:
  - Checking whether something is already planned before proposing it
  - Finding known gaps and open items in the framework
---

# Roadmap & Open Items

A running list of planned work and known gaps in the framework. Shipped features
live in the [Changelog](version-history/index.md); this page tracks what is
**not yet done**.

> Items here are deliberately not started or intentionally deferred. Each notes
> *why* and what "done" requires. Anything touching live authentication is
> verified in a real application before it is merged.

## Ask AI

### Voice: speaking a question and hearing the answer

Agreed approach: a chain — speech to text, then the assistant exactly as it answers in text,
then text to speech — rather than a speech-to-speech model (OpenAI Realtime, Gemini Live).

**Why a chain.** The assistant in the middle is the one that already exists, so its tools,
policies, data rules, the "what somebody else wrote is data" guard, the call log, proposals that
wait for approval and the fallback between providers all apply unchanged, and it works with any
provider, a local one included. A speech-to-speech model would be a second assistant beside the
first: context passed once per session, every guard reimplemented over tool results, no fallback,
no local option, and audio tokens at 15–90 times the cost of a text answer, with the whole
context billed again on every turn. The price of the chain is latency, about 1.5–3 s to the first
sound, which a spoken "one moment" covers while a tool runs.

The cost of a voice answer is almost all text to speech: transcription is a small fraction of a
cent per question, and a hosted voice reading a typical answer — about 320 tokens, well over a
minute spoken — costs ten to twenty times the text answer itself. Spoken locally, a voice answer costs what a text answer costs.

**Phases:**

1. ~~**Dictation and read-aloud.**~~ — **shipped**: `llm.voice`, `pf-voice.js` +
   `pf-speech-sanitizer.js` + `pf-voice-fillers.js` ([guide](Pramnos_LLM_Analyst_Guide.md#voice)).
   Only the vanilla-JS chat page has it; an application's own SPA component still wires its own
   mic/read buttons to the same scripts.
2. ~~**Transcription and speech on the server.**~~ — **shipped**: `Speech\Listening` + `Speech`,
   each a provider setting the way model providers are (`'llm' => ['listen' => ['provider' =>
   'google'|'whisper', ...]]]`, `'llm' => ['speech' => [...]]]`), both off unless configured.
   `whisper`/`google`/`piper` all reach an OpenAI-compatible or vendor endpoint through the
   existing transport; `base_url` points any of them at a self-hosted server (a whisper.cpp or
   faster-whisper instance speaking the OpenAI shape, a Piper HTTP server) — not an in-process
   `local` runtime the framework manages itself, which is a difference from how this phase was
   first worded, not a gap in what it does. `Listening::MAX_BYTES` caps an upload (10 MB, 422 past
   it or empty). A self-hosted call is queued to a worker of its own when turned on (FW-522,
   mirroring `code_exec`'s queue dispatch, FW-508), falling back to running inline the moment
   anything about queueing does not go cleanly; every call also lands beside the `speech` log, in
   `llm_calls`, so it shows in `/admin/Ai/settings` like any model call; and a provider can ask
   for its own agreement before a recording leaves for it (`requires_consent`, `{chat}/audio-consent`),
   separate from the text provider agreement.
3. ~~**Voice conversation.**~~ — **shipped**: sentence-by-sentence streamed speech
   (`splitSentences()`, the next sentence asked for while one plays), end-of-speech silence
   detection for a dictation turn (`silenceMs`/`silenceLevel`, both the native-recognition and
   server-dictation paths), and barge-in broader than first planned — by an interrupt word *and*
   by voice level (`bargeIn`, with an optional `dictate` that restarts listening on its own). A
   table or chart answer is kept from the spoken opening at the model level
   (`ContextAssembler::SPOKEN`), not the player. No literal "realtime socket" involved anywhere —
   plain client state turned out to be enough, simpler than this phase first assumed. A single
   **continuous-conversation switch** (`llm.voice_conversation`, FW-522) reopens dictation on its
   own once `speak()` ends — purely mechanical, no farewell/mute guessing built in here: an
   application's own conversation mode layers that judgement call on top, the way it already had
   to before this switch existed.

A speech-to-speech model can later sit in front of this as the voice only, with one tool that
hands each question to the assistant, if an application needs lower latency and accepts the cost.
Nothing above is thrown away by that.

**Done.** Every phase shipped, each documented in the LLM guide, with tests.

---

_Have a request or found a gap? Open an issue on
[GitHub](https://github.com/mrpc/PramnosFramework)._
