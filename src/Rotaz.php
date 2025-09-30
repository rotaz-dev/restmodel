<?php

namespace Rotaz\RestModel;

use Closure;
use Exception;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait Rotaz
{
    protected static $rotazConnection;
    protected ?bool $useAPI = false;
    protected ?string $baseUri = 'api';

    public function getSchema()
    {
        return $this->schema ?? [];
    }

    protected function rotazCacheReferencePath()
    {
        return (new \ReflectionClass(static::class))->getFileName();
    }

    protected function rotazShouldUseAPI(): bool
    {
        if( ! property_exists(static::class, 'useAPI')){
            return false;
        }

        return $this->useAPI;
    }

    protected function getBaseUri()
    {
        return $this->baseUri;

    }

    public function getRows()
    {
        if( property_exists(static::class, 'rows'))
            return $this->rows;
        if( $this->rotazShouldUseAPI())
            return $this->resolveList();
        return null;
    }

    protected function resolveList()
    {
       return static::newRequest('get');

    }



    protected function rotazShouldCache()
    {
        return property_exists(static::class, 'rows');
    }

    public static function resolveConnection($connection = null)
    {
        return static::$rotazConnection;
    }

    protected function rotazCachePath()
    {
        return implode(DIRECTORY_SEPARATOR, [
            $this->rotazCacheDirectory(),
            $this->rotazCacheFileName(),
        ]);
    }

    protected function rotazCacheFileName()
    {
        return config('rotaz.cache-prefix', 'rotaz').'-'.Str::kebab(str_replace('\\', '', static::class)).'.sqlite';
    }

    protected function rotazCacheDirectory()
    {
        return realpath(config('rotaz.cache-path', storage_path('framework/cache')));
    }

    protected static function booted()
    {
        static::creating(function ($record) {
            if (  $record->shouldUseAPI())
                static::newRequest('post' , $record);

        });
        static::saving(function ($record) {
            Log::debug('saving record: ' . $record->getKey());
        });

        static::updating(function ($record) {
            if (  $record->shouldUseAPI())
                static::newRequest('put' , $record);

        });
        static::deleting(function ($record) {
            if (  $record->shouldUseAPI())
                static::newRequest('delete' , $record);
        });

    }


    protected static function newRequest(string $verb , $record = null)
    {
        if(  !$record->shouldUseAPI())
            return;

        Log::debug('API request: ' ,  [ $verb , $record]);

        try {

            $response = match ($verb) {
                'get' => static::getApiClient()->get($record->getBaseUri() ),
                'post' => static::getApiClient()->post($record->getBaseUri(), $record->toArray()),
                'put' => static::getApiClient()->put($record->getBaseUri() . '/'. $record->getKey(), $record->toArray()),
                'delete' => static::getApiClient()->delete($record->getBaseUri() . '/'. $record->getKey(), $record->toArray()),
            };

            if ($response->successful()) {
                Log::debug('API response: ' ,  [
                    'verb ' => $verb,
                    'status ' => $response->status(),
                    'response' => json_encode( $response->json() , JSON_PRETTY_PRINT),
                ]);
                return $response->json();
            }else{
                Log::error('response: ' ,  [
                    'verb ' => $verb,
                    'status ' => $response->status(),
                    'response' =>  $response->reason() ,
                ]);
                throw new Exception($response->reason());
            }

        }
        catch (\Exception $e){
            throw $e;
        }
        catch (\Throwable $e){
            Log::error('response: ' ,  [ $e->getMessage()]);;
            throw new Exception($e->getMessage());
        }


    }

    public static function bootRotaz()
    {
        $instance = (new static);

        $cachePath = $instance->rotazCachePath();
        $dataPath = $instance->rotazCacheReferencePath();

        $states = [
            'cache-file-found-and-up-to-date' => function () use ($cachePath) {
                static::setSqliteConnection($cachePath);
            },
            'cache-file-not-found-or-stale' => function () use ($cachePath, $dataPath, $instance) {
                static::cacheFileNotFoundOrStale($cachePath, $dataPath, $instance);
            },
            'no-caching-capabilities' => function () use ($instance) {
                static::setSqliteConnection(':memory:');

                $instance->migrate();
            },
        ];

        switch (true) {
            case ! $instance->rotazShouldCache():
                $states['no-caching-capabilities']();
                break;

            case file_exists($cachePath) && filemtime($dataPath) <= filemtime($cachePath):
                $states['cache-file-found-and-up-to-date']();
                break;

            case file_exists($instance->rotazCacheDirectory()) && is_writable($instance->rotazCacheDirectory()):
                $states['cache-file-not-found-or-stale']();
                break;

            default:
                $states['no-caching-capabilities']();
                break;
        }
    }

    protected static function cacheFileNotFoundOrStale($cachePath, $dataPath, $instance)
    {
        file_put_contents($cachePath, '');

        static::setSqliteConnection($cachePath);

        $instance->migrate();

        touch($cachePath, filemtime($dataPath));
    }

    protected function newRelatedInstance($class)
    {
        return tap(new $class, function ($instance) {
            if (!$instance->getConnectionName()) {
                $instance->setConnection($this->getConnectionResolver()->getDefaultConnection());
            }
        });
    }

    protected static function setSqliteConnection($database)
    {
        $config = [
            'driver' => 'sqlite',
            'database' => $database,
        ];

        static::$rotazConnection = app(ConnectionFactory::class)->make($config);

        app('config')->set('database.connections.'.static::class, $config);
    }

    protected static function getApiClient()
    {
        $token = 'A9acxu3yNZSn6PFa9aPHVMQDePwUy1EdskN3lnYx7f52bf3f';
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];
        return  Http::withHeaders($headers)->baseUrl( env('ROTAZ_API_URL'));

    }


    public function migrate()
    {
        $rows = $this->getRows();
        $tableName = $this->getTable();

        if (!empty($rows)) {
            $this->createTable($tableName, $rows[0]);
            foreach (array_chunk($rows, $this->getRotazInsertChunkSize()) ?? [] as $inserts) {
                if (! empty($inserts)) {
                    static::insert($inserts);
                }
            }
        } else {
            $this->createTableWithNoData($tableName);
        }


    }

    public function createTable(string $tableName, $firstRow)
    {
        $this->createTableSafely($tableName, function ($table) use ($firstRow) {
            // Add the "id" column if it doesn't already exist in the rows.
            if ($this->incrementing && ! array_key_exists($this->primaryKey, $firstRow)) {
                $table->increments($this->primaryKey);
            }

            foreach ($firstRow as $column => $value) {
                switch (true) {
                    case is_int($value):
                        $type = 'integer';
                        break;
                    case is_numeric($value):
                        $type = 'float';
                        break;
                    case is_string($value):
                        $type = 'string';
                        break;
                    case is_object($value) && $value instanceof \DateTime:
                        $type = 'dateTime';
                        break;
                    default:
                        $type = 'string';
                }

                if ($column === $this->primaryKey && $type == 'integer') {
                    $table->increments($this->primaryKey);
                    continue;
                }

                $schema = $this->getSchema();

                $type = $schema[$column] ?? $type;

                $table->{$type}($column)->nullable();
            }

            if ($this->usesTimestamps() && (! in_array('updated_at', array_keys($firstRow)) || ! in_array('created_at', array_keys($firstRow)))) {
                $table->timestamps();
            }

            $this->afterMigrate($table);
        });
    }

    protected function afterMigrate(BluePrint $table)
    {
        //
    }

    public function createTableWithNoData(string $tableName)
    {
        $this->createTableSafely($tableName, function ($table) {
            $schema = $this->getSchema();

            if ($this->incrementing && ! in_array($this->primaryKey, array_keys($schema))) {
                $table->increments($this->primaryKey);
            }

            foreach ($schema as $name => $type) {
                if ($name === $this->primaryKey && $type == 'integer') {
                    $table->increments($this->primaryKey);
                    continue;
                }

                $table->{$type}($name)->nullable();
            }

            if ($this->usesTimestamps() && (! in_array('updated_at', array_keys($schema)) || ! in_array('created_at', array_keys($schema)))) {
                $table->timestamps();
            }
        });
    }

    protected function createTableSafely(string $tableName, Closure $callback)
    {
        /** @var \Illuminate\Database\Schema\SQLiteBuilder $schemaBuilder */
        $schemaBuilder = static::resolveConnection()->getSchemaBuilder();

        try {
            $schemaBuilder->create($tableName, $callback);
        } catch (QueryException $e) {
            if (Str::contains($e->getMessage(), [
                'already exists (SQL: create table',
                sprintf('table "%s" already exists', $tableName),
            ])) {
                // This error can happen in rare circumstances due to a race condition.
                // Concurrent requests may both see the necessary preconditions for
                // the table creation, but only one can actually succeed.
                return;
            }

            throw $e;
        }
    }

    public function usesTimestamps()
    {
        // Override the Laravel default value of $timestamps = true; Unless otherwise set.
        return (new \ReflectionClass($this))->getProperty('timestamps')->class === static::class
            ? parent::usesTimestamps()
            : false;
    }

    public function getRotazInsertChunkSize() {
        return $this->rotazInsertChunkSize ?? 100;
    }

    public function getConnectionName()
    {
        return static::class;
    }
}