<?php

namespace Tests\Integration\Schema\Directives;

use GraphQL\Deferred;
use Tests\DBTestCase;
use Tests\Utils\Models\Post;
use Tests\Utils\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Nuwave\Lighthouse\Schema\Values\CacheValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Schema\Factories\ValueFactory;

class CacheDirectiveTest extends DBTestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function itCanStoreResolverResultInCache()
    {
        $resolver = addslashes(self::class).'@resolve';
        $schema = "
        type User {
            id: ID!
            name: String @cache
        }
        type Query {
            user: User @field(resolver: \"{$resolver}\")
        }";

        $result = $this->execute($schema, '{ user { name } }');
        $this->assertEquals('foobar', array_get($result->data, 'user.name'));
        $this->assertEquals('foobar', app('cache')->get('user:1:name'));
    }

    /**
     * @test
     */
    public function itCanPlaceCacheKeyOnAnyField()
    {
        $resolver = addslashes(self::class).'@resolve';
        $schema = "
        type User {
            id: ID!
            name: String @cache
            email: String @cacheKey
        }
        type Query {
            user: User @field(resolver: \"{$resolver}\")
        }";

        $result = $this->execute($schema, '{ user { name } }');
        $this->assertEquals('foobar', array_get($result->data, 'user.name'));
        $this->assertEquals('foobar', app('cache')->get('user:foo@bar.com:name'));
    }

    /**
     * @test
     */
    public function itCanStoreResolverResultInPrivateCache()
    {
        $user = factory(User::class)->create();
        $resolver = addslashes(self::class).'@resolve';
        $schema = "
        type User {
            id: ID!
            name: String @cache(private: true)
        }
        type Query {
            user: User @field(resolver: \"{$resolver}\")
        }";

        $this->be($user);
        $cacheKey = "auth:{$user->getKey()}:user:1:name";
        $result = $this->execute($schema, '{ user { name } }');
        $this->assertEquals('foobar', array_get($result->data, 'user.name'));
        $this->assertEquals('foobar', app('cache')->get($cacheKey));
    }

    /**
     * @test
     */
    public function itCanStorePaginateResolverInCache()
    {
        factory(User::class, 5)->create();

        $schema = '
        type User {
            id: ID!
            name: String!
        }
        type Query {
            users: [User] @paginate(type: "paginator", model: "User") @cache
        }';

        $query = '{
            users(count: 5) {
                data {
                    id
                    name
                }
            }
        }';

        $this->execute($schema, $query, true);

        $result = app('cache')->get('query:users:count:5');

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(5, $result);
    }

    /**
     * @test
     */
    public function itCanCacheHasManyResolver()
    {
        $user = factory(User::class)->create();

        factory(Post::class, 3)->create([
            'user_id' => $user->getKey(),
        ]);

        $schema = '
        type Post {
            id: ID!
            title: String
        }
        type User {
            id: ID!
            name: String!
            posts: [Post] @hasMany(type: "paginator") @cache
        }
        type Query {
            user(id: ID! @eq): User @find(model: "User")
        }';

        $query = '{
            user(id: '.$user->getKey().') {
                id
                name
                posts(count: 3) {
                    data {
                        title
                    }
                }
            }
        }';

        $result = $this->execute($schema, $query, true)->data;
        $posts = app('cache')->get("user:{$user->getKey()}:posts:count:3");
        $this->assertInstanceOf(LengthAwarePaginator::class, $posts);
        $this->assertCount(3, $posts);

        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            // TODO: Find a better way of doing this
            if (! str_contains($query->sql, [
                'drop',
                'delete',
                'migrations',
                'aggregate',
                'limit 1',
            ])) {
                ++$queries;
            }
        });

        $cache = $this->execute($schema, $query, true)->data;

        // Get the the original user and the `find` directive checks the count
        $this->assertEquals(0, $queries);
        $this->assertEquals($result, $cache);
    }

    /**
     * @test
     */
    public function itCanUseCustomCacheValue()
    {
        $resolver = addslashes(self::class).'@resolve';
        $schema = "
        type User {
            id: ID!
            name: String @cache
        }
        type Query {
            user: User @field(resolver: \"{$resolver}\")
        }";

        /** @var ValueFactory $valueFactory */
        $valueFactory = app(ValueFactory::class);
        $valueFactory->cacheResolver(function ($arguments) {
            return new class($arguments) extends CacheValue {
                public function getKey()
                {
                    return 'foo';
                }
            };
        });

        $this->execute($schema, '{ user { name } }');
        $this->assertEquals('foobar', app('cache')->get('foo'));
    }

    /**
     * @test
     */
    public function itCanAttachTagsToCache()
    {
        config(['lighthouse.cache.tags' => true]);

        $user = factory(User::class)->create();
        factory(Post::class, 3)->create([
            'user_id' => $user->getKey(),
        ]);

        $tags = ['graphql:user:1', 'graphql:user:1:posts'];
        $schema = '
        type Post {
            id: ID!
            title: String
        }
        type User {
            id: ID!
            name: String!
            posts: [Post] @hasMany(type: "paginator") @cache
        }
        type Query {
            user(id: ID! @eq): User @find(model: "User")
        }';

        $query = '{
            user(id: '.$user->getKey().') {
                id
                name
                posts(count: 3) {
                    data {
                        title
                    }
                }
            }
        }';

        $result = $this->execute($schema, $query, true)->data;
        $posts = app('cache')->tags($tags)->get("user:{$user->getKey()}:posts:count:3");
        $this->assertInstanceOf(LengthAwarePaginator::class, $posts);
        $this->assertCount(3, $posts);

        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            // TODO: Find a better way of doing this
            if (! str_contains($query->sql, [
                'drop',
                'delete',
                'migrations',
                'aggregate',
                'limit 1',
            ])) {
                ++$queries;
            }
        });

        $cache = $this->execute($schema, $query, true)->data;

        // Get the the original user and the `find` directive checks the count
        $this->assertEquals(0, $queries);
        $this->assertEquals($result, $cache);
    }

    public function resolve()
    {
        return [
            'id' => 1,
            'name' => 'foobar',
            'email' => 'foo@bar.com',
        ];
    }
}
