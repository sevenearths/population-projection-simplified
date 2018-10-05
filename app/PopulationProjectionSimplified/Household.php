<?php

namespace App\PopulationProjectionSimplified;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class Household extends ProjectionBase
{
    public $test = [];
    private $excel;

    private $start_year_in_db        = 1991;
    private $end_year_in_db          = 2039;
    private $not_single_combinations = ['total males', 'total females', 'total'];

    private $number_of_rows_count                           = 108;
    private $number_of_rows_count_no_relationships          = 36;
    protected $number_of_rows_count_no_relationships_and_no_sex = 18;

    protected $combinations;
    protected $combinations_all_relationships;
    protected $combinations_all_relationships_and_all_sexes;
    private $ages = ['0_4', '5_9', '10_14', '15_19', '20_24', '25_29', '30_34', '35_39', '40_44', '45_49',
        '50_54', '55_59', '60_64', '65_69', '70_74', '75_79', '80_84', '85&'];
    private $ages_seventy_plus = ['75_79', '80_84', '85&'];
    private $sexes = ['F', 'M'];
    private $relationships = ['C', 'P', 'S'];

    public function __construct($database, $local_authority, $start_year, $end_year)
    {
        parent::__construct($database, $local_authority, $start_year, $end_year);
        
        $this->excel = new SimpleExcel($database, $local_authority, $start_year, $end_year);

        Config::set('database.connections.mysql.database', env('HOUSEHOLD_DB'));
        DB::purge('mysql');

        $this->ifExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike('hh_stage1');

        $this->checkDatabaseHasDataForStartAndEndYear();

        $this->select_with_combination =
            DB::raw("`".implode('`, `',range($this->start_year_in_db, $this->end_year_in_db))."` ,Age_Band, SEX, ".
                "Relationship, CONCAT(Age_Band, ', ', SEX, ', ', Relationship) AS combination, ".
                "LPAD(Age_band, 5, '0') AS Age_Band_Order");

        foreach ($this->ages as $age) {
            foreach ($this->sexes as $sex) {
                foreach ($this->relationships as $relationship) {
                    $this->combinations[] = $age . ', ' . $sex .', ' . $relationship;
                }
                $this->combinations_all_relationships[] = $age . ', ' . $sex .', All';
            }
            $this->combinations_all_relationships_and_all_sexes[] = $age . ', All, All';
        }

    }

    /**
     * FOR TESTING!!!
     */
    public function insertFinalData()
    {
        $test_case = new \TestCase();
        $this->references = $test_case->getFinalData();
    }

    /**
     * The MAIN function (NOT USED FOR COMMANDS)
     *
     * @param $projection_final_data
     * @throws \Exception
     */
    public function runHousehold($projection_final_data)
    {
        for ($year = $this->start_year; $year <= $this->end_year; $year++) {

            $this->references[$year] = ['population', 'household'];
            $this->final_data[$year] = [];

            // BUILD REFERENCES

            $this->insertReferenceWithNoRelationshipsFor(
                $year,
                'population',
                'projection',
                $this->convertProjectionData(
                    $projection_final_data[$year]['population_at_end_of_year'], $year
                )
            );

            // 'Communal establishment var pop'
            $this->insertReferenceFor($year, 'population', 'institutional', $this->getDataFor('instpop_stage1', $year));

            // the population in households
            $this->insertReferenceFor($year, 'population', 'household', $this->getDataFor('hhpop_stage1', $year));

            // number of households (flat, apartment, house, ...)
            $this->insertReferenceFor($year, 'household', 'household', $this->getDataFor('hh_stage1', $year));

            // CALCULATIONS

            $this->HouseholdsCalculation($year);
        }

        // put '$this->final_data' in a format that excel can write
        $this->excel->excelFileBuildFinalDataIntoExcelData($this->final_data);

        foreach ($this->excel->getExcelData() as $row) {

            // writes it out to the 'household' sheet
            $this->excel->excelFileWriteRow('household', $row);

        }

        if (env('DEBUG_CALCULATIONS') == true) {

            foreach ($this->debug_data as $row) {

                $this->excel->excelFileWriteHouseholdDebugRow($row);

            }

        }

        $this->comment('File written to\''.base_path().'/storage/export/'.$this->excel->excel_file_name.'\'');
    }

