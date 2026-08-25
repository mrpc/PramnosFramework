---
date: 2026-08-25
categories: [Changelog]
---

# One long word came back as an ellipsis

`Helpers::shortenText()` lost the entire text whenever there was no space inside the
limit. Reported as FW-018 with the strings measured, and found here independently while
moving the method somewhere it could be found.

<!-- more -->

## Added

- **`StringHelper::excerpt(?string $text, int $length, string $ellipsis = '…')`** — a
  plain-text excerpt of at most `$length` characters that never splits a word. Three
  guarantees, each of them a bug it removes:

  **A word longer than the limit is cut, not lost.** `mb_strrpos()` found no space,
  returned `false`, `mb_substr()` read that as 0, and the result was the ellipsis on its
  own. Measured in the reporting application:

  ```
  'Καθηγητήςμαθηματικών'               len=10  ->  '&hellip;'
  'Supercalifragilisticexpialidocious'  len=12  ->  '&hellip;'
  'Παιδαγωγός'                          len=5   ->  '&hellip;'
  ```

  A Greek compound, a name with no space, a URL and a hashtag are all that shape, and the
  method was called in 20 places there — all of them user-facing lists. The symptom was
  not an error; it was a column of "…" where titles should be. **The legacy framework this
  was ported from had exactly this guard and the port dropped it.**

  **The result never exceeds `$length`.** The old version cut to `$length` and *then*
  appended the suffix, so a caller sizing a column or a meta description could not rely on
  the number it passed. `Console\CommandBase::truncateText()` had this right all along,
  which the filing also spotted — the framework contained the correct answer and the wrong
  one, one layer apart.

  **HTML is stripped before measuring**, so the length is a length of visible text.

## Changed

- **`Helpers::shortenText()` is a deprecated alias** and forwards, so there is one
  implementation. Two behaviours change for existing callers — both of them the fixes
  above — and the default suffix is now the character `…` rather than the entity
  `&hellip;`. It renders the same in HTML and is correct where the entity was wrong: a
  plain-text email, a JSON field, or anything that escapes the result and turned it into a
  visible `&amp;hellip;`. It also has to be one character now that the length includes it,
  since charging eight for an ellipsis leaves almost nothing of a short excerpt. A suffix
  passed explicitly is used and counted literally.

  `$charset` is ignored — it was `utf-8` at every call site, and `excerpt()` uses the
  internal encoding.

## Why not `symfony/string`

It is already installed, so the question was real. Measured rather than assumed: its
`truncate($length, $ellipsis, cut: false)` guarantees the opposite — it extends to the
**next** word boundary, so a limit of 5 on `The quick brown fox` returns ten characters,
and a single long word comes back whole and unmarked. Useful when you want at least
`$length`; not when the bound is the point.

## Documentation

- [Framework Guide](../../Pramnos_Framework_Guide.md) gains **Shortening text**: the
  guarantees, the alias's two behaviour changes, and why `CommandBase::truncateText()` is
  not a duplicate — it measures visible width, ignoring ANSI codes, and it splits words,
  which is right for a terminal column and wrong for prose.
