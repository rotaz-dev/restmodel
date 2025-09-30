<?php

namespace Tests;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Orchestra\Testbench\TestCase;

use function Orchestra\Testbench\laravel_version_compare;

class RotazTest extends TestCase
{
    public $cachePath;

    public function setUp(): void
    {
        parent::setUp();

        config(['rotaz.cache-path' => $this->cachePath = __DIR__ . '/cache']);

        if (! file_exists($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }

        Foo::resetStatics();
        Bar::resetStatics();
        File::cleanDirectory($this->cachePath);
    }

    public function tearDown(): void
    {
        Foo::resetStatics();
        Bar::resetStatics();
        File::cleanDirectory($this->cachePath);

        parent::tearDown();
    }

    /** @test */
    function basic_usage()
    {
        $this->assertEquals(0, Foo::count());

        $this->assertEquals(2, Bar::count());
        $this->assertEquals('bar', Bar::first()->foo);
        $this->assertEquals('lob', Bar::whereBob('lob')->first()->bob);
    }

    /** @test */
    function columns_with_varying_types()
    {
        $row = ModelWithVaryingTypeColumns::first();
        $connectionBuilder = ModelWithVaryingTypeColumns::resolveConnection()->getSchemaBuilder();
        $this->assertEquals('integer', $connectionBuilder->getColumnType('model_with_varying_type_columns', 'int'));
        $this->assertEquals('float', $connectionBuilder->getColumnType('model_with_varying_type_columns', 'float'));
        $this->assertEquals('datetime', $connectionBuilder->getColumnType('model_with_varying_type_columns', 'dateTime'));
        $this->assertEquals(
            function_exists('\Orchestra\Testbench\laravel_version_compare') && laravel_version_compare('11.0.0', '>=') ? 'varchar' : 'string', 
            $connectionBuilder->getColumnType('model_with_varying_type_columns', 'string')
        );
        $this->assertEquals(null, $row->null);
    }

    /** @test */
    function model_with_custom_schema()
    {
        ModelWithCustomSchema::count();
        $connectionBuilder = ModelWithCustomSchema::resolveConnection()->getSchemaBuilder();
        $this->assertEquals(
            function_exists('\Orchestra\Testbench\laravel_version_compare') && laravel_version_compare('11.0.0', '>=') ? 'varchar' : 'string', 
            $connectionBuilder->getColumnType('model_with_custom_schemas', 'float')
        );
        $this->assertEquals(
            function_exists('\Orchestra\Testbench\laravel_version_compare') && laravel_version_compare('11.0.0', '>=') ? 'varchar' : 'string',
            $connectionBuilder->getColumnType('model_with_custom_schemas', 'string')
        );
    }

    /** @test */
    function models_using_the_get_rows_property_arent_cached()
    {
        Bar::$hasBeenAccessedBefore = false;
        $this->assertEquals(2, Bar::count());
        Bar::resetStatics();
        $this->assertEquals(3, Bar::count());
    }

    /** @test */
    function uses_in_memory_if_the_cache_directory_is_not_writeable_or_not_found()
    {
        config(['rotaz.cache-path' => $path = __DIR__ . '/non-existant-path']);

        Foo::count();

        $this->assertFalse(file_exists($path));
        $this->assertEquals(':memory:', (new Foo)->getConnection()->getDatabaseName());
    }

    /** @test */
    function caches_sqlite_file_if_storage_cache_folder_is_available()
    {
        Foo::count();

        print_r('CAche path ' . $this->cachePath . PHP_EOL);
        print_r('Database name ' . (new Foo)->getConnection()->getDatabaseName() . PHP_EOL);

        $this->assertTrue(file_exists($this->cachePath));





        /*$this->assertStringContainsString(
            'rotaz/tests/cache/rotaz-tests-foo.sqlite',
            str_replace('\\', '/', (new Foo())->getConnection()->getDatabaseName())
        );*/
    }

    /** @test */
    function avoids_error_when_creating_database_concurrently()
    {
        $actualFactory = app(ConnectionFactory::class);
        $actualConnection = $actualFactory->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->expects($this->once())
            ->method('make')
            ->willReturnCallback(function () use ($actualConnection) {
                // Simulate a concurrent request that creates the table at a point in time
                // where our main execution has already determined that it does not exist
                // and is about to create it.
                $actualConnection->getSchemaBuilder()->create('blanks', function ($table) {
                    $table->increments('id');
                });

                return $actualConnection;
            });

        $this->app->bind(ConnectionFactory::class, function () use ($connectionFactory) {
            return $connectionFactory;
        });

        // Triggers creation of the table
        (new Blank)->getConnection();
    }

    /**
     * @test
     * @group skipped
     * */
    function uses_same_cache_between_requests()
    {
        $this->markTestSkipped("I can't find a good way to test this right now.");
    }

    /** @test */
    function adds_primary_key_if_needed()
    {
        $this->assertEquals([5,6], ModelWithNonStandardKeys::orderBy('id')->pluck('id')->toArray());

    }


    /** @test */
    function it_returns_an_empty_collection()
    {
        $this->assertEquals(0, Blank::count());
    }

    /** @test */
    function can_use_exists_validation_rule()
    {
        ModelWithNonStandardKeys::all();
        Foo::all();

        $this->assertTrue(Validator::make(['bob' => 'lob'], ['bob' => 'exists:'.ModelWithNonStandardKeys::class.'.model_with_non_standard_keys'])->passes());

        $this->assertFalse(Validator::make(['id' => 4], ['id' => 'exists:'.ModelWithNonStandardKeys::class.'.model_with_non_standard_keys'])->passes());
        (int) explode('.', app()->version())[0] >= 6
            ? $this->assertFalse(Validator::make(['bob' => 'ble'], ['bob' => 'exists:'.ModelWithNonStandardKeys::class])->passes())
            : $this->assertFalse(Validator::make(['bob' => 'ble'], ['bob' => 'exists:'.ModelWithNonStandardKeys::class.'.model_with_non_standard_keys'])->passes());
    }

    /** @test */
    public function it_runs_method_after_migration_when_defined()
    {
        $model = ModelWithAddedTableOperations::all();
        $this->assertEquals('columnWasAdded', $model->first()->columnAdded, 'The runAfterMigrating method was not triggered.');
    }

    /**
     * @test
     * @define-env usesSqliteConnection
     * */
    function rotaz_models_can_relate_to_models_in_regular_sqlite_databases()
    {
        if (! trait_exists('\Orchestra\Testbench\Concerns\HandlesAnnotations')) {
            $this->markTestSkipped('Requires HandlesAnnotation trait to define sqlite connection using PHPUnit annotation');
        }

        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->artisan('migrate', ['--database' => 'testbench-sqlite'])->run();

        $productDefault = Product::create(['id' => 1, 'type' => 'Default']);

        $this->assertEquals(1, Category::find(1)->product->id);
        $this->assertCount(2, $productDefault->categories);
    }

    protected function usesSqliteConnection($app)
    {
        file_put_contents(__DIR__ . '/database/database.sqlite', '');

        $app['config']->set('database.default', 'testbench-sqlite');
        $app['config']->set('database.connections.testbench-sqlite', [
            'driver'   => 'sqlite',
            'database' => __DIR__ . '/database/database.sqlite',
            'prefix'   => '',
        ]);
    }
}

class Foo extends Model
{
    use \Rotaz\RestModel\Rotaz;


