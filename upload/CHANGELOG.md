# Changelog

## 1.2.2 - 2026-10-12

- Add an Off-by-default seven-day repeat-spammer ban through a separate native
  task, strict server-certified evidence, atomic deduplication and fresh staff,
  ownership, settings and permission checks.
- Preserve native moderator reversal/expiry and retain accounts and content.
  Durable attempt receipts prevent automatic reban after partial writes/crashes.
- Keep credentials, routing, ordinary checks and held-content approval metadata
  on upgrade. Make native Admin API key inputs write-only; blank preserves keys.
- Remove native task/log and local enforcement data on uninstall while preserving
  existing MyBB bans and original-group metadata.

## 1.2.1 - 2026-09-18

- Route lifecycle and bootstrap requests through the resilient public API path.

## 1.2.0 - 2026-09-11

- Replace health and endpoint-catalogue routing with deterministic GeoDNS
  fallback: global uses `api.ffapi.net` then `fortress.ffapi.net`; regional
  routing remains locked unless global fallback is enabled.
- Limit standard-plan heartbeat attempts to hourly while retaining ten-minute
  Pro/MultiMod check-ins.

## 1.1.6 - 2026-09-07

- First release licensed as free and open-source software under
  `GPL-2.0-or-later`.
- Add the complete GPLv2 text, project notice and same-licence contribution
  terms while keeping the hosted Forum Fortress service separate.
