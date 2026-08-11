---
date: 2026-07-27
categories:
  - Changelog
  - Features
tags:
  - cache
  - logging
  - media
  - bugfix
---

# Cross-cutting improvements: flat-key cache, log output modes, media alpha fix

A batch of cache / logging / media improvements driven by real app-integration
friction — each one makes a framework subsystem fit uses it previously couldn't,
rather than forcing the app to work around it.

<!-- more -->

## Cache

**`FlatCache`** — a flat-key PSR-16 cache over any cache adapter (see the
[Cache guide](../../Pramnos_Cache_Guide.md#flat-key-caching-flatcache)). Where
`Cache`/`SimpleCache` are category-based and mangle keys (and PSR-16 forbids
`:`), FlatCache stores keys **verbatim** under a fixed prefix — so apps that
address the cache with explicit colon-namespaced keys (`chat:messages:hash`) can
use the framework cache directly. Backend-agnostic: ArrayAdapter in tests,
Redis/File/Memcached in production.

**Bug fix — `ArrayAdapter` double-prefixed keys.** `ArrayAdapter::load/save/
delete` re-prepended `$this->prefix` even though the Cache layer already embeds
the prefix in the key (as the docblocks state and as RedisAdapter treats it), so
through the Cache class an ArrayAdapter with a configured prefix stored every
entry under a doubled prefix. Now stores the key verbatim, matching the other
adapters. (This consistency is also what let FlatCache be adapter-agnostic.)

## Logging

**Output mode — file / stream / both.** The Logger only wrote to files (great
for the LogViewer, awkward for containers). It now supports STDERR output too:
`Logger::setOutputMode('both')`, or the `PRAMNOS_LOG_MODE` env var / `LOG_MODE`
constant. Default stays `file`, fully backward-compatible. See the
[Logging guide](../../Pramnos_Logging_Guide.md#output-mode-file-stream-both).

## Media

**Bug fix — `ResizeTools::fastimagecopyresampled()` lost alpha.** The quality
(multi-step) path built an opaque intermediate image, flattening the alpha
channel of transparent PNG/GIF sources. It now preserves transparency through
the intermediate, so the optimised resampler is safe for transparency-preserving
callers.
