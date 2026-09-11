# Changelog

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
