<?php

namespace App\PopulationProjectionSimplified;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class Dwelling extends Household
{
    private $data;

    public function __construct($database, $local_authority, $start_year, $end_year)
    {
        parent::__construct($database, $local_authority, $start_year, $end_year);
    }

    public function runDwelling($households_final_data)
    {
        //
    }

    public function dwellingsCalculation($year)
    {
        $data = [];

        foreach ($this->combinations_all_relationships_and_all_sexes as $combination) {

            $households = $this->references[$year]['projection']['household'][$combination]->PREDICT_VALUE /
                (1 - env('VACANCY_RATE'));

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $households
            ];

        }

        $this->insertFinalDataFor($year, 'dwelling', $data);
    }



        //////////////////////////////////////////
        //////////  OVERRIDE FUNCTIONS  //////////
        //////////////////////////////////////////


    /**
     * You need year for when pop_start_year looks back at pop_end_year
     *
     * @param $year
     * @param $type
     * @param $name
     * @param $data
     * @throws \Exception
     */
    public function insertReferenceWithNoRelationshipsAndNoSexFor($year, $type, $name, $data)
    {
        $this->data = $data;

        // ages(18) + total row = 19
        $this->checkDataHasCorrectNumberOfEntries($data, $this->number_of_rows_count_no_relationships_and_no_sex + 1);

        $this->references[$year][$type][$name] = collect($data)->keyBy('combination');
    }
}