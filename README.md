# agentlock

[![tests](https://github.com/js199069/agentlock/actions/workflows/tests.yml/badge.svg)](https://github.com/js199069/agentlock/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Your coding agent says the tests pass. agentlock checks whether it got there honestly.**

When an AI agent cannot make a test pass, it sometimes makes the test easier instead: it deletes an assertion,
skips the test, wraps the call in a `try/catch`, hard-codes the expected value, or adds the error to the PHPStan
baseline. Researchers measuring this on coding benchmarks found that a large share of agent "solutions" game the
tests, and many of those still pass them. If you are not reading every diff, you will not notice.

agentlock is a PHP tool that inspects the diff, independently of the agent, and lets you lock the tests the agent
must not touch.

~~~bash
composer require --dev js199069/agentlock

vendor/bin/agentlock scan                      # working tree vs HEAD
vendor/bin/agentlock scan --staged             # pre-commit
vendor/bin/agentlock scan --base=origin/main   # CI / pull request
~~~

~~~
[HIGH] test-emptied  tests/Billing/InvoiceTest.php:42
    Test InvoiceTest::test_total_includes_tax has no assertions left (was 3)
[HIGH] swallowed-exception  src/Billing/Invoice.php:57
    Invoice::total: a catch block that swallows the error was added
[MEDIUM] expectation-changed  tests/Billing/InvoiceTest.php:61
    Expected values in InvoiceTest::test_discount changed: [90] → [100]. Was the code fixed, or the test bent to the output?

agentlock: 2 high, 1 medium, 0 low in 3 changed file(s).
~~~

No LLM, no tokens: every rule is deterministic, so the same diff always gives the same answer.

---

## What it detects

| Rule | Severity | Meaning |
|---|---|---|
| `test-file-deleted`, `test-removed` | high | A test file or test method disappeared (renames are recognised as `test-renamed`, low) |
| `test-skipped` | high | `markTestSkipped`, `markTestIncomplete`, or Pest `->skip()` / `->todo()` added |
| `test-emptied` | high | A test that had assertions now has none |
| `tautology` | high | An assertion that cannot fail: `assertTrue(true)`, `assertSame($x, $x)`, `expect(true)` |
| `assertions-reduced` | medium | Fewer assertions in a test than before |
| `expectation-changed` | medium | The literal a test expects changed: a fix, or the test bent to the output |
| `try-catch-in-test` | medium | A `try/catch` in a test that never calls `fail()` or asserts |
| `swallowed-exception` | high | A `catch` that does nothing or returns a constant |
| `validation-removed` | medium | A method throws less than before |
| `literal-special-case` | medium | `if ($x === 100) { return 110; }` — a fixed answer for a fixed input |
| `suppression-added` | medium | New `@phpstan-ignore`, `@psalm-suppress`, `@codeCoverageIgnore` |
| `suite-shrunk`, `suite-excluded` | high | Tests removed from or excluded in `phpunit.xml` |
| `strictness-lowered` | medium | `failOn*` / `beStrictAbout*` turned off |
| `analysis-level-lowered` | high | PHPStan level lowered, Psalm `errorLevel` raised |
| `ignore-errors-added`, `baseline-added`, `baseline-grew` | medium | Errors moved into ignore lists or a baseline |
| `script-ignores-failure`, `ci-failure-ignored` | high | `\|\| true` or `continue-on-error: true` added |
| `checks-removed`, `ci-checks-reduced` | medium | Fewer test or analysis tools run in scripts or CI |
| `quality-gate-lowered` | high | Infection `minMsi` / `minCoveredMsi` lowered |
| `locked-*`, `lock-modified` | high | The contract was broken (see below) |
| `msi-dropped` | high | The tests catch fewer injected bugs than on the trusted baseline |

## The contract: locked tests

Before handing a task to an agent, lock what defines "done":

~~~bash
vendor/bin/agentlock lock tests/Feature/CheckoutTest.php                 # a whole file
vendor/bin/agentlock lock tests/Unit/PriceTest.php::test_vat_is_applied  # one test
git add .agentlock/lock.json && git commit -m "Lock acceptance tests"
~~~

Any change to a locked file, or to the body of a locked test, is reported as high. Other tests in the same file stay
free. Changing `.agentlock/lock.json` itself is also reported: locks are changed by people, not by agents.

## Weaker tests that still pass: the mutation check

Pattern rules cannot see a test that was made subtly weaker. Mutation testing can. On a commit you trust:

~~~bash
vendor/bin/agentlock baseline src/Billing/Invoice.php src/Billing/Tax.php
~~~

Later, `scan --mutation` re-measures those files with [Infection](https://infection.github.io) and reports any MSI drop.
It is slow, so use it in CI or before merging, not on every save.

---

## Where to run it

**CI (the gate you can rely on):**

~~~yaml
- uses: actions/checkout@v4
  with:
    fetch-depth: 0
- run: composer install --no-interaction
- run: vendor/bin/agentlock scan --base=origin/${{ github.base_ref }}
~~~

**Pre-commit:** `vendor/bin/agentlock install-hook`

**Claude Code** (`.claude/settings.json`), so the agent sees the findings when it stops:

~~~json
{
  "hooks": {
    "Stop": [{ "hooks": [{ "type": "command", "command": "php vendor/bin/agentlock scan --stderr --exit-code=2" }] }]
  }
}
~~~

Hooks run on a machine the agent can touch and can be bypassed (`--no-verify`). Treat them as early feedback,
and CI with branch protection as the enforcement.

| Option | Meaning |
|---|---|
| `--staged` / `--base=<ref>` | What to compare (default: working tree vs HEAD) |
| `--fail-on=high\|medium\|low\|none` | When to return a failing exit code (default: high) |
| `--exit-code=N` | The failing exit code (2 makes Claude Code feed the report back to the agent) |
| `--format=json` | Machine-readable output |
| `--mutation` | Also compare MSI with the baseline |

---

## Design decisions

**Independent of the agent.** The agent cannot be its own reviewer. agentlock runs outside it, on the diff, with
rules that cannot be argued with.

**Deterministic first.** No LLM means no cost, no variance, and findings you can reproduce. An LLM judge may be
added later for cases rules cannot decide, never as the only signal.

**Medium means "look", high means "stop".** Changing an expected value is sometimes the right fix; deleting a test
rarely is. Severities follow how often the pattern is legitimate.

**Locks hash test bodies, not files**, so locking one test does not freeze the whole file.

## Limitations

- Heuristics: a legitimate refactor can trigger medium findings, and a determined agent can find patterns not
  covered here. The mutation check exists for the cases patterns miss.
- Pest support covers top-level `test()` / `it()` calls; `describe()` blocks are not inspected yet.
- Data providers are not compared: changing the cases a test runs is not detected unless the file is locked.
- Hooks can be bypassed; only CI is a real gate.

## Testing

~~~bash
composer install
vendor/bin/phpunit
~~~

## License

MIT
