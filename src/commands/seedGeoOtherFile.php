<?php

namespace Igaster\LaravelCities\commands;

use Exception;
use Igaster\LaravelCities\commands\helpers\geoCollection;
use Igaster\LaravelCities\commands\helpers\geoItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;

class seedGeoOtherFile extends Command
{
    protected $signature = 'geo:seed-other {--chunk=1000}';
    protected $description = 'Load + Parse + Save other geo files to DB.';

    private $pdo;
    private $driver;

    private $geoItems;

    private $batch = 0;

    private $chunkSize = 1000;

    public function __construct()
    {
        parent::__construct();

        $connection = config('database.default');
        $this->driver = strtolower(config("database.connections.{$connection}.driver"));

        $this->geoItems = new geoCollection();
    }

    public function sql($sql)
    {
        $result = $this->pdo->query($sql);
        if ($result === false) {
            throw new Exception("Error in SQL : '$sql'\n" . PDO::errorInfo(), 1);
        }

        return $result->fetch();
    }

    /**
     * Get fully qualified table name with prefix if any
     *
     * @return string
     */
    public function getFullyQualifiedTableName($tableName): string
    {
        return DB::getTablePrefix() . $tableName;
    }

    protected function getColumnsAsStringDelimated($tableName, $delimeter = '"', bool $onlyPrefix = false)
    {
        $columns = [
            'id',
            'parent_id',
            'left',
            'right',
            'depth',
            'name',
            'alternames',
            'country',
            'a1code',
            'level',
            'population',
            'lat',
            'long',
            'timezone',
        ];

        $columns = [];
        switch ($tableName) {
            case 'geo_alternate_names':
                $columns = [
                    'alternate_name_id',
                    'geo_id',
                    'isolanguage',
                    'alternate_name',
                    'is_preferred_name',
                    'is_short_name',
                    'is_colloquial',
                    'is_historic',
                ];
                break;
            case 'geo_country_infos':
                $columns = [
                    'country',
                    'currency_code',
                    'languages',
                    'geo_id',
                ];
                break;
            default:
                return;
        }

        $modifiedColumns = [];

        foreach ($columns as $column) {
            $modifiedColumns[] = $delimeter . $column . (($onlyPrefix) ? '' : $delimeter);
        }

        return implode(',', $modifiedColumns);
    }

    public function getDBStatement($tableName): array
    {
        $sql = "INSERT INTO {$this->getFullyQualifiedTableName($tableName)} ( {$this->getColumnsAsStringDelimated($tableName)} ) VALUES ( {$this->getColumnsAsStringDelimated($tableName, ':', true)} )";

        if ($this->driver == 'mysql') {
            $sql = "INSERT INTO {$this->getFullyQualifiedTableName($tableName)} ( {$this->getColumnsAsStringDelimated($tableName, '`')} ) VALUES ( {$this->getColumnsAsStringDelimated($tableName, ':', true)} )";
        }

        return [$this->pdo->prepare($sql), $sql];
    }

    public function processItems($country)
    {
        //reset the chunk
        $this->geoItems->reset();

        $this->info(PHP_EOL . 'Processed Batch ' . $this->batch);
        $this->batch++;
    }

    public function handle()
    {
        $otherFiles = [
            'geo_alternate_names' => 'alternateNamesV2',
            'geo_country_infos' => 'countryInfo'
        ];

        $this->pdo = DB::connection()->getPdo(PDO::FETCH_ASSOC);

        foreach ($otherFiles as $tableName => $sourceName) {
            if (! Schema::hasTable($tableName)) {
                $this->error("{$tableName} table has not exists!");
                return;
            }
        }

        $start = microtime(true);

        DB::beginTransaction();

        foreach ($otherFiles as $tableName => $sourceName) {

            $this->chunkSize = $this->option('chunk');

            $this->info("Start seeding for $sourceName");

            // Clear Table
            $this->info("Truncating '{$this->getFullyQualifiedTableName()}' table...");
            DB::table($tableName)->truncate();

            // write to persistent storage
            $this->writeToDb($tableName, $sourceName);
        }

        //Lets get back MySQL FOREIGN_KEY_CHECKS to laravel
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        $this->info(PHP_EOL . ' Relation checks enabled');

        DB::commit();

        $this->info(' Done</info>');
        $time_elapsed_secs = microtime(true) - $start;
        $this->info("Timing: $time_elapsed_secs sec</info>");
    }

    public function otherFileParams($tableName, array $line)
    {

        $params = [];
        switch ($tableName) {
            case 'geo_alternate_names':
                $params = [
                    ':alternate_name_id' => $line[0],
                    ':geo_id' => $line[1],
                    ':isolanguage' => $line[2],
                    ':alternate_name' => $line[3],
                    ':is_preferred_name' => $line[4],
                    ':is_short_name' => $line[5],
                    ':is_colloquial' => $line[6],
                    ':is_historic' => $line[7],
                ];
                break;
            case 'geo_country_infos':
                $params = [
                    ':country' => $line[0],
                    ':currency_code' => $line[10],
                    ':languages' => $line[15],
                    ':geo_id' => $line[16],
                ];
                break;
            default:
                break;
        }

        return $params;
    }

    public function writeToDb($tableName, $sourceName)
    {
        // Store Tree in DB
        $this->info('Writing in Database</info>');

        [$stmt, $sql] = $this->getDBStatement($tableName);

        $count = 0;
        $totalCount = count($this->geoItems->items);

        $progressBar = new ProgressBar($this->output, 100);

        $fileName = storage_path("geo/{$sourceName}.txt");
        $this->info("Reading File '$fileName'");


        $handle = fopen($fileName, 'r');

        while (($line = fgets($handle)) !== false) {
            // ignore empty lines and comments
            if (! $line || $line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $progress = $count++ / $totalCount * 100;
            $progressBar->setProgress($progress);
            // Check for errors
            //dd($line[0], $line[2]);

            // Convert TAB sepereted line to array
            $line = explode("\t", $line);

            $params = $this->otherFileParams($tableName, $line);

            if ($stmt->execute($params) === false) {
                $error = "Error in SQL : '$sql'\n" . PDO::errorInfo() . "\nParams: \n$params";
                throw new Exception($error, 1);
            }

            $progress = ftell($handle) / $filesize * 100;
            $progressBar->setProgress($progress);
        }

        $progressBar->finish();
    }
}
