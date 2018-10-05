<?php

namespace App\Console\Commands;

use App\PopulationProjectionSimplified\Projection;
use App\PopulationProjectionSimplified\Household;
use App\PopulationProjectionSimplified\Dwelling;
use App\PopulationProjectionSimplified\SimpleExcel;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateProjection extends Command
{
    private $count = [];
    private $database;
    private $end_year;
    private $start_time;
    private $start_year;
    private $progress_bar;
    private $local_authority;

    /**
     * @var \App\PopulationProjectionSimplified\Projection
     */
    private $projection;

    /**
     * @var \App\PopulationProjectionSimplified\Household
     */
    private $household;

    /**
     * @var \App\PopulationProjectionSimplified\Dwelling
     */
    private $dwelling;

    /**
     * @var \App\PopulationProjectionSimplified\SimpleExcel
     */
    private $excel;

    private $variables = [];
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'project:projection {database} {local_authority} {start_year} {end_year} {--no-questions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs a Projection on the data in Hugo\'s database';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        $this->start_time = Carbon::now();

        $variables = [
            'international_in_held_constant',
            'cross_border_in_held_constant',
            'births_by_age_of_mother_multiplier',
            'deaths_multiplier',
            'internal_in_multiplier',
            'internal_out_multiplier',
            'international_in_multiplier',
            'international_out_multiplier',
            'cross_border_in_multiplier',
            'cross_border_out_multiplier'
        ];

        foreach ($variables as $variable) {
            $this->variables[] = [
                str_replace('_', ' ', title_case($variable)),
                getenv(strtoupper($variable))
            ];
        }

        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->database = $this->argument('database');
        $this->local_authority = $this->argument('local_authority');
        $start_year_dirty = $this->argument('start_year');
        $end_year_dirty = $this->argument('end_year');

        $this->checkDatabaseNameIsInCorrectFormat($this->database);

        // set dynamically in the terminal
        config(['database.connections.mysql.database' => $this->database]);

        $this->checkStartAndEndDatesAreInCorrectFormat($start_year_dirty, $end_year_dirty);

        $this->start_year = (int)$start_year_dirty;
        $this->end_year = (int)$end_year_dirty;

        $this->checkProjectionReferenceDataWasFoundInDatabase($this->local_authority);

        if ($this->option('no-questions') == false) {

            $this->table(['Variable', 'Value'], $this->variables);

            if ($this->confirm('Are you happy with these values for the projection?') == false) {
                exit;
            }

        }
        
        
        $this->startProgressBar();

        $this->excel = new SimpleExcel($this->database, $this->local_authority, $this->start_year, $this->end_year);
        $this->excel->excelFileCreate();
        $this->progress_bar->advance();
        
        
            // Main Calculations

            $this->projection = new Projection($this->database, $this->local_authority, $this->start_year, $this->end_year);
            $this->runProjection();

            $this->excel->emptyExcelData();
    
            $this->household = new Household($this->database, $this->local_authority, $this->start_year, $this->end_year);
            $this->runHousehold();
    
            $this->excel->emptyExcelData();
    
            $this->dwelling = new Dwelling($this->database, $this->local_authority, $this->start_year, $this->end_year);
            $this->runDwelling();


        $this->excel->excelFileWriteOut();

        $this->progress_bar->finish();

        
        $this->comment('');
        $this->comment('File written to => \''.base_path().'/storage/export/'.$this->excel->excel_file_name.'.xls\'');

        $this->outputTimeTakenInfo();
    }

    /**
     * (Ronseal)
     */
    private function runProjection()
    {
        for ($year = $this->start_year; $year <= $this->end_year; $year++) {

            $this->projection->references[$year] = ['national', 'local', 'rates'];
            $this->projection->final_data[$year] = [];


            // BUILD REFERENCES

            // needed for 'Deaths'
            $this->projection->insertReferenceFor($year, 'rates', 'deaths', $this->projection->getDataFor('finalrates_deaths', $year));

            // needed for 'Internal In'
            $this->projection->insertReferenceFor($year, 'rates', 'internal_in', $this->projection->getDataFor('finalrates_internal_in', $year));
            $this->projection->insertReferenceFor($year, 'national', 'births', $this->projection->getDataFor('national_birth', $year));
            $this->projection->insertReferenceFor($year, 'national', 'deaths', $this->projection->getDataFor('national_death', $year));
            // (shifted down for the previous year)
            $this->projection->insertReferenceFor($year, 'national', 'population_at_start_of_year', $this->projection->getDataFor('national_pop', $year - 1));

            // needed for 'Internal Out'
            $this->projection->insertReferenceFor($year, 'rates', 'internal_out', $this->projection->getDataFor('finalrates_internal_out', $year));

            // needed for 'International In'
            $this->projection->insertReferenceFor($year, 'local', 'international_in', $this->projection->getDataFor('international_in', $year));

            // needed for 'International Out'
            $this->projection->insertReferenceFor($year, 'rates', 'international_out', $this->projection->getDataFor('finalrates_international_out', $year));

            // needed for 'Cross Border In'
            $this->projection->insertReferenceFor($year, 'local', 'cross_border_in', $this->projection->getDataFor('x_border_in', $year));

            // needed for 'Cross Border Out'
            $this->projection->insertReferenceFor($year, 'rates', 'cross_border_out', $this->projection->getDataFor('finalrates_x_border_out', $year));


            // CALCULATIONS

            $this->projection->calculateCOCPopulationAtStartOfYear($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCBirthsByAgeOfMother($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCBirths($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCDeaths($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCInternalIn($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCInternalOut($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCInternationalIn($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCInternationalOut($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCCrossBorderIn($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCCrossBorderOut($year);
            $this->progress_bar->advance();

            $this->projection->calculateCOCPopulationAtEndOfYear($year);
            $this->progress_bar->advance();

        }

        $this->excel->excelFileBuildFinalDataIntoExcelData($this->projection->final_data);
        $this->progress_bar->advance();

        foreach ($this->excel->getExcelData() as $row) {

            $this->excel->excelFileWriteRow('projection', $row);
            $this->progress_bar->advance();

        }

        if (env('DEBUG_CALCULATIONS') == true) {

            foreach ($this->projection->debug_data as $row) {

                $this->excel->excelFileWriteProjectionDebugRow($row);
                $this->progress_bar->advance();

            }

        }
    }

    /**
     * (Ronseal)
     */
    private function runHousehold()
    {
        for ($year = $this->start_year; $year <= $this->end_year; $year++) {

            $this->household->references[$year] = ['population', 'household'];
            $this->household->final_data[$year] = [];

            // BUILD REFERENCES

            $this->household->insertReferenceWithNoRelationshipsFor(
                $year,
                'population',
                'projection',
                $this->household->convertProjectionData(
                    $this->projection->final_data[$year]['population_at_end_of_year'], $year
                )
            );

            // 'Communal establishment var pop'
            $this->household->insertReferenceFor($year, 'population', 'institutional', $this->household->getDataFor('instpop_stage1', $year));

            // the population in households
            $this->household->insertReferenceFor($year, 'population', 'household', $this->household->getDataFor('hhpop_stage1', $year));

            // number of households (flat, apartment, house, ...)
            $this->household->insertReferenceFor($year, 'household', 'household', $this->household->getDataFor('hh_stage1', $year));

            // CALCULATIONS
            $this->household->HouseholdsCalculation($year);
            $this->progress_bar->advance();

        }

        // put '$this->final_data' in a format that excel can write
        $this->excel->excelFileBuildFinalDataIntoExcelData($this->household->final_data);
        $this->progress_bar->advance();

        foreach ($this->excel->getExcelData() as $row) {

            // writes it out to the 'household' sheet
            $this->excel->excelFileWriteRow('household', $row);
            $this->progress_bar->advance();

        }

        if (env('DEBUG_CALCULATIONS') == true) {

            foreach ($this->household->debug_data as $row) {

                $this->excel->excelFileWriteHouseholdDebugRow($row);
                $this->progress_bar->advance();

            }

        }

    }

    /**
     * (Ronseal)
     */
    private function runDwelling()
    {
        for ($year = $this->start_year; $year <= $this->end_year; $year++) {

            $this->dwelling->final_data[$year] = [];

            // BUILD REFERENCES

            $this->dwelling->insertReferenceWithNoRelationshipsAndNoSexFor(
                $year,
                'projection',
                'household',
                $this->household->final_data[$year]['household_projection']
            );

            // CALCULATIONS

            $this->dwelling->DwellingsCalculation($year);
            $this->progress_bar->advance();
        }

        // put '$this->final_data' in a format that excel can write
        $this->excel->excelFileBuildFinalDataIntoExcelData($this->dwelling->final_data);
        $this->progress_bar->advance();

        foreach ($this->excel->getExcelData() as $row) {

            // writes it out to the 'household' sheet
            $this->excel->excelFileWriteRow('dwelling', $row);
            $this->progress_bar->advance();

        }

        if (env('DEBUG_CALCULATIONS') == true) {

            foreach ($this->dwelling->debug_data as $row) {

                $this->excel->excelFileWriteHouseholdDebugRow($row);
                $this->progress_bar->advance();

            }

        }
    }

    /**
     * (Ronseal)
     *
     * @param $database
     * @throws \Exception
     */
    private function checkDatabaseNameIsInCorrectFormat($database)
    {
        preg_match('/^[a-z]{3,4}_20[0-9]{2}$/', $database, $matches);

        if (count($matches) == 0) {
            throw new \Exception('database name not in correct format');
        }

        $this->comment('Database name is in the correct format');
    }

    /**
     * (Ronseal)
     *
     * @param $start_year
     * @param $end_year
     * @throws \Exception
     */
    private function checkStartAndEndDatesAreInCorrectFormat($start_year, $end_year)
    {
        preg_match('/^20[0-9]{2}$/', $start_year, $matches);

        if ($matches == null) {
            throw new \Exception('start year is in the wrong format');
        }

        preg_match('/^20[0-9]{2}$/', $end_year, $matches);

        if ($matches == null) {
            throw new \Exception('end year is in the wrong format');
        }

        if ((int)$end_year < (int)$start_year) {
            throw new \Exception('your start year needs to be before your end year');
        }
    }

    /**
     * Makes sure the local authority reference data for the projection equations exists in the database
     *
     * @param $local_authority
     * @throws \Exception
     */
    private function checkProjectionReferenceDataWasFoundInDatabase($local_authority)
    {
        $tables = ['population', 'births', 'deaths', 'internal_in', 'internal_out', 'international_in',
            'international_out', 'x_border_in', 'x_border_out'];

        foreach ($tables as $table) {
            $this->checkLocalAuthorityExistsInTableInHugosDB($table, $local_authority);
        }

        $this->comment('\''.$local_authority.'\' was successfully found in the \''.$this->database.'\'');

    }

    /**
     * (Ronseal)
     *
     * @param $table
     * @param $local_authority
     * @throws \Exception
     */
    private function checkLocalAuthorityExistsInTableInHugosDB($table, $local_authority)
    {
        $entries = DB::table($table)->where('AREA_NAME', 'like', $local_authority.'%')->first();

        if ($entries == null) {
            throw new \Exception('The local authority \''.$local_authority.
                '\' does not exists in the table \''.$table.'\'');
        }
    }

    /**
     * (Ronseal)
     */
    private function startProgressBar()
    {
        // 1 = excel file create
        $total_rows = 1;

        // PROJECTIONS

        $year_diff = $this->end_year - $this->start_year + 1;

        // 185 = all combinations + 'total male' + 'total female' + 'total'
        // 31  = 'births by age of mother' + 'total'
        // 3   = 'births' + 'total'
        // 11  = spaces between each component of change
        // 1   = the header at the top
        $total_final_rows = (185 * 9) + 31 + 3 + 11 + 1;

        // + 1 = build final data into excel data
        $total_rows += ($year_diff * 11) + 1 + $total_final_rows;

        if (env('DEBUG_CALCULATIONS') == true) {
            
            // 182 = all combinations WITHOUT TOTALS
            // 30 = 'births by age of mother' WITHOUT TOTAL
            // 2 = 'births' WITHOUT TOTAL
            // 8 = because pop_start is not put in the debug sheet
            $debug_rows = (182 * 8) + 30 + 2;

            if (env('DEBUG_FIRST_YEAR_ONLY') == true) {
                $total_rows += 1 * $debug_rows;
            } else {
                $total_rows += $year_diff * $debug_rows;
            }
        }
        
        
        // HOUSEHOLDS

        // 1 = because there is only one calculation done in the households loop
        // 1 = excelFileBuildFinalDataIntoExcelData()
        // 19 = 18 age groups plus 'total'
        $total_rows += ($year_diff * 1) + 1 + 19;

        if (env('DEBUG_CALCULATIONS') == true) {
            // 36 = old projection data
            // 36 = head of household rate
            // 24 = communal establishment 75+ (24/6 = 4) [4 = for each combination it get the results for m/f, each
            //          combination has m/f in it so that is 4 for the age range]
            // 72 = communal establishment population percentage 75+ (3 relationship types * 2 sexes * 3 age ranges *
            //          4 m/f * the result of m/f calculations = 72)
            // 24 = communal establishment population percentage 75+ FINAL (24/6 = 4) [4 = for each combination it
            //          get the results for m/f, each combination has m/f in it so that is 4 for the age range]
            // 36 = household population calculation (18 age ranges * 2 sexes)
            // 72 = population relationship percentage (3 relationship types * 2 sexes * 3 age ranges * 4 m/f * the
            //          result of m/f calculations = 72)

            $debug_rows = 36 + 36 + 24 + 72 + 24 + 36 + 72;

            if (env('DEBUG_FIRST_YEAR_ONLY') == true) {
                $total_rows += 1 * $debug_rows;
            } else {
                $total_rows += $year_diff * $debug_rows;
            }
        }


        // DWELLINGS

        // 1 = because there is only one calculation done in the households loop
        // 1 = excelFileBuildFinalDataIntoExcelData()
        // 19 = 18 age groups plus 'total'
        $total_rows += ($year_diff * 1) + 1 + 19;

        
        $this->progress_bar = $this->output->createProgressBar($total_rows);
    }

    /**
     * The time taken for the command to run
     */
    private function outputTimeTakenInfo()
    {
        $this->comment('');
        $this->info('-------------------------------------------');
        $this->info('The process took ' . Carbon::now()->diffForHumans($this->start_time, true));
        $this->info('-------------------------------------------');
    }
}
