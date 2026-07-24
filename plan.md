# Workflow Layer — Brainstorm & Package Idea

_Thinking document. Not tied to any specific codebase._

---

## The Problem

In a standard DDD/CA Laravel application the Application layer collapses into a flat bag of UseCases. This creates two failure modes as complexity grows:

1. **UseCase-calls-UseCase.** Someone needs to reuse orchestration logic, so they inject one UseCase into another. Transaction boundaries blur. The layer has no ceiling.
2. **God UseCase.** Someone extracts a "shared service" that inlines six distinct operations. SRP is gone. Conditional flags (`$withLeadTimeSync`) bleed into the signature.

Both are symptoms of a missing layer.

---

## The Triality: Action / UseCase / Workflow

Three distinct levels of application-layer abstraction, each with a clear responsibility:

```text
Action    →  atomic unit. One thing. No transaction. No orchestration.
UseCase   →  orchestrates Actions. Owns the transaction boundary. One business operation.
Workflow  →  sequences UseCases (or Actions) via a Pipeline. Owns the error boundary.
```

### Action

The smallest meaningful unit of application work. Think: call this service, apply this domain rule, persist this change. No transaction of its own — it either participates in the caller's transaction or fires and forgets.

Close to CQRS Command Handler concept.

Analogy: a database migration's `up()` — focused, reversible in isolation, not responsible for the larger sequence.

```php
interface Action
{
    public function handle(mixed $payload): void;
}
```

### UseCase

Orchestrates one coherent piece of domain work. Owns the transaction boundary (`ITransactionManager::execute()`). Dispatches domain events after commit. Entry point from a Port (controller, listener, console command) for single-operation requests.

Rule of 👍: A UseCase should not call another UseCase.

### Workflow

Coordinates multiple UseCases (or Actions) in a named, sequential business flow using a Pipeline. Owns the aggregate lifecycle around the sequence (e.g. `startSync / completeSync / failSync`). Does **not** own a transaction — each Step's UseCase/Action owns its own.

The discriminator between two similar workflows is one or more conditional steps (`skippable([...], callable $predicate)`, chained in order), not a boolean parameter.

---

## The Step

A **Step** is a thin pipeline adapter. It wraps one Action or UseCase, extracts what it needs from the Payload, builds any required DTOs, and calls the wrapped class. No business logic.

```php
interface ISteppable
{
    public function __invoke(mixed $payload, ?Closure $next): mixed;
}
```

The `Steppable` trait wires `__invoke` → `handle()` / `execute()` automatically, so any Action or UseCase becomes pipeline-compatible without knowing it lives in a pipeline.
※　the `handle` vs `execute` distinction is just a legacy patch coming from an existing codebase that has both conventions. The package should standardize on one.

---

## The Payload

Each Workflow defines a typed `Payload` — essentially a DTO that travels through the pipeline:

- Properties known at entry → `readonly`, non-nullable.
- Properties populated mid-pipeline by a Step → nullable, non-readonly.

Steps that depend on a mid-pipeline property declare `requires(): array`. The trait auto-skips the step if those properties are null, avoiding null-checks inside `handle()`.

This makes everything more **declarative**: the Workflow declares what it needs, and the Step declares what it requires. The pipeline enforces the contract.
The author also has to **think** about the input contract ahead of time, rather than pitching and patching.

---

## The Transaction Boundary Problem

The moment a Workflow sequences DB writes alongside external API calls, no single transaction can provide consistency. DB transactions are local; API calls are not rollbackable.

This means:

- **Each UseCase owns a small, tight transaction** around its own DB write(s).
- **The Workflow owns the error boundary** (try/catch), not a transaction.
- On failure, external state may already be partially updated. Meaning any HTTP call, for example, could not be undone, while our DB has.

The only realistic recovery mechanism is **idempotency**: every Step must be safe to re-execute with the same input, because the queue layer may retry the entire Workflow.

---

## The Compensable Idea

> _Inspired by database migrations: `up()` without `down()` is an incomplete contract._

An Action or Step that mutates external state should declare what "undoing" looks like:

```php
interface Compensable
{
    public function handle(WorkflowPayload $payload): void;
    public function rollback(WorkflowPayload $payload): void;
}

interface MutatesExternalState extends Compensable
{
    // marker interface for steps that mutate external state and require compensation, might be superfluous (but naming is more explicit)
}
```

The Workflow (or WorkflowPipeline) tracks which steps succeeded. On failure, it walks back in reverse and calls `rollback()` on each completed step.

**"Not compensable" is a valid declared answer.** Some operations have no meaningful inverse (e.g. a notification was sent). The contract forces the question at definition time — making a point of no return explicit rather than discovered at incident time.

This is a **saga with explicit compensating transactions**. The difference from informal error handling: the compensation logic is a first-class contract on the step, not an afterthought in a catch block.

