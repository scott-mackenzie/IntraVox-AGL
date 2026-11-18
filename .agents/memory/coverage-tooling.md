---
name: Coverage tooling
description: Local branch-coverage constraints and the downstream Unit-only coverage boundary.
---

Use a coverage-only PHP invocation with at least 1 GiB of memory for Xdebug
branch/path instrumentation; do not change the web server's PHP settings.

**Why:** The default 128 MiB CLI limit exhausted memory inside XdebugDriver even
for the focused Sovereign tests because coverage instruments the configured app
source scope. The same checks passed with the coverage CLI memory limit raised.

**How to apply:** Follow the Gold Master deployment guide's CLI coverage commands.
PCOV supplies downstream Unit-suite line coverage, not branch coverage. Native
integration evidence alone does not satisfy that downstream Unit coverage gate.
