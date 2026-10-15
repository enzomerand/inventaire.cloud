<?php

declare(strict_types=1);

use App\Database\DeadlockRetries;
use Illuminate\Database\QueryException;

describe('the failures that are a lost deadlock', function (): void {
    it('are the ones of PostgreSQL, MySQL and MariaDB', function (string $state, int|string $code, string $message): void {
        expect(DeadlockRetries::isDeadlock(queryFailure($state, $code, $message)))->toBeTrue();
    })->with([
        'PostgreSQL: deadlock detected' => ['40P01', 7, 'ERROR: deadlock detected'],
        'MySQL and MariaDB: deadlock found' => ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
        'MariaDB: record has changed since last read' => ['HY000', 1020, "Record has changed since last read in table 'login_codes'"],
    ]);

    it('are not the others, which the framework would also retry', function (string $state, int|string $code, string $message): void {
        expect(DeadlockRetries::isDeadlock(queryFailure($state, $code, $message)))->toBeFalse();
    })->with([
        'a lock wait timeout' => ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'],
        'a serialization failure of PostgreSQL' => ['40001', '40001', 'ERROR: could not serialize access due to concurrent update'],
        'a duplicate key' => ['23000', 1062, "Duplicate entry 'ada@example.com' for key 'users_email_unique'"],
    ]);

    it('are found under the exception that wrapped them', function (): void {
        $wrapped = new RuntimeException('The sign-up failed.', 0, queryFailure('40P01', 7, 'ERROR: deadlock detected'));

        expect(DeadlockRetries::isDeadlock($wrapped))->toBeTrue();
    });

    it('are not guessed from a message alone', function (): void {
        expect(DeadlockRetries::isDeadlock(new RuntimeException('Deadlock found when trying to get lock')))->toBeFalse();
    });
});

describe('running the work again', function (): void {
    it('goes on after a lost deadlock, and returns what the work finally returns', function (): void {
        $attempts = 0;

        $result = DeadlockRetries::run(function () use (&$attempts): string {
            if (++$attempts < 3) {
                throw lostDeadlock();
            }

            return 'done';
        });

        expect($result)->toBe('done')
            ->and($attempts)->toBe(3);
    });

    it('does not go on after a lock wait timeout: the lock is not coming back sooner', function (): void {
        $attempts = 0;

        expect(function () use (&$attempts): void {
            DeadlockRetries::run(function () use (&$attempts): void {
                $attempts++;

                throw queryFailure('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');
            });
        })->toThrow(QueryException::class)
            ->and($attempts)->toBe(1);
    });

    it('does not go on after another failure', function (): void {
        $attempts = 0;

        expect(function () use (&$attempts): void {
            DeadlockRetries::run(function () use (&$attempts): void {
                $attempts++;

                throw new LogicException('A bug.');
            });
        })->toThrow(LogicException::class)
            ->and($attempts)->toBe(1);
    });

    it('gives up after its attempts, with the last deadlock', function (): void {
        $attempts = 0;

        expect(function () use (&$attempts): void {
            DeadlockRetries::run(function () use (&$attempts): void {
                $attempts++;

                throw queryFailure('40P01', 7, 'ERROR: deadlock detected');
            });
        })->toThrow(QueryException::class)
            ->and($attempts)->toBe(DeadlockRetries::ATTEMPTS);
    });
});
