#!/usr/bin/env python3
"""Stop the container when supervisord gives up on a managed process.

Without this, a process that cannot be restarted leaves the container running in
a degraded state. php-fpm is the concrete case: SIGKILL the master and its
workers are orphaned still holding 127.0.0.1:9000, so every restart attempt
exits with status 78 (address in use) until supervisord marks the program FATAL
and stops trying. The orphaned worker keeps answering nginx, so the health check
continues to pass and the orchestrator never learns anything is wrong.

Turning FATAL into container exit is the container-native response: fail loudly
and let the scheduler replace the task, rather than serving from a process
nothing is supervising.

Speaks supervisord's event listener protocol on stdin/stdout, so stdout carries
protocol frames only and diagnostics go to stderr.
"""

import os
import signal
import sys


def read_headers() -> dict[str, str]:
    """Read one header line, or return an empty mapping at end of stream."""
    line = sys.stdin.readline()

    if not line:
        return {}

    return dict(token.split(":", 1) for token in line.split())


def main() -> None:
    while True:
        sys.stdout.write("READY\n")
        sys.stdout.flush()

        headers = read_headers()

        if not headers:
            return

        # The payload must be consumed whether or not this event is acted on,
        # otherwise the next header read starts mid-frame.
        payload = sys.stdin.read(int(headers.get("len", 0)))

        sys.stdout.write("RESULT 2\nOK")
        sys.stdout.flush()

        if headers.get("eventname") != "PROCESS_STATE_FATAL":
            continue

        fields = dict(token.split(":", 1) for token in payload.split())
        process = fields.get("processname", "unknown")

        sys.stderr.write(
            f"exit-on-fatal: '{process}' could not be restarted; stopping the container\n"
        )
        sys.stderr.flush()

        # SIGTERM to supervisord, which stops the remaining programs and exits.
        os.kill(os.getppid(), signal.SIGTERM)

        return


if __name__ == "__main__":
    main()
