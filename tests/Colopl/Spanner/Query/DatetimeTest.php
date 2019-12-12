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

namespace Colopl\Spanner\Tests\Query;

use Colopl\Spanner\Tests\TestCase;
use Google\Cloud\Spanner\Date;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;

class DatetimeTest extends TestCase
{
    public function testTimezone()
    {
        $db = (new SpannerClient())->connect(config('database.connections.main.instance'), config('database.connections.main.database'));

        date_default_timezone_set('UTC');

        /** @var Timestamp $ts */
        $ts = $db->execute('SELECT TIMESTAMP("2018-01-01T00:00:00.000000Z")')->rows()->current()[0];
        $this->assertEquals('Z', $ts->get()->getTimezone()->getName());
        $this->assertEquals('2018-01-01 00:00:00.000000+00:00', $ts->get()->format('Y-m-d H:i:s.uP'));

        /** @var Timestamp $ts2 */
        $ts2 = $db->execute('SELECT TIMESTAMP("2018-01-01T09:00:00.000000+09:00")')->rows()->current()[0];
        $this->assertEquals('Z', $ts2->get()->getTimezone()->getName());
        $this->assertEquals('2018-01-01 00:00:00.000000+00:00', $ts2->get()->format('Y-m-d H:i:s.uP'));


        // Cloud Spanner library will ignore PHP's default timezone and always returns UTC (Z)
        date_default_timezone_set('Asia/Tokyo');

        /** @var Timestamp $ts3 */
        $ts3 = $db->execute('SELECT TIMESTAMP("2018-01-01T09:00:00.000000+09:00")')->rows()->current()[0];
        $this->assertEquals('Z', $ts3->get()->getTimezone()->getName());
        $this->assertEquals('2018-01-01 00:00:00.000000+00:00', $ts3->get()->format('Y-m-d H:i:s.uP'));
    }

    public function testTimezoneWithQueryBuilder()
    {
        $conn = $this->getDefaultConnection();

        date_default_timezone_set('Asia/Tokyo');
        $expected = new \DateTime('2018-03-13 09:00:00');
        $this->assertEquals('Asia/Tokyo', $expected->getTimezone()->getName());

        $row = $conn->query()->selectRaw('TIMESTAMP("2018-03-13T00:00:00Z")')->get()->first();
        /** @var \DateTimeInterface $datetime */
        $datetime = $row[0];
        $this->assertEquals($expected->getTimestamp(), $datetime->getTimestamp());
        $this->assertEquals('Asia/Tokyo', $datetime->getTimezone()->getName());
    }

    public function testTimestampCreateWithNanoseconds()
    {
        $datetime = \DateTime::createFromFormat(Timestamp::FORMAT, '2018-03-13T16:40:12.345678Z');
        $ts = new Timestamp($datetime);

        $this->assertEquals($datetime->getTimestamp(), $ts->get()->getTimestamp());
        $this->assertEquals(345678000, $ts->nanoSeconds());
    }

    public function testFormatTimestamp()
    {
        $conn = $this->getDefaultConnection();
        $row = $conn->query()->selectRaw('TIMESTAMP("2018-03-13T16:40:12.300000Z")')->get()->first();
        /** @var \DateTimeInterface $datetime */
        $datetime = $row[0];
        $this->assertEquals('2018-03-13T16:40:12.300000Z', $datetime->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testDateType()
    {
        $conn = $this->getDefaultConnection();
        $row = $conn->query()->selectRaw('DATE("2018-06-07")')->get()->first();

        /** @var Date $date */
        $date = $row[0];
        $this->assertInstanceOf(Date::class, $date);

        $dateCarbon = \Illuminate\Support\Carbon::instance($date->get());
        $this->assertEquals(2018, $dateCarbon->year);
        $this->assertEquals(6, $dateCarbon->month);
        $this->assertEquals(7, $dateCarbon->day);
    }

    public function testTimestampWithConnection()
    {
        $conn = $this->getDefaultConnection();
        $row = $conn->selectOne('SELECT TIMESTAMP("2019-12-12T14:45:00+09:00") as ts');
        /** @var Timestamp $ts */
        $ts = $row['ts'];
        $this->assertInstanceOf(Timestamp::class, $ts);
        // Cloud Spanner TIMESTAMP always has UTC timezone
        $this->assertEquals('Z', $ts->get()->getTimezone()->getName());
    }
}