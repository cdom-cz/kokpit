# Security policy

## Supported versions

Only the latest release receives security fixes. Until the first release, only the `main` branch is supported.

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub private vulnerability reporting: open the Security tab of the repository and choose **Report a vulnerability**. Do not open a public issue or pull request for a vulnerability, and do not post details in a discussion.

This project publishes no e-mail address for reports on purpose; private reporting needs none and keeps the report out of public view.

Expected response times (best effort, the project has a single maintainer):

- acknowledgement of the report within 7 days;
- an assessment and a plan within 30 days;
- a fix and a coordinated disclosure as soon as the fix is released.

Never attach real data to a report: no client names, invoices, prices, credentials, tokens, database dumps or personal files. A description and a reproduction with fictional data (`example.com` addresses, company ID `12345678`) are enough.

## Scope

In scope: the Kokpit application in this repository (authentication and two-factor authentication, access isolation between the Admin and Partner accounts, the data layer, the CI and repository tooling).

Out of scope: anyone's hosting, network or server configuration, third-party services, and vulnerabilities in dependencies that are not reachable through Kokpit (report those upstream).

## Safe harbour

Good-faith research that follows this policy, uses only your own instance or fictional data, and avoids privacy violations and service disruption will not be pursued by the project, and the maintainer will not ask anyone to take action against it.

## If something sensitive leaks

If a secret, real data or an instance-specific value was committed or pushed:

1. Rotate or revoke it first. A secret that was ever pushed is compromised, whatever happens to the history afterwards.
2. Remove it from history. Before the first push, a local history rewrite is enough. After a push, tell the owner before touching shared history: the repository ruleset blocks force-pushes to `main`, so the repair needs a decision. Do not rewrite history on your own.
3. Notify the affected parties (clients, providers, anyone whose data it was).

Never bypass the pre-commit hook (`--no-verify`, `LEFTHOOK=0`); CI rescans the whole tree and history. The day-to-day rules are in [CONTRIBUTING.md](CONTRIBUTING.md).
