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

            //$this->chunkSize = $this->option('chunk');

            $this->info("Start seeding for $sourceName");

            // Clear Table
            $this->info("Truncating '{$tableName}' table...");
            DB::table($tableName)->truncate();

            $fileName = $this->removeCommentLines($sourceName);
            $this->info("Reading File '$fileName'");

            $sql = $this->otherFileSqls($tableName);
            DB::statement(sprintf($sql, $tableName), [$fileName]);
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

    public function removeCommentLines($sourceName)
    {
        $inputFile = storage_path("geo/{$sourceName}.txt");
        $outputFile = storage_path("geo/{$sourceName}_remove_comment.txt");

        $escapedInputFile = escapeshellarg($inputFile);
        $escapedOutputFile = escapeshellarg($outputFile);

        // Execute the sed command
        $command = "sed -e '/^#/d' -e '/^\\s*$/d' $escapedInputFile > $escapedOutputFile";

        // Run the command and capture the output and status
        $output = shell_exec($command . ' 2>&1'); // Capture error output
        $status = null; // Initialize status

        // Check if the command executed successfully
        if ($output === null) {
            $this->info("Command executed successfully.");
        } else {
            $this->info('Error executing command: ' . $output);
        }

        return $outputFile;
    }

    public function otherFileSqls($tableName)
    {
        switch ($tableName) {
            case 'geo_alternate_names':
                return <<<EOT
        LOAD DATA INFILE ?
    INTO TABLE %s
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n';
EOT;
                break;
            case 'geo_country_infos':
                return <<<EOT
        LOAD DATA INFILE ?
    INTO TABLE %s
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n'
(country,
@dummy,
@dummy,
@dummy,
@dummy,
@dummy,
@dummy,
@dummy,
@dummy,
@dummy,
currency_code,
@dummy,
@dummy,
@dummy,
@dummy,
languages,
geo_id
);
EOT;
                break;
            default:
                return;
        }
    }

    public function writeToDb($tableName, $sourceName)
    {
        // Store Tree in DB
        $this->info('Writing in Database</info>');

        [$stmt, $sql] = $this->getDBStatement($tableName);

        $count = 0;

        $progressBar = new ProgressBar($this->output, 100);

        $fileName = storage_path("geo/{$sourceName}.txt");
        $this->info("Reading File '$fileName'");

        $sql = $this->otherFileSqls($tableName, $fileName);

        $progressBar->finish();
    }
}
