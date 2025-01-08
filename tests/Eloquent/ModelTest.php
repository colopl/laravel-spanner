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

namespace Colopl\Spanner\Tests\Eloquent;

use Colopl\Spanner\Eloquent\Model;
use Colopl\Spanner\Tests\TestCase;
use Google\Cloud\Spanner\Bytes;
use Google\Cloud\Spanner\Date;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $userId
 * @property string $name
 * @property UserInfo $info
 * @property Collection<int, UserItem> $items
 */
class User extends Model
{
    protected $table = 'User';
    protected $primaryKey = 'userId';
    protected $keyType = 'string';
    public $timestamps = false;

    public function info(): HasOne
    {
        return $this->hasOne(UserInfo::class, 'userId');
    }

    public function items(): HasMany
    {
        return $this->hasMany(UserItem::class, 'userId');
    }
}

/**
 * @property string $userId
 * @property string $userInfoId
 * @property int $rank
 * @property User $user
 */
class UserInfo extends Model
{
    protected $table = 'UserInfo';
    protected $primaryKey = 'userInfoId';
    protected $keyType = 'string';
    protected $interleaveKeys = ['userId', 'userInfoId'];
    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}

/**
 * @property string $userId
 * @property string $userItemId
 * @property string $itemId
 * @property int $count
 * @property User $user
 */
class UserItem extends Model
{
    protected $table = 'UserItem';
    protected $primaryKey = 'userItemId';
    protected $keyType = 'string';
    protected $interleaveKeys = ['userId', 'userItemId'];
    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}

/**
 * @property string $itemId
 * @property string $name
 * @property Collection<int, Tag> $tags
 */
class Item extends Model
{
    protected $table = 'Item';
    protected $primaryKey = 'itemId';
    protected $keyType = 'string';
    public $timestamps = false;

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ItemTag', 'itemId', 'tagId');
    }
}

/**
 * @property string $tagId
 */
class Tag extends Model
{
    protected $table = 'Tag';
    protected $primaryKey = 'tagId';
    protected $keyType = 'string';
    public $timestamps = false;

    public function items()
    {
        return $this->belongsToMany(Item::class, 'ItemTag', 'itemId', 'tagId');
    }
}

/**
 * @property string $testId
 * @property string $uniqueStringTest
 * @property string $stringTest
 * @property string|null $nullableStringTest
 * @property int $intTest
 * @property int|null $nullableIntTest
 * @property float $floatTest
 * @property float|null $nullableFloatTest
 * @property Carbon $timestampTest
 * @property Carbon|null $nullableTimestampTest
 * @property Date $dateTest
 * @property Date|null $nullableDateTest
 * @property Bytes $bytesTest
 * @property Bytes|null $nullableBytesTest
 */
class Test extends Model
{
    protected $table = 'Test';
    protected $primaryKey = 'testId';
    protected $keyType = 'string';
    public $timestamps = false;
}

/**
 * @property int $id
 */
class Binding extends Model
{
    protected $table = 'Binding';
    public $timestamps = false;
}

/**
 * @property int $id
 * @property int $childId
 */
class BindingChild extends Model
{
    protected $table = 'BindingChild';
    protected $primaryKey = 'childId';
    protected $interleaveKeys = ['id', 'childId'];
    public $timestamps = false;
}

class IdentityTest extends Model
{
    protected $table = 'IdentityTest';
    protected $primaryKey = 'identityTestId';
    public $incrementing = true;
    public $timestamps = false;
}