    /**
     * final equation
     *
     * @param $year
     */
    public function HouseholdsCalculation($year)
    {
        // (DONE!) first put projection into 0-4, 5-9, etc...
        //
        //  households = household population calculation * multiplier * Head of Household Rate (HHR) [0_4][all sexes][all relationships]
        //
        //  (DONE!) household population calculation = projection - communal establishment population
        //
        //  (DONE!) Head of Household Rate (HHR) [0_4][all sexes][all relationships] = Households [0_4][all sexes][all relationships] /
        //      Household Population from DB [0_4][all sexes][all relationships]
        //
        //  (DONE!) Household Population from DB [0_4][all sexes][all relationships] = dev_hugo(household pop[75-79][M][singles]) +
        //      dev_hugo(household pop[75-79][M][couples]) + dev_hugo(household pop[75-79][M][previous]) +
        //      dev_hugo(household pop[75-79][F][singles]) + dev_hugo(household pop[75-79][F][couples]) +
        //      dev_hugo(household pop[75-79][F][previous]);
        //
        // hold_constant = FALSE
        // hc_year = 2015
        //
        // (By M/F then added together because the cal for 75-90)
        //
        //  (DONE!) (0-74) communal establishment population[0-4] = dev_hugo(Institutional Population[0-4][all relationships])
        //  (DONE!) (0-74) communal establishment population[5-9] = dev_hugo(Institutional Population[5-9][all relationships])
        //   ...
        //  (DONE!) (75-90) communal establishment population[75-79] =
        //
        //     if(hold_constant && (year > hc_year)) {
        //         return last year's result
        //     else
        //         return projection * communal establishment population percentage (CEPP) [0_4][M][all relationships]*
        //
        //   CEPP = CEPP[couples] + CEPP[singles] + CEPP[previously_married]
        //
        //     if(Communal establishment var pop[75-79][F][all relationships] +
        //         Household population aged 75+[75-79][F][all relationships] == 0) {
        //         CEPP[couples] = 0
        //     else
        //         CEPP[couples] = Communal establishment var pop[75-79][F][couples] /
        //                         (Communal establishment var pop[75-79][F][couples] +
        //                          household population aged 75+[75-79][F][couples])
        //
        //  (DONE!) Communal establishment var pop[75-79][F][couples] = dev_hugo(institutional pop[75-79][F][couples])
        //
        //  (DONE!) household population aged 75+[75-79][F][couples] = dev_hugo(household pop[75-79][F][couples])
        //
        //
        //  (* = The second on on the 'Com Est Calc' sheet)
        //

        $data = [];

        foreach ($this->combinations_all_relationships as $combination) {

            $households = $this->householdPopulationCalculation($year, $combination)->PREDICT_VALUE *
                env('HOUSEHOLD_MULTIPLIER') * $this->headOfHouseholdRate($year, $combination)->PREDICT_VALUE;

            // The final data only needs to be for age (i.e. '30_34, All, All') so just use the males
            // because the values for male and female are the same
            if (strpos($combination, ', F,') !== False) {

                $data[] = (object)[
                    'combination' => $this->makeSexAllInCombination($combination),
                    'PREDICT_VALUE' => $households
                ];

            }

        }

        $this->insertFinalDataFor($year, 'household_projection', $data);

    }

