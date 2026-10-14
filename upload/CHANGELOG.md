# Changelog

## 1.3.1 - 2026-10-14

- Require three cleanup-certified blocks within 24 hours and five or fewer
  posts before cleanup-mode enforcement; keep the ban-only threshold unchanged.
- Store cleanup eligibility separately from ordinary ban evidence and recheck
  the five-post limit before cleanup.
- Serialize native task workers with a database-scoped connection lock and bind
  cleanup to its own confirmed ban.
- Reconcile interrupted soft-delete receipts without deleting visible or
  moderator-restored replies again; native MyISAM tables cannot promise full
  transaction rollback. Failed or unconfirmed ban attempts require review.
- Verify public 1.2.2 upgrade, lifecycle, restoration/replay and concurrent
  workers against native MyBB 1.8.41 on PHP 8.3 and MariaDB.

## 1.3.0 - Unreleased (included in 1.3.1)

- Add a separate Off-by-default option to clean up to five eligible public
  replies in total after a confirmed repeat-spammer ban, processed in bounded
  native task batches.
- Use MyBB's reversible `Moderation::soft_delete_posts` API; topic starters,
  private messages, profiles, signatures and replies in non-public forums are
  excluded so legitimate thread contributions remain visible.
- Persist per-post cleanup receipts and progress independently from the ban.
  Restored replies are not removed again, disabling cleanup cancels queued work,
  and the Forum Fortress dashboard reports the cleaned reply count and status.
- Preserve the existing ban-only value and default cleanup to Off on upgrades.
- Add an isolated MyBB native integration scenario for deletion, preserved
  contributions, restore and opt-out-after-queue behavior.

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