class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getDefaultConnection();
    }

    protected function createTestUser(): User
    {
        $user = new User();
        $user->userId = $this->generateUuid();
        $user->name = 'test user on EloquentTest';
        return $user;
    }

    /**
     * @param string $userId
     * @param int $rank
     * @return UserInfo
     */
    protected function createTestUserInfo(string $userId, int $rank): UserInfo
    {
        $userInfo = new UserInfo();
        $userInfo->userId = $userId;
        $userInfo->userInfoId = $this->generateUuid();
        $userInfo->rank = $rank;
        return $userInfo;
    }

    /**
     * @param string $userId
     * @param string $itemId
     * @param int $count
     * @return UserItem
     */
    protected function createTestUserItem(string $userId, string $itemId, int $count): UserItem
    {
        $userItem = new UserItem();
        $userItem->userId = $userId;
        $userItem->userItemId = $this->generateUuid();
        $userItem->itemId = $itemId;
        $userItem->count = $count;
        return $userItem;
    }

    /**
     * @param string $stringTestValue
     * @return Test
     */
    protected function createTestTest(string $stringTestValue): Test
    {
        $test = new Test();
        $test->setRawAttributes($this->generateTestRow());
        $test->stringTest = $stringTestValue;
        return $test;
    }

    public function testCRUD(): void
    {
        // create
        /** @var User $user */
        $user = $this->createTestUser();
        $user->save();
        $this->assertDatabaseHas($user->getTable(), ['userId' => $user->userId, 'name' => $user->name]);

        // update
        $afterName = 'after update ' . $user->name;
        $user->name = $afterName;
        $user->save();
        $this->assertDatabaseHas($user->getTable(), ['userId' => $user->userId, 'name' => $afterName]);

        // delete
        $user->delete();
        $this->assertDatabaseMissing($user->getTable(), ['userId' => $user->userId]);
    }

    public function testBelongsTo(): void
    {
        /** @var User $user */
        $user = $this->createTestUser();
        $user->save();

        $itemId = $this->generateUuid();
        $itemCount = 99;
        /** @var UserItem $userItem */
        $userItem = $this->createTestUserItem($user->userId, $itemId, $itemCount);
        $userItem->save();

        $this->assertDatabaseHas($userItem->getTable(), ['userId' => $user->userId, 'itemId' => $itemId, 'count' => $itemCount]);

        $ownerUser = $userItem->user()->firstOrFail();
        $this->assertInstanceOf(User::class, $ownerUser);
        $this->assertSame($user->userId, $ownerUser->userId);
    }

    public function testHasOne(): void
    {
        $user = $this->createTestUser();
        $user->save();

        $rank = 12345;
        $userInfo = $this->createTestUserInfo($user->userId, $rank);
        $userInfo->save();

        $this->assertDatabaseHas($userInfo->getTable(), ['userId' => $user->userId, 'rank' => $rank]);

        /** @var UserInfo $fetchedUserInfo */
        $fetchedUserInfo = $user->info;
        $this->assertSame($user->userId, $fetchedUserInfo->userId);
        $this->assertSame($rank, $fetchedUserInfo->rank);
    }

    public function testHasMany(): void
    {
        $user = $this->createTestUser();
        $user->save();

        /** @var User $user */
        $user = User::find($user->userId);

        $userItemA = $this->createTestUserItem($user->userId, 'itemA', 12);
        $userItemB = $this->createTestUserItem($user->userId, 'itemB', 34);
        $user->items()->saveMany([$userItemA, $userItemB]);

        $this->assertDatabaseHas($userItemA->getTable(), ['userId' => $user->userId, 'itemId' => $userItemA->itemId, 'count' => $userItemA->count]);
        $this->assertDatabaseHas($userItemB->getTable(), ['userId' => $user->userId, 'itemId' => $userItemA->itemId, 'count' => $userItemA->count]);

        $fetchedUserItems = $user->items;
        $this->assertCount(2, $fetchedUserItems);

        $fetchedUser = $fetchedUserItems->first()->user;
        $this->assertSame($user->userId, $fetchedUser->userId);
    }

    public function testBelongsToMany(): void
    {
        $this->getConnection()->enableQueryLog();

        $item = new Item();
        $item->itemId = $this->generateUuid();
        $item->name = 'test';
        $item->saveOrFail();

        $tag = new Tag();
        $tag->tagId = $this->generateUuid();
        $tag->saveOrFail();

        $item->tags()->save($tag);

        $tagFromQuery = $item->tags->first();

        $this->assertSame($item->getKey(), $tagFromQuery->pivot->itemId);
        $this->assertSame($tag->getKey(), $tagFromQuery->pivot->tagId);
        $this->assertSame($tag->getKey(), $tagFromQuery->getKey());
    }

    public function testFind(): void
    {
        $user = $this->createTestUser();
        $user->save();
        User::findOrFail($user->userId);

        $user2 = $this->createTestUser();
        $user2->save();
        $this->assertCount(2, User::find([$user->userId, $user2->userId]));
    }

    public function testPreloadRelation(): void
    {
        /** @var User $user */
        $user = $this->createTestUser();
        $user->save();

        for ($i = 0; $i < 5; $i++) {
            $itemId = $this->generateUuid();
            $itemCount = 99;
            /** @var UserItem $userItem */
            $userItem = $this->createTestUserItem($user->userId, $itemId, $itemCount);
            $userItem->save();
        }

        $user->getConnection()->enableQueryLog();
        UserItem::all()
            ->map(fn(UserItem $item): User => $item->user);
        $this->assertTrue(count($user->getConnection()->getQueryLog()) > 5, 'preload をしていない場合、所持 User を取る SELECT 文が全部分かれるので 5個以上のクエリが発行されるはず');
        $user->getConnection()->flushQueryLog();

        UserItem::with('user')
            ->get()
            ->map(fn(UserItem $item): User => $item->user);
        $this->assertCount(2, $user->getConnection()->getQueryLog(), 'with() による preload をしていると、所持 User を取る SELECT 文が1つにまとめられるのでクエリログの個数は2になるはず');
    }

    public function testTestTableCRUD(): void
    {
        // create
        $test = $this->createTestTest('test1');
        $test->save();
        $this->assertDatabaseHas($test->getTable(), $test->getAttributes());

        // update
        $afterString = 'after update test1';
        $test->stringTest = $afterString;
        $test->save();
        $this->assertDatabaseHas($test->getTable(), ['stringTest' => $afterString]);

        // delete
        $test->delete();
        $this->assertDatabaseMissing($test->getTable(), [$test->getKeyName() => $test->getKey()]);
    }

    public function testInterleavedTableCRUD(): void
    {
        $user = $this->createTestUser();
        $user->save();

        // create
        $userItem = $this->createTestUserItem($user->userId, $this->generateUuid(), 12345);
        $userItem->save();
        $this->assertDatabaseHas($userItem->getTable(), $userItem->getAttributes());

        // update
        $afterCount = 54321;
        $userItem->count = 54321;
        $userItem->save();
        $this->assertDatabaseHas($userItem->getTable(), ['count' => $afterCount]);

        // delete
        $userItem->delete();
        $pkValues = [];
        foreach ($userItem->getInterleaveKeys() as $keyName) {
            $pkValues[$keyName] = $userItem->getAttribute($keyName);
        }
        $this->assertDatabaseMissing($userItem->getTable(), $pkValues);
    }

    public function testRouteBinding(): void
    {
        /** @var Router $router */
        $router = $this->app?->make('router');

        $record = new Binding();
        $record->id = 1;
        $record->save();

        $result = null;
        $router->middleware(SubstituteBindings::class)->get('/b/{b}', function (Binding $b) use (&$result) {
            $result = $b;
            return response()->noContent(200);
        });

        $this->get('/b/' . $record->id)
            ->assertOk();

        $this->assertSame($record->id, $result->id);
    }

    public function testChildRouteBinding(): void
    {
        /** @var Router $router */
        $router = $this->app?->make('router');

        $parentRecord = new Binding();
        $parentRecord->id = 1;
        $parentRecord->save();

        $childRecord = new BindingChild();
        $childRecord->id = 1;
        $childRecord->childId = 2;
        $childRecord->save();

        $results = [];
        $router->middleware(SubstituteBindings::class)->get('/p/{p}/c/{c}', function (Binding $p, BindingChild $c) use (&$results) {
            $results[] = $p;
            $results[] = $c;
            return response()->noContent(200);
        });

        $this->get('/p/' . $parentRecord->id . '/c/' . $childRecord->childId)
            ->assertOk();

        $this->assertSame($parentRecord->id, $results[0]->id);
        $this->assertSame($childRecord->childId, $results[1]->childId);
    }

    public function test_refresh_uses_interleaved_keys(): void
    {
        $user = $this->createTestUser();
        $user->save();
        $userInfo = $this->createTestUserInfo($user->getKey(), 1);
        $userInfo->save();

        DB::enableQueryLog();
        $userInfo->fresh();

        $queryLogs = DB::getQueryLog();

        $this->assertCount(1, $queryLogs);
        $this->assertStringContainsString('`userId`', $queryLogs[0]['query']);
        $this->assertSame(
            [$user->getKey(), $userInfo->getKey()],
            $queryLogs[0]['bindings'],
        );

        DB::flushQueryLog();

        $userInfo->refresh();

        $queryLogs = DB::getQueryLog();

        $this->assertCount(1, $queryLogs);
        $this->assertStringContainsString('`userId`', $queryLogs[0]['query']);
        $this->assertSame(
            [$user->getKey(), $userInfo->getKey()],
            $queryLogs[0]['bindings'],
        );
    }

    public function test_insertAndSetId(): void
    {
        $test = new IdentityTest();
        $test->name = 'test';
        $test->saveOrFail();
        $this->assertIsInt($test->getKey());
        $this->assertTrue($test->getKey() > 1000000000000000000);
    }
}