    /**
     * works out the head of household rate for use in the final equation
     *
     * @param $year
     * @param $combination
     * @return object
     */
    private function headOfHouseholdRate($year, $combination)
    {
        $households = $this->references[$year]['household']['household'][$this->getMaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['household']['household'][$this->getMaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['household']['household'][$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['household']['household'][$this->getFemaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['household']['household'][$this->getFemaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['household']['household'][$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE;

        $household_population = $this->references[$year]['population']['household'][$this->getMaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['population']['household'][$this->getMaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['population']['household'][$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['population']['household'][$this->getFemaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['population']['household'][$this->getFemaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE +
            $this->references[$year]['population']['household'][$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE;

        if ($this->enableDebug($year)) {

            $debug_formula = '($this->references['.$year.'][\'household\'][\'household\']['.$this->getMaleCombination($this->getSingleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'household\'][\'household\']['.$this->getMaleCombination($this->getCoupleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'household\'][\'household\']['.$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination)).'] + '.
                '$this->references['.$year.'][\'household\'][\'household\']['.$this->getFemaleCombination($this->getSingleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'household\'][\'household\']['.$this->getFemaleCombination($this->getCoupleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'household\'][\'household\']['.$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination)).']) / ('.
                '$this->references['.$year.'][\'population\'][\'household\']['.$this->getMaleCombination($this->getSingleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'population\'][\'household\']['.$this->getMaleCombination($this->getCoupleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'population\'][\'household\']['.$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination)).'] + '.
                '$this->references['.$year.'][\'population\'][\'household\']['.$this->getFemaleCombination($this->getSingleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'population\'][\'household\']['.$this->getFemaleCombination($this->getCoupleCombination($combination)).'] + '.
                '$this->references['.$year.'][\'population\'][\'household\']['.$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination)).'])';

            $debug_equation = '('.$this->references[$year]['household']['household'][$this->getMaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['household']['household'][$this->getMaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['household']['household'][$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['household']['household'][$this->getFemaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['household']['household'][$this->getFemaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['household']['household'][$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE.') / ('.
                $this->references[$year]['population']['household'][$this->getMaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['population']['household'][$this->getMaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['population']['household'][$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['population']['household'][$this->getFemaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['population']['household'][$this->getFemaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE.' + '.
                $this->references[$year]['population']['household'][$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE.')';

            $this->insertDebugData([
                $year,
                'head_of_house_hold_rate',
                $combination,
                $debug_formula,
                $debug_equation,
                $households / $household_population
            ]);
        }

        return (object)[
            'combination' => $combination,
            'PREDICT_VALUE' => $households / $household_population
        ];
    }

    /**
     * household population = projection[75-79][All][All] - communal establishment population[75-79][All][All]
     *
     * @param $year
     * @param $combination
     * @return object
     */
    private function householdPopulationCalculation($year, $combination)
    {
        $result = (
            $this->references[$year]['population']['projection'][$this->getMaleCombination($combination)]->PREDICT_VALUE +
            $this->references[$year]['population']['projection'][$this->getFemaleCombination($combination)]->PREDICT_VALUE
        ) - $this->communalEstablishmentPopulation($year, $combination)->PREDICT_VALUE;

        if ($this->enableDebug($year)) {

            $debug_formula = '('.
                    '$this->references[$year][\'population\'][\'projection\'][$this->getMaleCombination($combination)]->PREDICT_VALUE + '.
                    '$this->references[$year][\'population\'][\'projection\'][$this->getFemaleCombination($combination)]->PREDICT_VALUE'.
                ') - $this->communalEstablishmentPopulation($year, $combination)->PREDICT_VALUE';

            $debug_equation = '('.
                    $this->references[$year]['population']['projection'][$this->getMaleCombination($combination)]->PREDICT_VALUE.' + '.
                    $this->references[$year]['population']['projection'][$this->getFemaleCombination($combination)]->PREDICT_VALUE.
                ') - '.
                    $this->communalEstablishmentPopulation($year, $combination)->PREDICT_VALUE;

            $this->insertDebugData([
                $year,
                'household_population_calculation',
                $combination,
                $debug_formula,
                $debug_equation,
                $result
            ]);
        }

        return (object)[
            'combination' => $combination,
            'PREDICT_VALUE' => $result
        ];
    }

    /**
     * @param $year
     * @param $combination
     * @return object
     */
    private function communalEstablishmentPopulation($year, $combination)
    {
        $combination_is_over_seventy_five = false;

        foreach ($this->ages_seventy_plus as $age_range) {

            // see if the 75+ age ranges are in the combination
            if (strpos($combination, $age_range) !== false) {

                $combination_is_over_seventy_five = true;

            }
        }

        if ($combination_is_over_seventy_five == true) {

            return (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => (
                    $this->communalEstablishmentPopulationOverSeventyFive($year, $this->getFemaleCombination($combination))->PREDICT_VALUE +
                    $this->communalEstablishmentPopulationOverSeventyFive($year, $this->getMaleCombination($combination))->PREDICT_VALUE
                )
            ];

        } else {

            // use the old value (previous value) if it's exceeded the hold constant year
            if (env('HOUSEHOLD_HOLD_CONSTANT') == true && $year > env('HOUSEHOLD_HOLD_CONSTANT_YEAR')) {

                return (object)[
                    'combination' => $combination,
                    'PREDICT_VALUE' =>
                        $this->references[env('HOUSEHOLD_HOLD_CONSTANT_YEAR')]['communal_establishment']['population'][$combination]->PREDICT_VALUE
                ];

            } else {

                $communalEstablishmentPopulationPercentage =
                    $this->references[$year]['population']['institutional'][$this->getFemaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE +
                    $this->references[$year]['population']['institutional'][$this->getFemaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE +
                    $this->references[$year]['population']['institutional'][$this->getFemaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE +
                    $this->references[$year]['population']['institutional'][$this->getMaleCombination($this->getCoupleCombination($combination))]->PREDICT_VALUE +
                    $this->references[$year]['population']['institutional'][$this->getMaleCombination($this->getSingleCombination($combination))]->PREDICT_VALUE +
                    $this->references[$year]['population']['institutional'][$this->getMaleCombination($this->getPreviouslyMarriedCombination($combination))]->PREDICT_VALUE;

                // the function that will use this data if 'hold constant' is set
                // (can't use $this->insertReferenceWithNoRelationshipsFor(...) because it doesn't save individual entries)
                $this->references[$year]['communal_establishment']['population'][$combination] = (object)[
                    'combination' => $combination, 'PREDICT_VALUE' => $communalEstablishmentPopulationPercentage];

                return (object)[
                    'combination' => $combination,
                    'PREDICT_VALUE' => $communalEstablishmentPopulationPercentage
                ];

            }

        }

    }

    /**
     * @param $year
     * @param $combination
     * @return mixed
     */
    private function communalEstablishmentPopulationOverSeventyFive($year, $combination)
    {
        // use the old value (previous value) if it's exceeded the hold constant year
        if (env('HOUSEHOLD_OLD_CONSTANT') == true && $year > env('HOUSEHOLD_HOLD_CONSTANT_YEAR')) {

            $result = $this->references[env('HOUSEHOLD_HOLD_CONSTANT_YEAR')]['communal_establishment']
                ['population_percentage'][$combination]->PREDICT_VALUE;

            if ($this->enableDebug($year)) {
                $this->insertDebugData([
                    $year,
                    'communal_establishment_population_75_plus['.$combination.']',
                    $combination,
                    'communal establishment value from '.env('HOUSEHOLD_HOLD_CONSTANT_YEAR').' used',
                    'n/a',
                    $result
                ]);
            }

            return $result;
            
        } else {

            $communalEstablishmentPopulationPercentageOverSeventyFive =
                $this->communalEstablishmentPopulationPercentageOverSeventyFive($year, $combination)->PREDICT_VALUE;

            $result = $this->references[$year]['population']['projection'][$combination]->PREDICT_VALUE *
                $communalEstablishmentPopulationPercentageOverSeventyFive;

            if ($this->enableDebug($year)) {
                $debug_formula = '$this->references[$year][\'population\'][\'projection\'][$combination]->PREDICT_VALUE * '.
                    '$this->communalEstablishmentPopulationPercentageOverSeventyFive($year, $combination)->PREDICT_VALUE';
                $debug_equation = $this->references[$year]['population']['projection'][$combination]->PREDICT_VALUE.' * '.
                    $communalEstablishmentPopulationPercentageOverSeventyFive;
                $this->insertDebugData([
                    $year,
                    'communal_establishment_population_75_plus['.$combination.']',
                    $combination,
                    $debug_formula,
                    $debug_equation,
                    $communalEstablishmentPopulationPercentageOverSeventyFive
                ]);
            }

            // the function that will use this data if 'hold constant' is set
            // (can't use $this->insertReferenceWithNoRelationshipsFor(...) because it doesn't save individual entries)
            $this->references[$year]['communal_establishment']['population_percentage'][$combination] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $result
            ];

            return (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $result
            ];
            
        }
        
    }

    /**
     * Oh you have stumbled on a beauty. Let me unpack this cluster f*&k for you
     *
     * Combinations
     * ------------
     * '<age>, <sex>, <relationship>'
     * '<0-4/5_9/10_14>,<M/F>,<S/C/P>'
     * (i.e. '0_4, M, C')
     *
     * The combination (which will be something like '0_4, M, All') is unpacked into '0_4, M, S', '0_4, M, C',
     * '0_4, M, P' because 'communal_establishment_population_percentage' (CEPP) and 'population_relationship_percentage'
     * (PRP) have calculation that are per relationship. The result of each equation is stored in an array (i.e.
     * CEPP = ['single' => ..., 'couple' => ..., 'previously_married' => ...]. Then final at the end these six numbers
     * are brought together for the final number.
     *
     * Because the user has the ability to hold the Communal Establishment Population constant the result is also
     * stored in the references array should previous values need referencing.
     *
     * @param $year
     * @param $combination - $combination_all (i.e. '0_4, M, All', '40_44, F, All')
     * @return mixed
     * @throws \Exception
     */
    private function communalEstablishmentPopulationPercentageOverSeventyFive($year, $combination)
    {
        $debug_formula = '(none)';
        $debug_equation = '(none)';

        $communal_establishment_population_percentage = [];
        $population_relationship_percentage = [];

        foreach (
            [
                $this->getSingleCombination($combination),
                $this->getCoupleCombination($combination),
                $this->getPreviouslyMarriedCombination($combination)
            ] as $combination_with_relationship) {

            // ... = ['single/couple/previo...' => 0]
            $communal_establishment_population_percentage[
                $this->getCombinationRelationshipType($combination_with_relationship)] = 0;

            // ... = ['single/couple/previo...' => 0]
            $population_relationship_percentage[
                $this->getCombinationRelationshipType($combination_with_relationship)] = 0;

            if ($this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE +
                $this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE != 0) {

                $communal_establishment_population_percentage[
                    $this->getCombinationRelationshipType($combination_with_relationship)
                ] = $this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE /
                    ($this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE +
                        $this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE);

                if ($this->enableDebug($year)) {
                    $debug_formula = '$this->references['.$year.'][population][institutional]['.$combination_with_relationship.'] / '.
                        '($this->references['.$year.'][population][household]['.$combination_with_relationship.'] + '.
                        '$this->references['.$year.'][population][institutional]['.$combination_with_relationship.'])';
                    $debug_equation = $this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE.' / '.
                        '('.$this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE.')';
                    $this->insertDebugData([
                        $year,
                        'communal_establishment_population_percentage_75_plus['.$combination_with_relationship.']',
                        $combination,
                        $debug_formula,
                        $debug_equation,
                        $communal_establishment_population_percentage[
                            $this->getCombinationRelationshipType($combination_with_relationship)
                        ]
                    ]);
                }

            } else {

                if ($this->enableDebug($year)) {
                    $debug_formula = 'if($this->references[' . $year . '][population][institutional][' . $combination_with_relationship . '] +
                            $this->references[' . $year . '][population][household][' . $combination_with_relationship . '] == 0) { ' .
                        '$communal_establishment_population_percentage = 0; }';
                    $debug_equation = 'if(' . $this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE . ' + ' .
                        $this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE . ' == 0) { ' .
                        '$communal_establishment_population_percentage = 0; }';
                    $this->insertDebugData([
                        $year,
                        'communal_establishment_population_percentage_75_plus['.$combination_with_relationship.']',
                        $combination,
                        $debug_formula,
                        $debug_equation,
                        $communal_establishment_population_percentage[
                            $this->getCombinationRelationshipType($combination_with_relationship)
                        ]
                    ]);
                }

            }

            $population_relationship_percentage_initial =
                $this->references[$year]['population']['institutional'][$this->getSingleCombination($combination_with_relationship)]->PREDICT_VALUE +
                $this->references[$year]['population']['institutional'][$this->getCoupleCombination($combination_with_relationship)]->PREDICT_VALUE +
                $this->references[$year]['population']['institutional'][$this->getPreviouslyMarriedCombination($combination_with_relationship)]->PREDICT_VALUE +
                $this->references[$year]['population']['household'][$this->getSingleCombination($combination_with_relationship)]->PREDICT_VALUE +
                $this->references[$year]['population']['household'][$this->getCoupleCombination($combination_with_relationship)]->PREDICT_VALUE +
                $this->references[$year]['population']['household'][$this->getPreviouslyMarriedCombination($combination_with_relationship)]->PREDICT_VALUE;

            if ($population_relationship_percentage_initial == 0) {

                $population_relationship_percentage[$this->getCombinationRelationshipType($combination_with_relationship)] = 0;

                if ($this->enableDebug($year)) {
                    $debug_formula =
                        '($this->references['.$year.'][population][institutional]['.$combination_with_relationship.'] + '.
                            '$this->references['.$year.'][population][household]['.$combination_with_relationship.']) / '.
                        '($this->references['.$year.'][population][institutional]['.$this->getSingleCombination($combination_with_relationship).'] + '.
                            '$this->references['.$year.'][population][institutional]['.$this->getCoupleCombination($combination_with_relationship).'] + '.
                            '$this->references['.$year.'][population][institutional]['.$this->getPreviouslyMarriedCombination($combination_with_relationship).'] + '.
                            '$this->references['.$year.'][population][household]['.$this->getSingleCombination($combination_with_relationship).'] + '.
                            '$this->references['.$year.'][population][household]['.$this->getCoupleCombination($combination_with_relationship).'] + '.
                            '$this->references['.$year.'][population][household]['.$this->getPreviouslyMarriedCombination($combination_with_relationship).'])';
                    $debug_equation =
                        '('.$this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE.' + '.
                            $this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE.') / '.
                        '('.$this->references[$year]['population']['institutional'][$this->getSingleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                            $this->references[$year]['population']['institutional'][$this->getCoupleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                            $this->references[$year]['population']['institutional'][$this->getPreviouslyMarriedCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                            $this->references[$year]['population']['household'][$this->getSingleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                            $this->references[$year]['population']['household'][$this->getCoupleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                            $this->references[$year]['population']['household'][$this->getPreviouslyMarriedCombination($combination_with_relationship)]->PREDICT_VALUE.')';
                }

            } else {

                $population_relationship_percentage[
                    $this->getCombinationRelationshipType($combination_with_relationship)
                ] = ($this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE +
                    $this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE) /
                    $population_relationship_percentage_initial;

                if ($this->enableDebug($year)) {
                    $debug_formula =
                        '($this->references['.$year.'][population][institutional]['.$combination_with_relationship.'] + '.
                        '$this->references['.$year.'][population][household]['.$combination_with_relationship.']) / '.
                        '$this->references['.$year.'][population][institutional]['.$this->getSingleCombination($combination_with_relationship).'] + '.
                        '$this->references['.$year.'][population][institutional]['.$this->getCoupleCombination($combination_with_relationship).'] + '.
                        '$this->references['.$year.'][population][institutional]['.$this->getPreviouslyMarriedCombination($combination_with_relationship).'] + '.
                        '$this->references['.$year.'][population][household]['.$this->getSingleCombination($combination_with_relationship).'] + '.
                        '$this->references['.$year.'][population][household]['.$this->getCoupleCombination($combination_with_relationship).'] + '.
                        '$this->references['.$year.'][population][household]['.$this->getPreviouslyMarriedCombination($combination_with_relationship).']';
                    $debug_equation =
                        '('.$this->references[$year]['population']['institutional'][$combination_with_relationship]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['household'][$combination_with_relationship]->PREDICT_VALUE.') / '.
                        $this->references[$year]['population']['institutional'][$this->getSingleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['institutional'][$this->getCoupleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['institutional'][$this->getPreviouslyMarriedCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['household'][$this->getSingleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['household'][$this->getCoupleCombination($combination_with_relationship)]->PREDICT_VALUE.' + '.
                        $this->references[$year]['population']['household'][$this->getPreviouslyMarriedCombination($combination_with_relationship)]->PREDICT_VALUE;
                }
            }

            if ($this->enableDebug($year)) {
                $this->insertDebugData([
                    $year,
                    'population_relationship_percentage['.$combination_with_relationship.']',
                    $combination,
                    $debug_formula,
                    $debug_equation,
                    $population_relationship_percentage[$this->getCombinationRelationshipType($combination_with_relationship)]
                ]);
            }
        }

        $final_value = ($communal_establishment_population_percentage['single'] * $population_relationship_percentage['single']) +
            ($communal_establishment_population_percentage['couple'] * $population_relationship_percentage['couple']) +
            ($communal_establishment_population_percentage['previously_married'] *
                $population_relationship_percentage['previously_married']);

        $finalCommunalEstablishmentPopulationPercentageOverSeventyFive = (object)[
            'combination' => $combination,
            'PREDICT_VALUE' => $final_value
        ];

        // for the final equation
        if ($this->enableDebug($year)) {

            $debug_formula = '($communal_establishment_population_percentage[\'single\'] * $population_relationship_percentage[\'single\']) + '.
                '($communal_establishment_population_percentage[\'couple\'] * $population_relationship_percentage[\'couple\']) + '.
                '($communal_establishment_population_percentage[\'previously_married\'] * '.
                '$population_relationship_percentage[\'previously_married\'])';

            $debug_equation = '('.$communal_establishment_population_percentage['single'].' * '.$population_relationship_percentage['single'].') + ('.
                $communal_establishment_population_percentage['couple'].' * '.$population_relationship_percentage['couple'].') + ('.
                $communal_establishment_population_percentage['previously_married'].' * '.
                $population_relationship_percentage['previously_married'].')';

            $this->insertDebugData([
                $year,
                'communal_establishment_population_percentage_75_plus_FINAL['.$this->makeRelationshipAllInCombination($this->makeSexAllInCombination($combination)).']',
                $combination,
                $debug_formula,
                $debug_equation,
                $finalCommunalEstablishmentPopulationPercentageOverSeventyFive->PREDICT_VALUE
            ]);

        }

        return $finalCommunalEstablishmentPopulationPercentageOverSeventyFive;

    }

    /**
     * Because the data has to go from 'female, 38', 'female, 39', ... to '35-39, F, ALL'
     *
     * Coverts the projection data when it comes in
     *
     * @param $final_projection_data_by_year
     * @param $year
     * @return \Illuminate\Support\Collection
     * @throws \Exception
     */
    public function convertProjectionData($final_projection_data_by_year, $year)
    {
        $data = collect();

        $debug_formula = collect();
        $debug_equation = collect();

        $total_for_range = 0.0;

        foreach ($final_projection_data_by_year as $entry_key => $entry_data) {

            try {

                $age = (int)explode(',', $entry_key)[1];
                $sex = strtoupper(substr($entry_key, 0, 1));

                // add till ...
                $total_for_range += (float)$entry_data->PREDICT_VALUE;

                if ($this->enableDebug($year)) {
                    $debug_formula->push('fd[' . $age . ', ' . $sex . ']');
                    $debug_equation->push((float)$entry_data->PREDICT_VALUE);
                }

                // ... the five numbers have been added together
                //
                //   (fd[30, F] + fd[31, F] + fd[32, F] + fd[33, F] + fd[34, F])
                //   (fd[85, F] + fd[86, F] + fd[87, F] + fd[88, F] + fd[89, F] + fd[90, F])
                //
                if ((($age + 1) % 5 == 0 && $age != 89) || $age == 90) {

                    $combination = ($age - 4) . '_'.$age.', ' . $sex . ', All';
                    if ($age >= 89) { $combination = '85&, ' . $sex . ', All'; }

                    $data->push(
                        (object)[
                            'PREDICT_VALUE' => $total_for_range,
                            'combination' => $combination
                        ]
                    );

                    if ($this->enableDebug($year)) {
                        $this->insertDebugData([
                            $year,
                            'convertProjectionData['.$combination.']',
                            $combination,
                            $debug_formula->implode(' + '),
                            $debug_equation->implode(' + '),
                            $total_for_range
                        ]);
                        //Log::debug('['.$combination.'] = '.$debug_formula . ' = ' . $debug_equation);
                        $debug_formula = collect();
                        $debug_equation = collect();
                    }

                    $total_for_range = 0;

                }

            } catch(\Exception $e) {

                if (in_array($entry_key, $this->not_single_combinations) == false) {

                    throw new \Exception('The final projection data entry \'' . $entry_key . '\' was not in '.
                        'the correct format (\'<females/males>, <0,1,2,3,...>\')');

                }

            }

        }

        return $data;
    }



        //////////////////////////////////////////
        //////////  OVERRIDE FUNCTIONS  //////////
        //////////////////////////////////////////


    /**
     * You could do one query and loop through it but I can't ba arsed
     *
     * @param $table_name
     * @param $year
     * @return mixed
     */
    public function getDataFor($table_name, $year)
    {
        $data = DB::table($table_name)->select(DB::raw($this->select_with_combination))
            ->where('Area_Name', 'like', $this->local_authority)->where('Age_Band', '!=', 'TOT')
            ->orderBy('Relationship')->orderBy('SEX')->orderBy('Age_Band_Order')->get();

        $data_ordered = collect();

        foreach ($data as $row) {
            $data_ordered->push(
                (object)[
                    'PREDICT_VALUE' => $row->{$year},
                    'combination' => $row->combination
                ]
            );
        }

        return $data_ordered;
    }

    /**
     * You need year for when pop_start_year looks back at pop_end_year
     *
     * @param $year
     * @param $type
     * @param $name
     * @param $data
     * @throws \Exception
     */
    public function insertReferenceWithNoRelationshipsFor($year, $type, $name, $data)
    {
        // ages(18) * sexes(2) = 36
        $this->checkDataHasCorrectNumberOfEntries($data, $this->number_of_rows_count_no_relationships);

        $this->references[$year][$type][$name] = collect($data)->keyBy('combination');
    }

    /**
     * You need year for when pop_start_year looks back at pop_end_year
     *
     * @param $year
     * @param $type
     * @param $name
     * @param $data
     * @throws \Exception
     */
    public function insertReferenceFor($year, $type, $name, $data)
    {
        // ages(18) * sexes(2) * relationships(3) = 108
        $this->checkDataHasCorrectNumberOfEntries($data, $this->number_of_rows_count);

        $this->references[$year][$type][$name] = collect($data)->keyBy('combination');
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
        $this->checkDataHasCorrectNumberOfEntries($data, $this->number_of_rows_count_no_relationships_and_no_sex);

        $collection = collect($data)->keyBy('combination');

        $collection = $this->updateCollectionWithTotal($collection);

        $this->final_data[$year][$component_of_change] = $collection;
    }

    /**
     * (Ronseal)
     *
     * @param $collection
     * @return mixed
     */
    protected function updateCollectionWithTotals($collection)
    {
        $total_for_ages = [];

        foreach ($collection as $combination => $value) {

            $combination_array = explode(', ', $combination);

            if (array_key_exists($combination_array[0], $total_for_ages)) {
                $total_for_ages[$combination_array[0]] += $value;
            } else {
                $total_for_ages[$combination_array[0]] = $value;
            }

        }

        $total = 0;

        foreach ($total_for_ages as $age_range => $total_for_age_range) {
            $collection->put($age_range.', All, All', (object)['combination' => $age_range.', All, All', 'PREDICT_VALUE' => $total_for_age_range]);
            $total += $total_for_age_range;
        }

        $collection->put('total', (object)['combination' => 'total', 'PREDICT_VALUE' => $total]);

        return $collection;
    }

    /**
     * (Ronseal)
     *
     * @param $data
     */
    protected function insertDebugData($data)
    {
        if (env('DEBUG_CALCULATIONS') == true) {

            $this->debug_data[] = $data;

        }
    }



        /////////////////////////////////////////
        //////////  PRIVATE FUNCTIONS  //////////
        /////////////////////////////////////////


    /**
     * (Ronseal)
     *
     * @param $data
     * @param $num_entries
     * @throws \Exception
     */
    protected function checkDataHasCorrectNumberOfEntries($data, $num_entries)
    {
        if (count($data) != $num_entries) {
            throw new \Exception('The data has \'' . count($data) . '\' entries and it should have '.$num_entries);
        }
    }

    /**
     * I could compare to $start_year_in_db and $end_year_in_db but this way I'm getting
     * the information direct from the horses mouth (as it were)
     *
     * @throws \Exception
     */
    protected function checkDatabaseHasDataForStartAndEndYear()
    {
        try {
            DB::table('hh_stage1')->select($this->start_year)->first();
        } catch(\Exception $e) {
            throw new \Exception('The Household database \''.env('HOUSEHOLD_DB').'\' does not have column for '.
                'the year \''.$this->start_year.'\'');
        }
        try {
            DB::table('hh_stage1')->select($this->end_year)->first();
        } catch(\Exception $e) {
            throw new \Exception('The Household database \''.env('HOUSEHOLD_DB').'\' does not have column for '.
                'the year \''.$this->end_year.'\'');
        }
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    protected function makeSexAllInCombination($combination)
    {
        $sex = explode(', ', $combination);
        $sex[count($sex) - 2] = 'All';
        return implode(', ', $sex);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    protected function makeRelationshipAllInCombination($combination)
    {
        $sex = explode(', ', $combination);
        $sex[count($sex) - 1] = 'All';
        return implode(', ', $sex);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    protected function getMaleCombination($combination)
    {
        $male = explode(', ', $combination);
        $male[count($male) - 2] = 'M';
        return implode(', ', $male);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    protected function getFemaleCombination($combination)
    {
        $female = explode(', ', $combination);
        $female[count($female) - 2] = 'F';
        return implode(', ', $female);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    private function getSingleCombination($combination)
    {
        $couple = explode(', ', $combination);
        $couple[count($couple) - 1] = 'S';
        return implode(', ', $couple);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    private function getCoupleCombination($combination)
    {
        $single = explode(', ', $combination);
        $single[count($single) - 1] = 'C';
        return implode(', ', $single);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return array
     */
    private function getPreviouslyMarriedCombination($combination)
    {
        $previous = explode(', ', $combination);
        $previous[count($previous) - 1] = 'P';
        return implode(', ', $previous);
    }

    /**
     * (Ronseal)
     *
     * @param $combination
     * @return string
     * @throws \Exception
     */
    private function getCombinationRelationshipType($combination)
    {
        $single = explode(', ', $combination);
        $relationship_single_character = $single[count($single) - 1];
        switch ($relationship_single_character) {
            case 'S':
                return 'single';
            case 'C':
                return 'couple';
            case 'P':
                return 'previously_married';
            default:
                throw new \Exception($relationship_single_character . ' not recognised as a relationship type');
        }
    }
}
