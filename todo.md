# Compensable - TODOS

## Clean abort (WorkflowAbortedException)

Add src/WorkflowAbortedException.php — a plain extends \RuntimeException. In WorkflowPipeline::process(), catch it before the \Throwable catch, skip compensation, and return the payload as-is. This lets any step signal "stop cleanly, nothing went wrong."

## Conditional steps (skippable())

Add a skippable(array $steps, callable|bool $when): self method on WorkflowPipeline alongside through(). Internally, store stages with type + condition and resolve the active pipe list at process() time — only include a skippable group if the condition is truthy (evaluated against the payload). The existing through() becomes "always run these."

Optionally, steps can declare requires(): array — key names that must exist on the payload via has(). WorkflowPipeline checks this before calling run($step, $payload) and skips silently if not satisfied.

## Per-step retry

Add src/Contracts/Retryable.php — an interface with retryConfig(): RetryConfig and isUnrecoverableError(\Throwable $e): bool. Add src/RetryConfig.php as a readonly VO with tries, retryAfterSeconds, exponential backoff in a waitMs(int $attempt): int method.

In CompensableScope::run(), after resolving the action, check instanceof Retryable. If so, wrap the $action->handle() call in a retry loop using the config — on each failure check isUnrecoverableError() to bail early, otherwise sleep and retry. Only push to the undo stack on success.
