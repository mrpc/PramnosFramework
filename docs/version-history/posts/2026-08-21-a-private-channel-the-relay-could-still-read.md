---
date: 2026-08-21
categories: [Changelog]
---

# A private channel the relay could still read

A `private-` channel is private because the *server* checks who may subscribe. The server can
then read every byte of it — and so can a managed Pusher or Reverb endpoint you do not operate.
For anything where that is the wrong trust boundary, there was no answer.

<!-- more -->

## Added

`private-encrypted-` channels, in Pusher's wire format:

```php
'broadcasting' => ['encryption_key' => 'base64:32 random bytes'],
```

```php
$broadcasting->broadcast('private-encrypted-patient-notes.17', 'note.added', $payload);
```

Nothing else changes, and there is **no client-side code**: `pusher-js` decrypts these natively,
so matching the format was the whole job. Per-channel key of `sha256(channel_name || master_key)`,
payload sealed with NaCl secretbox, sent as `{nonce, ciphertext}`. The auth endpoint hands the
subscriber the same key as `shared_secret` — which is why the derivation is a pure function of the
channel name: both ends compute it independently and it never travels over the socket.

Encryption happens in the manager rather than in a driver, so it happens exactly once whichever
driver is active and whichever process relays it. The daemon then forwards ciphertext it cannot
read, which is the point.

## Two things worth knowing before using it

**Without a key configured, the prefix does nothing.** A broadcast to a `private-encrypted-`
channel with no `encryption_key` goes out in the clear. The prefix is a contract with the client
and only the key makes the server keep its half, so a deployment that names channels this way and
never sets a key has encryption in the name only. There is a test pinning that behaviour, so it
cannot change quietly.

Authorizing such a channel with **no** key throws, because the alternative is worse: a token
without `shared_secret` produces a client that subscribes successfully and then silently drops
every message it cannot decrypt — a channel that looks connected and delivers nothing.

**The channel name is not encrypted, and neither is the event name.** Only the payload is.
`private-encrypted-patient.4417` still tells a relay operator that patient 4417 exists and that
something happened to them. Put nothing in a channel name that the payload is being encrypted to
hide.

A wrong-length or non-base64 key is refused at construction rather than at the first publish: a
realtime feature that fails on its first real event fails in front of users. Decryption fails
closed and returns one answer for every malformation — bad base64, short nonce, failed
authentication — because a caller can do nothing different with the difference, and telling them
apart is what an oracle-style probe wants.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Encrypted channels**, including what it does not protect, and
a `use_cases:` entry.
