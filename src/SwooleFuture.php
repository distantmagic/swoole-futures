<?php

declare(strict_types=1);

namespace Distantmagic\SwooleFuture;

use Closure;
use Ds\Set;
use GraphQL\Executor\Promise\Promise;
use RuntimeException;
use Swoole\Coroutine\WaitGroup;
use Throwable;

use function Swoole\Coroutine\go;

final class SwooleFuture
{
    public readonly Closure $executor;

    /**
     * @var Set<WaitGroup> $awaitingGroups
     */
    private Set $awaitingGroups;

    private mixed $result = null;
    private PromiseState $state = PromiseState::Pending;
    private float $timeout;

    /**
     * To be used by the framework users.
     */
    public static function create(callable $executor): self
    {
        return new self($executor);
    }

    public function __construct(callable $executor)
    {
        $this->awaitingGroups = new Set();
        $this->executor = $executor instanceof Closure
            ? $executor
            : Closure::fromCallable($executor);
        $this->timeout = defined('DM_GRAPHQL_PROMISE_TIMEOUT')
            ? (float) DM_GRAPHQL_PROMISE_TIMEOUT
            : 0.3;
    }

    public function getResult(): mixed
    {
        if (!$this->state->isSettled()) {
            throw new RuntimeException('Promise is not settled');
        }

        return $this->result;
    }

    public function getState(): PromiseState
    {
        return $this->state;
    }

    public function resolve(mixed $value): SwooleFutureResult
    {
        if (PromiseState::Resolving === $this->state) {
            throw new RuntimeException('SwooleFuture is currently resolving');
        }

        if (PromiseState::Pending !== $this->state) {
            throw new RuntimeException('SwooleFuture is already resolved');
        }

        $this->state = PromiseState::Resolving;

        if ($value instanceof SwooleFutureResult) {
            $this->result = match ($value->state) {
                PromiseState::Fulfilled,
                PromiseState::Rejected => $value->state,
                default => throw new RuntimeException('Unexpected Future state: '.((string) $value->state)),
            };
            $this->result = $value->result;
        } else {
            $waitGroup = new WaitGroup(1);

            $cid = go(function () use (&$value, $waitGroup) {
                try {
                    $this->result = ($this->executor)($value);
                    $this->state = PromiseState::Fulfilled;
                } catch (Throwable $throwable) {
                    $this->result = $throwable;
                    $this->state = PromiseState::Rejected;
                } finally {
                    $waitGroup->done();
                }
            });

            if (!is_int($cid)) {
                throw new RuntimeException('Unable to start an executor Coroutine');
            }

            if (!$waitGroup->wait($this->timeout)) {
                return $this->reportWaitGroupFailure();
            }
        }

        if (!$this->state->isSettled()) {
            throw new RuntimeException('Unexpected non-settled state');
        }

        $this->unwrapResult();

        /**
         * Both state and result are settled and final
         */
        foreach ($this->awaitingGroups as $awaitingThenable) {
            $awaitingThenable->done();
        }

        $this->awaitingGroups->clear();

        return new SwooleFutureResult($this->state, $this->result);
    }

    public function then(?self $onFulfilled, ?self $onRejected): self
    {
        if (is_null($onFulfilled) && is_null($onRejected)) {
            throw new RuntimeException('Must provide at least one chain callback');
        }

        $waitGroup = $this->state->isSettled() ? null : new WaitGroup(1);

        if ($waitGroup) {
            $this->awaitingGroups->add($waitGroup);
        }

        return new self(function (mixed $value) use ($onFulfilled, $onRejected, $waitGroup) {
            if (PromiseState::Pending === $this->state) {
                // This resolve's result cannot be used here as the promise can
                // be resolving at the moment and this code branch might not be
                // visited.
                $this->resolve($value);
            }

            if (
                !$this->state->isSettled()
                && !is_null($waitGroup)
                && !$waitGroup->wait($this->timeout)
            ) {
                return $this->reportWaitGroupFailure();
            }

            $this->awaitingGroups->clear();

            if (!$this->state->isSettled()) {
                throw new RuntimeException('Cannot chain non-settled Future: '.$this->state->name);
            }

            if (PromiseState::Fulfilled === $this->state && $onFulfilled) {
                return $onFulfilled->resolve($this->result);
            }

            if (PromiseState::Rejected === $this->state && $onRejected) {
                return $onRejected->resolve($this->result);
            }

            return new SwooleFutureResult($this->state, $this->result);
        });
    }

    public function wait(?float $timeout = null): void
    {
        if ($this->state->isSettled()) {
            return;
        }

        $waitGroup = new WaitGroup(1);

        $this->awaitingGroups->add($waitGroup);

        $waitGroupTimeout = is_null($timeout) ? $this->timeout : $timeout;

        if (!$waitGroup->wait($waitGroupTimeout)) {
            throw new RuntimeException('WaitGroup timeout');
        }
    }

    private function reportWaitGroupFailure(): SwooleFutureResult
    {
        return new SwooleFutureResult(
            PromiseState::Rejected,
            new RuntimeException('WaitGroup failed while resolving promise (likely due to a timeout)'),
        );
    }

    private function unwrapResult(int $nestingLimit = 3): void
    {
        if ($nestingLimit < 0) {
            throw new RuntimeException('SwooleFuture\'s result is to deeply nested');
        }

        /**
         * Unwrap SwooleFutureResult (from `then`)
         */
        if ($this->result instanceof SwooleFutureResult) {
            $this->state = $this->result->state;
            $this->result = $this->result->result;

            $this->unwrapResult($nestingLimit - 1);
        } elseif ($this->result instanceof Promise) {
            $adoptedPromise = $this->result->adoptedPromise;

            if ($adoptedPromise instanceof SwooleFutureResult) {
                /**
                 * @var SwooleFutureResult $adoptedPromise
                 */
                $this->state = $adoptedPromise->state;
                $this->result = $adoptedPromise->result;

                $this->unwrapResult($nestingLimit - 1);
            } else {
                throw new RuntimeException('SwooleFuture is not fully resolved.');
            }
        }
    }
}
