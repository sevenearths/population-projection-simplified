<?php

namespace App\PopulationProjectionSimplified;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

class ProjectionBase extends Command
{
    // 'all males', 'all females', 'totals' are not stored in this because it will juts get messy
    public $references = [];
    // contains all combinations including 'all males', 'all females', 'totals'
    public $final_data = [];
    // will hold debug data for the projection instance and the household instance
    public $debug_data = [];

    // public for testing
    public $start_year;
    public $end_year;
    public $local_authority;
    protected $database;
    protected $select_with_combination;

    // Keep as the base class needs it to work (don't ask me why)
    protected $name = 'Projection';
    
    public function __construct($database, $local_authority, $start_year, $end_year)
    {
        parent::__construct();

        $this->output = new ConsoleOutput;

        $this->start_year = (int)$start_year;
        $this->end_year = (int)$end_year;
        $this->local_authority = $local_authority;
        $this->database = $database;

        $this->select_with_combination =
            DB::raw("PREDICT_VALUE, CONCAT(SEX, ', ', LPAD(AGE_VAL, 2, '0')) AS combination");
    }

    /**
     * (Ronseal)
     *
     * @param $table_name
     * @param $year
     * @return mixed
     */
    public function getDataFor($table_name, $year)
    {
        if ($table_name == 'national_birth') {
            return DB::table($table_name)->select(DB::raw("PREDICT_VALUE, CONCAT('total ',LOWER(SEX)) AS combination"))
                ->where('PREDICT_YEAR', $year)->orderBy('combination')->get();
            // [strpos($table_name, 'national') < 1] is for 'international_in', ...
        } elseif (strpos($table_name, 'national') !== false && strpos($table_name, 'national') < 1) {
            return DB::table($table_name)->select($this->select_with_combination)
                ->where('PREDICT_YEAR', $year)->orderBy('combination')->get();
        } else {
            return DB::table($table_name)->select($this->select_with_combination)
                ->where('PREDICT_YEAR', $year)->where('AREA_NAME', 'like', $this->local_authority)
                ->orderBy('combination')->get();
        }
    }

    /**
     * You need year for when pop_start_year looks back at pop_end_year
     *
     * @param $year
     * @param $type
     * @param $component_of_change
     * @param $data
     * @throws \Exception
     */
    public function insertReferenceFor($year, $type, $component_of_change, $data)
    {
        $this->checkDataForComponentOfChangeHasCorrectNumberOfEntries($year, $component_of_change, $data);

        $this->references[$year][$type][$component_of_change] = collect($data)->keyBy('combination');
    }

    /**
     * This data will be turned into excel data
     *
     * @param $year
     * @param $component_of_change
     * @param $data
     * @throws \Exception
     */
    protected function insertFinalDataFor($year, $component_of_change, $data)
    {
        $this->checkDataForComponentOfChangeHasCorrectNumberOfEntries($year, $component_of_change, $data);

        $collection = collect($data)->keyBy('combination');

        if (count($collection) == 182) {

            $collection = $this->updateCollectionWithTotalsForSex($collection);

        } else {

            $collection = $this->updateCollectionWithTotal($collection);

        }
        
        $this->final_data[$year][$component_of_change] = $collection;
    }

    /**
     * (Ronseal)
     *
     * @param $data
     */
    protected function insertDebugData($data)
    {
        if (env('DEBUG_CALCULATIONS') == true) {

            $this->debug_data = array_merge($this->debug_data, $data);

        }
    }

    /**
     * (Ronseal)
     *
     * @param $collection
     * @return mixed
     */
    protected function updateCollectionWithTotalsForSex($collection)
    {
        $total_males = 0;
        $total_females = 0;

        foreach ($collection as $key => $value) {

            if ($this->combinationContainsMales($key)) {
                $total_males += $value->PREDICT_VALUE;
            }

            if ($this->combinationContainsFemales($key)) {
                $total_females += $value->PREDICT_VALUE;
            }
        }

        $collection->put('total males', (object)['combination' => 'all males', 'PREDICT_VALUE' => $total_males]);
        $collection->put('total females', (object)['combination' => 'all females', 'PREDICT_VALUE' => $total_females]);

        $collection->put('total', (object)['combination' => 'total', 'PREDICT_VALUE' => $total_males + $total_females]);

        return $collection;
    }

    /**
     * (Ronseal)
     *
     * @param $collection
     * @return mixed
     */
    protected function updateCollectionWithTotal($collection)
    {
        $collection->put('total', (object)['combination' => 'total', 'PREDICT_VALUE' => $collection->sum('PREDICT_VALUE')]);
        
        return $collection;
    }

    /**
     * (Ronseal)
     *
     * @throws \Exception
     */
    protected function checkDatabaseHasDataForStartAndEndYear()
    {
        $start_year_entry = DB::table('deaths')->where('AREA_NAME', 'like', $this->local_authority)
            ->where('PREDICT_YEAR', $this->start_year)->first();

        $end_year_entry = DB::table('deaths')->where('AREA_NAME', 'like', $this->local_authority)
            ->where('PREDICT_YEAR', $this->end_year)->first();
        
        if ($start_year_entry == null) {
            throw new \Exception('There is no data for the Start Year ' . $this->start_year);
        }

        if ($end_year_entry == null) {
            throw new \Exception('There is no data for the End Year ' . $this->end_year);
        }
    }

    /**
     * (Ronseal)
     */
    protected function ifExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike($table)
    {
        $row = DB::table($table)->where('AREA_NAME', '=', $this->local_authority)->first();

        if ($row == null) {
            $this->local_authority .= '%';
        }
    }

    /**
     * (Ronseal)
     *
     * @param $component_of_change
     * @param $data
     * @throws \Exception
     */
    private function checkDataForComponentOfChangeHasCorrectNumberOfEntries($year, $component_of_change, $data)
    {
        if ($component_of_change == 'births' && count($data) == 2) {
            //
        } elseif ($component_of_change == 'births_by_age_of_mother' && count($data) == 30) {
            //
        } elseif ($component_of_change != 'Births' &&
            $component_of_change != 'births_by_age_of_mother' &&
            count($data) == 182
        ) {
            //
        } else {
            Log::debug('year being processed = ' . $year);
            throw new \Exception('The component of Change \''.$component_of_change.'\' has \''.
                count($data).'\' entries');
        }
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return bool
     */
    protected function combinationContainsMales($combination)
    {
        if (strpos($combination, 'males') !== false && strpos($combination, ',') !== false &&
            strpos($combination, 'females') === false) {
            return true;
        }
        return false;
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return bool
     */
    protected function combinationContainsFemales($combination)
    {
        if (strpos($combination, 'females') !== false && strpos($combination, ',') !== false) {
            return true;
        }
        return false;
    }

    /**
     * 'males, 31' > 'males, 30'
     *
     * @param $combination
     * @return string
     */
    protected function combinationYounger($combination)
    {
        if (strpos($combination, '00') === false) {
            return substr($combination, 0, -2) .
            str_pad((int)substr($combination, -2, count($combination) + 1) - 1, 2, '0', STR_PAD_LEFT);
        } else {
            return $combination;
        }
    }

    /**
     * if enabled it adds another tab to the spreadsheet called 'debug'
     *
     * @param $year
     * @return bool
     */
    protected function enableDebug($year)
    {
        if (env('DEBUG_CALCULATIONS') == true) {
            if (env('DEBUG_FIRST_YEAR_ONLY') == false || (env('DEBUG_FIRST_YEAR_ONLY') == true && $year == $this->start_year)) {
                return true;
            }
        }
        return false;
    }
}
