# 0006. A printer agent with an access token lets the interface run elsewhere

Date: 2026-11-08 · Status: accepted

## Context

The printer needs a computer with USB next to it, usually a Raspberry Pi. People want the
interface on their server (in Docker, behind their reverse proxy) and the printer anywhere in the
house. USB/IP would make the printer look local to the server, but needs kernel modules on both
sides, re-attaching after every reboot or network drop, and USB access inside the container.

## Decision

The computer with the printer runs a printer agent: the same API (`status`, `print`, `log`,
`health`) without the interface, installed by `install.sh --agent`, protected by a generated
access token. The interface gets `printer_url` and `printer_token` and forwards those three calls;
fonts, icons and rendering stay with the interface and the browser. Without `printer_url`
nothing changes.

## Consequences

- The agent is a few PHP files and `php-cli`; nothing else runs on the Pi.
- A dropped network only means "agent cannot be reached" until it is back; no re-attaching.
- One code base: the agent is the API itself, so the simulator, the tests and the contract test
  cover both roles; `AgentTest` runs the interface against a real agent process.
- The token is a bearer secret over HTTP on the local network; across untrusted networks, TLS or a
  VPN is needed (SECURITY.md).
- Several agents (one per printer) fit the same model later (#28).
