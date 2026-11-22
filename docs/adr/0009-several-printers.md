# 0009. One interface serves several printers of different kinds, picked per request

Date: 2026-11-22 · Status: accepted

## Context

With the `ql` driver ([ADR 0008](0008-ql-raster-in-php.md)) a Pi can have a P-touch and a QL
printer side by side. One Labeler instance had one driver, so that meant two instances on two
ports. The printer choice of ptouch-print 1.9 (`serial=`) only switches between printers of the
same kind, and a printer agent was one per interface.

## Decision

The interface has a list of printers (`Target`: id, name, and either a `Driver` or a printer
agent with the agent's own printer id), built by `Targets` from `printers`, else `printer_url`,
else `printer_driver`, which may name several drivers (`ptouch,ql`). Every request picks one with
`printer=<id>` (default: the first); everything after that is unchanged, because the API already
worked on one driver or one agent. `overview` asks all printers at once for the picker.

The browser keeps the chosen printer, renders for its media, and sends each print job with the
printer it was started on, so a change of printer during a long job does not move the job. PHP's
built-in server runs several workers (`PHP_CLI_SERVER_WORKERS` in the service), so two printers
print at the same time.

## Consequences

- Existing settings mean what they meant: one driver or one agent is a list of one, and its log
  entries carry no printer id.
- A printer agent may itself serve several printers; the interface names which one (`printer` in
  its entry), so agents nest without new API.
- `overview` costs one status query per printer; the picker asks for it when the status is
  refreshed, not continuously.
- Two printers on the same ptouch-print need different `serial`s, two QL printers different
  `ql_device`s; Labeler does not lock a printer against two jobs at once beyond what the device
  itself does.
