---
title: Testing
weight: 12
description: The fakes this package ships and dogfoods, and the one lesson its own suite learned the hard way.
---

# Testing

The engine shells out to `docker`, `kind` and `kubectl`, so a test that runs it
for real is a test that needs a cluster. Two fakes ship in
`Cbox\Engine\Testing` so most tests do not.

## FakeCommandRunner

A `CommandRunner` that refuses everything it was not told about.

```php
use Cbox\Engine\Testing\FakeCommandRunner;
use Cbox\Engine\ValueObjects\CommandResult;

$runner = (new FakeCommandRunner)->stage(
    ['docker', 'info', '--format', '{{json .}}'],
    new CommandResult(ran: true, exitCode: 0, output: '{"ServerVersion":"27.0"}', errorOutput: ''),
);

$runner->wasRun(['docker', 'info', '--format', '{{json .}}']);   // true
$runner->calls;                                                   // every command, in order
```

**Deny-by-default is the point.** An unstaged command comes back
`ran: false` with `nothing staged for: …`, so a test that quietly starts
shelling out something new fails instead of passing. Here the command *is* the
contract: `docker info` and `docker info --format json` are different questions.

## FakeHttpProbe

An `HttpProbe` with a canned answer, for the readiness checks a deploy makes
against a project's own URL.

## The lesson worth stealing

**A fake that answers every question the same way agrees with the mistake it
should catch.**

`RecordingKubernetes`, the double this package uses for the cluster, once
ignored the kind and the namespace it was asked for and returned the same
objects to everybody. Every test passed. The first caller that swept objects by
kind was handed the wrong ones — and would have deleted them — while the suite
stayed green.

It filters by kind and namespace now, because the real one does. If a fake is
awkward to be honest with, fix the fake.

The same shape shows up in `serves()`, which asked the cluster whether it
served a kind and had always answered *no*: nothing had ever called it, so
nothing had ever noticed. **Zero callers is a warning, not a convenience** —
verify a helper against the real thing before building a feature on it.

## Running this package's own suite

```bash
composer qa
```

Pint, PHPStan at level max with larastan, Pest, the dependency license gate and
`composer audit --no-dev`. CI runs the same on PHP 8.4 and 8.5 and additionally
checks that the committed `sbom.json` still matches the lock it describes.
