# Architecture decision records

Short records of the decisions that shaped Labeler. Each states the context, the decision and its
consequences; superseded records stay here with a note.

| # | Decision |
| --- | --- |
| [0001](0001-render-in-the-browser.md) | Labels are rendered in the browser and printed as a 1-bit PNG |
| [0002](0002-plain-php-no-build.md) | Plain PHP and ES modules: no framework, no build step, no runtime dependencies |
| [0003](0003-simulated-printer.md) | Tests run against a simulated ptouch-print, pinned to the real one by a contract test |
| [0004](0004-php-built-in-server.md) | PHP's built-in server under systemd is the default deployment |
| [0005](0005-labels-in-the-browser.md) | Saved labels live in the browser's storage |

Adding a record: copy the template into `NNNN-short-title.md`, add a row here, and link it from
[DESIGN.md](../DESIGN.md) where the decision matters.

```markdown
# NNNN. Title

Date: YYYY-MM-DD · Status: accepted | superseded by NNNN

## Context
## Decision
## Consequences
```
