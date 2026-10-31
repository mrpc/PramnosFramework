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

## Search engines

### hreflang, and a sitemap

Under design, written up separately in **[hreflang and Sitemaps — design](Hreflang-And-Sitemap-Design.md)**.

Neither exists. The framework has a full language system and no way for a page to say which URL is
its equivalent in another language; there is no sitemap generator, route or `<link rel="sitemap">`.
`robots` has no home either — it is set in several places by different mechanisms (headers,
meta tags in individual controllers and views) and has no `Document` property. The generated
`robots.txt` carries a `Sitemap:` line only when the `sitemap_url` setting names one or a
`sitemap.xml` file exists in the web root.

They are one piece of work rather than two, because the same declaration has to serve both: if a
sitemap and a page head disagree about which pages are translations of each other, Google discards
the declaration. And a controller cannot be the place it is declared, because no controller runs
when a sitemap is generated.

**Done requires:** deciding whether hreflang is delivered in the head, in the sitemap, or both
under the all-or-nothing rule for language groups; localized route groups, which make reciprocity
structural and are available because `Routing\Route` already compiles through Symfony; and a
sitemap generator that shards, since the protocol caps a file at 50,000 URLs and the sites this is
for have more pages than that.

The design page carries the requirements taken from an application that already does this in
production across five locales, what its implementation gets wrong, and the conflict between a
lean sitemap and sitemap-delivered hreflang.

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

1. **Dictation and read-aloud.** A microphone button in the composer and a "read it" button on
   each answer, in the page and the SPA component, behind a feature setting. Transcription and
   speech in the browser (Web Speech API, `speechSynthesis`). No server side. The page has to
   say that Chrome's recognition sends the audio to Google, and Firefox has none.
2. **Transcription and speech on the server**, each provider a setting the way model providers
   are: `browser`, `api` (an OpenAI-compatible `/audio/transcriptions` and `/audio/speech`, through
   the existing transport, logged and priced in the call log) or `local` (whisper.cpp or
   faster-whisper; Piper for speech). Size and length limits on uploads; consent to sending audio
   to a provider, beside the existing agreement to a provider. Local transcription takes CPU and
   about 0.5–2 GB of memory while it runs, so it goes through the queue.
3. **Voice conversation.** Continuous listening with end-of-speech detection, the answer spoken
   sentence by sentence as it streams, speech stopped when the person starts talking. Over the
   realtime socket where the application runs one; an answer that is a table or a chart is shown,
   not read.

A speech-to-speech model can later sit in front of this as the voice only, with one tool that
hands each question to the assistant, if an application needs lower latency and accepts the cost.
Nothing above is thrown away by that.

**Done requires:** each phase in the page and the SPA, documented in the LLM guide, with tests for
the endpoint, limits, consent and call-log entries of phase 2.

## Test suite

### A dashboard test that sometimes skips itself

`DashboardLayoutsMySQLTest::testUntrackedMailShowsNoOpenRate` asserts that the dashboard shows
no open rate when no mail this week was tracked. It reads the shared test database for that, and
when it finds tracked rows from the last eight days it marks itself skipped rather than fail. In
one full run it did; in the next it did not. Run folder by folder, nothing before it leaves a
tracking row behind that can be seen from outside — so the rows are most likely written, and
seen, inside one connection of the full run, by a class that cleans them up only later.

A skip is not a failure, and that is the trouble: when it happens, the test checks nothing.

- Find the class that leaves the rows — log the count, and the `list` and recipient domain of
  the rows, at the moment the test skips.
- Make that class remove its own rows in `tearDown()`, as the mail body store tests do.
- Make the dashboard test own its data — tracking rows of its own, dated before the week — rather
  than depend on what the database happens to hold.

*Done when:* the test cannot skip for rows another test wrote.

---

_Have a request or found a gap? Open an issue on
[GitHub](https://github.com/mrpc/PramnosFramework)._