    public static function resetStatics()
    {
        static::setRotazConnection(null);
        static::clearBootedModels();
    }

    public static function setRotazConnection($connection)
    {
        static::$rotazConnection = $connection;
    }
}

class ModelWithNonStandardKeys extends Model
{
    use \Rotaz\RestModel\Rotaz;

    protected $rows = [
        ['id' => 5, 'foo' => 'bar', 'bob' => 'lob'],
        ['id' => 6, 'foo' => 'baz', 'bob' => 'law'],
    ];
}

class ModelWithVaryingTypeColumns extends Model
{
    use \Rotaz\RestModel\Rotaz;

    public function getRows()
    {
        return [[
            'int' => 123,
            'float' => 123.456,
            'datetime' => \Carbon\Carbon::parse('January 1 2020'),
            'string' => 'bar',
            'null' => null,
        ]];
    }
}

class ModelWithCustomSchema extends Model
{
    use \Rotaz\RestModel\Rotaz;

    protected $rows = [[
        'float' => 123.456,
        'string' => 'foo',
    ]];

    protected $schema = [
        'float' => 'string',
    ];
}

class ModelWithAddedTableOperations extends Model
{
    use \Rotaz\RestModel\Rotaz;

    protected $rows = [[
        'float' => 123.456,
        'string' => 'foo',
    ]];

    protected function afterMigrate(Blueprint $table)
    {
        $table->string('columnAdded')->default('columnWasAdded');
    }
}

class Bar extends Model
{
    use \Rotaz\RestModel\Rotaz;

    public static $hasBeenAccessedBefore = false;

    public function getRows()
    {
        if (static::$hasBeenAccessedBefore) {
            return [
                ['foo' => 'bar', 'bob' => 'lob'],
                ['foo' => 'baz', 'bob' => 'law'],
                ['foo' => 'baz', 'bob' => 'law'],
            ];
        } else {
            static::$hasBeenAccessedBefore = true;

            return [
                ['foo' => 'bar', 'bob' => 'lob'],
                ['foo' => 'baz', 'bob' => 'law'],
            ];
        }
    }

    public static function resetStatics()
    {
        static::setRotazConnection(null);
        static::clearBootedModels();
    }

    public static function setRotazConnection($connection)
    {
        static::$rotazConnection = $connection;
    }
}

class Baz extends Model
{
    use \Rotaz\RestModel\Rotaz;
}

class Blank extends Model
{
    use \Rotaz\RestModel\Rotaz;

    protected $columns = [
        'id' => 'integer',
        'name' => 'string'
    ];

    protected $rows = [];
}

class Product extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function categories()
    {
        return $this->hasMany(Category::class);
    }
}

class Category extends Model
{
    use \Rotaz\RestModel\Rotaz;

    protected $rows = [
        ['id' => 1, 'type' => 'man', 'product_id' => 1],
        ['id' => 2, 'type' => 'woman', 'product_id' => 1],
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
