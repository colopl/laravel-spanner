<?php

/**
 * Copyright 2019 Colopl Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Colopl\Spanner\Tests\Queue;

use Colopl\Spanner\Queue\MessageReceiver;
use Colopl\Spanner\Tests\TestCase;
use Generator;
use Illuminate\Support\Carbon;
use RuntimeException;

class MessageReceiverTest extends TestCase
{
    /**
     * How many streams the receiver opened.
     */
    protected int $opened = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A receiver whose n-th stream yields the n-th batch of rows.
     *
     * @param list<list<array<array-key, mixed>>> $batches
     */
    protected function receiver(array $batches, int $lifetime = 20): MessageReceiver
    {
        $this->opened = 0;

        return new MessageReceiver(
            function () use (&$batches): Generator {
                $this->opened++;
                $rows = array_shift($batches) ?? [];

                return (static function () use ($rows): Generator {
                    yield from $rows;
                })();
            },
            $lifetime,
        );
    }

    public function test_one_stream_serves_consecutive_calls(): void
    {
        $receiver = $this->receiver([[['id' => 'a'], ['id' => 'b']]]);

        $this->assertSame(['id' => 'a'], $receiver->next());
        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(1, $this->opened);
    }

    public function test_the_stream_is_only_opened_once_a_message_is_requested(): void
    {
        $this->receiver([[['id' => 'a']]]);

        $this->assertSame(0, $this->opened);
    }

    public function test_a_stream_opened_by_this_call_that_runs_dry_reports_an_empty_queue(): void
    {
        $receiver = $this->receiver([[]]);

        $this->assertNull($receiver->next());
        $this->assertSame(1, $this->opened);
    }

    public function test_a_stream_that_ran_dry_is_replaced_by_the_next_call(): void
    {
        $receiver = $this->receiver([[['id' => 'a']], [['id' => 'b']]]);

        $this->assertSame(['id' => 'a'], $receiver->next());
        $this->assertNull($receiver->next());
        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(2, $this->opened);
    }

    public function test_a_stream_within_its_lifetime_is_kept(): void
    {
        Carbon::setTestNow($start = Carbon::parse('2026-10-26 12:00:00'));

        $receiver = $this->receiver([[['id' => 'a'], ['id' => 'b']], [['id' => 'c']]], 20);
        $this->assertSame(['id' => 'a'], $receiver->next());

        Carbon::setTestNow($start->copy()->addSeconds(19));

        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(1, $this->opened);
    }

    public function test_a_stream_is_replaced_once_its_lifetime_has_elapsed(): void
    {
        Carbon::setTestNow($start = Carbon::parse('2026-10-26 12:00:00'));

        // The first stream is not exhausted, which is what a job that outlives
        // `block_for` looks like to the caller: the stream ended server side,
        // but the generator has not been advanced since.
        $receiver = $this->receiver([[['id' => 'a'], ['id' => 'stale']], [['id' => 'b']]], 20);
        $this->assertSame(['id' => 'a'], $receiver->next());

        Carbon::setTestNow($start->copy()->addSeconds(21));

        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(2, $this->opened);
    }

    public function test_a_stream_expires_exactly_at_its_lifetime(): void
    {
        Carbon::setTestNow($start = Carbon::parse('2026-10-26 12:00:00'));

        $receiver = $this->receiver([[['id' => 'a'], ['id' => 'stale']], [['id' => 'b']]], 20);
        $this->assertSame(['id' => 'a'], $receiver->next());

        Carbon::setTestNow($start->copy()->addSeconds(20));

        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(2, $this->opened);
    }

    public function test_the_lifetime_restarts_with_each_stream(): void
    {
        Carbon::setTestNow($start = Carbon::parse('2026-10-26 12:00:00'));

        $receiver = $this->receiver(
            [[['id' => 'a'], ['id' => 'x']], [['id' => 'b'], ['id' => 'c']]],
            20,
        );
        $this->assertSame(['id' => 'a'], $receiver->next());

        // Replaced at +21s; the second stream's own 20s then runs until +41s.
        Carbon::setTestNow($start->copy()->addSeconds(21));
        $this->assertSame(['id' => 'b'], $receiver->next());

        Carbon::setTestNow($start->copy()->addSeconds(40));
        $this->assertSame(['id' => 'c'], $receiver->next());
        $this->assertSame(2, $this->opened);
    }

    public function test_a_failed_stream_is_dropped_so_the_next_call_starts_afresh(): void
    {
        $opened = 0;
        $receiver = new MessageReceiver(
            function () use (&$opened): Generator {
                $opened++;

                return $opened === 1
                    ? (static function (): Generator {
                        yield ['id' => 'a'];
                        throw new RuntimeException('stream failed');
                    })()
                    : (static function (): Generator {
                        yield ['id' => 'b'];
                    })();
            },
            20,
        );

        $this->assertSame(['id' => 'a'], $receiver->next());

        try {
            $receiver->next();
            $this->fail('Expected the stream failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('stream failed', $e->getMessage());
        }

        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(2, $opened);
    }

    public function test_close_discards_the_stream(): void
    {
        $receiver = $this->receiver([[['id' => 'a'], ['id' => 'x']], [['id' => 'b']]]);
        $this->assertSame(['id' => 'a'], $receiver->next());

        $receiver->close();

        $this->assertSame(['id' => 'b'], $receiver->next());
        $this->assertSame(2, $this->opened);
    }
}