```php
// Example sketch
class WorkflowPipeline
{
    private array $completed = [];

    public function run(): void
    {
        foreach ($this->pipes as $step) {
            try {
                $step->handle($this->payload);
                if ($step instanceof Compensable) {
                    $this->completed[] = $step;
                }
            } catch (\Throwable $e) {
                $this->compensate();
                throw $e;
            }
        }
    }

    private function compensate(): void
    {
        foreach (array_reverse($this->completed) as $step) {
            $step->rollback($this->payload);
        }
    }
}
```

---

## The Saga Connection

What's described above is a **choreography saga** with optional compensation. **Temporal.io** formalizes this pattern with durable execution — Steps become Activities, the Workflow becomes a durable function that survives process restarts. The PHP package would be the lightweight, synchronous (or queue-backed) version of the same mental model.

---

## Package Sketch

**Name ideas:** `laravel-workflow`, `pipeline-saga`, `application-workflow`

**Core primitives:**

| Class / Interface      | Responsibility                                                |
| ---------------------- | ------------------------------------------------------------- |
| `Workflow`             | Base class. Declares `steps()` and `run()`.                   |
| `WorkflowPayload`      | Base typed payload. `set/get/has` for dynamic cases.          |
| `WorkflowPipeline`     | Fluent pipeline wrapper. `->steps()->skippable()->run()`.     |
| `ISteppable`           | Marker interface for pipeline-aware steps.                    |
| `Steppable`            | Trait wiring `__invoke` → `handle/execute`.                   |
| `Compensable`          | Interface adding `rollback()` to a step.                      |
| `CompensatingPipeline` | WorkflowPipeline variant that tracks and reverses on failure. |

**Open questions:**

- Should `Workflow` be the transaction boundary for pure-DB workflows (no external calls)?
- Should `CompensatingPipeline` swallow rollback failures or chain them?
- Async steps — how does a Step signal "wait for webhook before continuing"? (Temporal territory)
- Should `requires()` be on the Payload or on the Step?
- Laravel-specific: integrate with `ShouldQueue` on the Workflow itself, not individual Steps?

---

## Naming Alternatives Worth Considering

The Action/UseCase/Workflow names carry baggage from different communities. Alternatives:

| This doc | CQRS                   | Laravel Actions | Classic DDD                |
| -------- | ---------------------- | --------------- | -------------------------- |
| Action   | Command Handler        | Action          | Application Service (thin) |
| UseCase  | Application Service    | —               | Application Service        |
| Workflow | Saga / Process Manager | —               | Domain Service (stretched) |

---

## On CQRS and Repository Simplicity

The read/write split (QueryService vs CommandRepository) earns its keep in specific scenarios:

- **Cross-module reads** — joining across module boundaries where a Repository can't go
- **Projections** — read models that diverge significantly from the write model
- **Enforced access scoping** — when tenant isolation is pinned to QueryService method signatures

Outside those cases, the split is ceremony. An Action that fetches an entity to validate before writing doesn't benefit from two injected dependencies. It just needs `IRepository`.

**The composability argument:** an Action with one `IXxxRepository` dependency is trivially injectable anywhere — into a UseCase, a Step, a test. Two dependencies means two mocks, two bindings, more friction.

The package's `Action` contract should not assume CQRS. Default to a plain Repository; reach for QueryService only when the problem it solves is actually present (divergent read model, cross-module query, projection).

Rule of thumb: **don't apply a pattern before the problem it solves exists.**

---

## On Compensation Failure

`WorkflowPipeline` owns detection, not reaction. When a `rollback()` fails, the package logs the failure, persists a `FailedCompensation` record with full context (Workflow, Step, Payload, original exception, compensation exception, timestamp), and attempts a fixed number of retries via a dedicated queue. After the retry cap is exhausted, a hook is invoked:

```php
->onCompensationFailed(function (FailedCompensation $failure): void {
    // plug in a Slack notification, a PagerDuty webhook, a Sentry report — whatever fits
})
```

The package ships with a sensible default (log + DB record) but gets out of the way beyond that. Wiring to a Laravel `Notification`, a third-party exception tracker, or an external webhook is left to the consumer. Open question: should the hook be registered per-pipeline instance (fluent, as above) or as a `CompensationFailureHandler` binding in the service container — the latter allowing a single global configuration rather than per-Workflow setup.

---

## Next Steps

- [ ] Sketch the package structure (`src/`, interfaces, traits)
- [ ] Decide: separate `CompensatingWorkflow` subclass or opt-in interface on `WorkflowPipeline`?
- [ ] Prototype `Compensable` with a real-world example (Airbnb sync rollback)
- [ ] Consider: does the package ship a base `Workflow` class or just the pipeline primitives?
- [ ] Publish to Packagist under personal namespace first, extract to org if adopted
