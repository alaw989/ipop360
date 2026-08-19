# Iteration Notes

## Goal
Fix the AI enrichment fallback chain in AiEnrichmentService so enrichment has real resilience when the primary provider is unavailable. (1) It currently only fails over to the fallback provider on HTTP 429; any other error (5xx, 401, connection/network failure) short-circuits and returns null without trying the fallback — make it fail over to the next provider on 5xx and connection/network errors too, while keeping genuinely non-retryable 4xx (400/401/403/404) as a hard stop. (2) The GitHub Models fallback (gpt-4o-mini at models.inference.ai.azure.com) returns 404 on prod and is effectively dead weight — correct the fallback config (model name / base URL / or a working free provider) so the chain provides real coverage when the primary is down or rate-limited. Add or extend unit tests in AiEnrichmentServiceTest covering: 5xx fail-over, connection-error fail-over, non-retryable 4xx short-circuit, and the corrected fallback provider selection. Keep all existing tests green and match the project's TDD + PHPStan level 8 conventions.

## State
- Fail-over extended beyond 429: now retries next provider on 5xx + connection errors (408/409/425 too); non-retryable 4xx (400/401/403/404) still hard-stops. Implemented in AiEnrichmentService::callProviders/tryProvider via isRetryableStatus.
- GitHub Models fallback (retired 2026-07-30, 404) replaced with Cerebras defaults (api.cerebras.ai/v1, gpt-oss-120b) in config/services.php, .env, .env.example, deploy.yml comment.
- Stale ghp_ GitHub PAT in .env AI_FALLBACK_KEY (guaranteed 401 hard-stop vs Cerebras = dead weight) blanked; chain now skips fallback cleanly when unset.
- 20 tests pass (4xx data-provided 400/401/403/404); PHPStan 0 errors; Pint clean. Next: operator approval/shipping (local-first).

## Log
- Blank stale ghp_ fallback PAT in .env so dead fallback doesn't fire; gate green.
- Data-provider 4xx hard-stop coverage (400/401/403/404) via PHPUnit attribute; 17→20 tests green, PHPStan/Pint clean.
- Extend fail-over to 5xx/connection errors; move fallback to Cerebras (GitHub Models retired). Tests red→green, PHPStan/Pint clean.
