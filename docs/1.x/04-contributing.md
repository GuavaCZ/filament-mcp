---
title: Contributing
---

# Contributing

Contributions are **welcome** and will be fully **credited**. This page covers the quickest way to get your issue, idea or PR in front of us.

Before you open anything, please read the [contribution guide](https://github.com/GuavaCZ/filament-mcp/blob/main/.github/CONTRIBUTING.md) in the repository.

## Reporting a bug

Bugs belong in the [issue tracker](https://github.com/GuavaCZ/filament-mcp/issues/new?template=bug.yml).

Before you open one, please try to reproduce the problem once more, and have a quick look at the open issues and pull requests to see if somebody already reported it or is working on a fix.

To make the report useful to us, please include:

1. Your filament version and your plugin version
2. The plugin registration on your panel, and the `McpResource` for the resource you are having trouble with
3. The tool call you made and what came back
4. What you expected to happen, and what happened instead
5. The full exception and stack trace, if there is one

> [!NOTE]
> A tool that never shows up in the list is usually a registration problem rather than a bug. Actions need `->discoverActions()` on the plugin, and one whose closure can't run headlessly is skipped with a log entry naming the reason. Please check your logs and the [limitations](03-limitations.md) page first.

## Requesting a feature

Feature requests are handled as [discussions](https://github.com/GuavaCZ/filament-mcp/discussions/new?category=ideas) instead of issues, so other users can chime in and tell us whether they need it too.

Please describe what you are trying to build rather than only the API you have in mind. It is often possible to solve it with what is already there, and if it isn't, knowing the use case helps us design the option properly.

## Asking a question

If something is unclear and you are not sure it's a bug, please use the [Q&A discussions](https://github.com/GuavaCZ/filament-mcp/discussions/new?category=q-a).

## Reporting a security issue

Please do **not** open a public issue for security problems.

This package exposes your data to agents, so we take reports here seriously. If you discover a security related issue, send an email to office@guava.cz instead. More details in our [security policy](https://github.com/GuavaCZ/filament-mcp/security/policy).

## Opening a pull request

A PR is very welcome, for features as well as for fixes and documentation.

1. Fork the repository and create a branch off `main`
2. Add tests for your change, we use [pest](https://pestphp.com)
3. Make sure the whole suite passes with `composer test`
4. Run `composer format` to apply our code style
5. Run `composer analyse` to make sure phpstan is happy
6. Open the PR against `main` and describe what it changes

We use [conventional commits](https://www.conventionalcommits.org), since our releases are generated from the commit history. So please prefix your commits with `feat:`, `fix:`, `docs:` or `chore:`.

If your change breaks the public API, please say so in the PR description so we can release it as a new major.

> [!IMPORTANT]
> Anything touching authorization, token handling or the upload path needs a test that proves the *denial* as well as the success. A change that widens what an agent can reach is a security change, please call it out in the PR description.

## Improving the docs

The documentation lives in the `docs` directory of the repository, with one directory per major version. If you spot something wrong or missing, you can edit the markdown file directly and open a PR.

## Contributing financially

Maintaining our packages takes a lot of unpaid time. If this package saves you some of yours, you can support the work through [GitHub Sponsors](https://github.com/sponsors/GuavaCZ).

If you or your company depend on our packages, sponsoring is what keeps them maintained and compatible with new filament versions. It is very much appreciated :)
