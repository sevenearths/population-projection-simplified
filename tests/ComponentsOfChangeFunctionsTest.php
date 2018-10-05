<?php

use App\PopulationProjectionSimplified\Projection;

use Illuminate\Support\Facades\DB;

class ComponentsOfChangeFunctionsTest extends TestCase
{
    private $combinations;
    private $year = 2013;
    private $start_year = 2013;
    private $end_year = 2023;
    private $num_tests_for_data = 10;

    /**
     * Can't put this in 'setUp()' because it does not recognise the config command
     */
    private function buildBaseData()
    {
        config(['database.connections.mysql.database' => $this->database]);

        $this->projection = new Projection($this->database, $this->local_authority, $this->start_year, $this->end_year);

        foreach (['females', 'males'] as $sex) {
            foreach (range(0, 90) as $age) {
                $this->combinations[] = $sex . ', ' . str_pad($age, 2, '0', STR_PAD_LEFT);
            }
        }

        // so we can pull projections from the final data
        $this->references['projection'] = $this->final_data;

        // to make the row data objects
        // (can't over-ride the constructor on a test class,
        //  can't declare the entries as objects when building the data)
        foreach ($this->references as $key_type => $type) {
            foreach ($type as $key_component_of_change => $component_of_change) {

                $data = [];

                foreach ($component_of_change as $key_combination => $combination) {
                    $data[] = (object)$combination;
                }

                $this->references[$this->year][$key_type][$key_component_of_change] = collect($data)->keyBy('combination');
            }
        }

        $this->projection->references = $this->references;
    }

    public function testTotalInFinalData()
    {
        $this->buildBaseData();

        $this->invokeMethod($this->projection, 'calculateCOCPopulationAtStartOfYear', [$this->year]);

        $total = $this->projection->final_data[$this->year]['population_at_start_of_year']['total'];

        $this->assertEquals($this->getTotalFor('population', $this->year - 1), $total->PREDICT_VALUE);
    }

    /**
     * Done using Hugo's db
     */
    public function testPopulationAtStartOfYear()
    {
        $this->buildBaseData();

        $this->invokeMethod($this->projection, 'calculateCOCPopulationAtStartOfYear', [$this->year]);

        for($i = 0; $i <= $this->num_tests_for_data; $i++) {

            $combination = $this->randCombination();

            $total = $this->projection->final_data[$this->year]['population_at_start_of_year'][$combination];

            $this->assertEquals($this->getValueForCombination('population', $combination, $this->year - 1), $total->PREDICT_VALUE);

        }
    }

    /**
     * Done using Hugo's db
     */
    public function testBirthsByAgeOfMother()
    {
        $this->buildBaseData();

        $this->invokeMethod($this->projection, 'calculateCOCPopulationAtStartOfYear', [$this->year]);

        $this->invokeMethod($this->projection, 'calculateCOCBirthsByAgeOfMother', [$this->year]);

        for($i = 0; $i <= $this->num_tests_for_data; $i++) {

            $combination = $this->combinations[rand(16, count($this->births_by_age_of_mother_combinations) - 1)];

            $combination_younger = $this->invokeMethod($this->projection, 'combinationYounger', [$combination]);

            $calculated_value = $this->projection->final_data[$this->year]['births_by_age_of_mother'][$combination];

            $calculated_value_from_hugos_db = (($this->getValueForCombination('population', $combination, $this->year - 1) +
                $this->getValueForCombination('population', $combination_younger, $this->year - 1)) / 2) *
                $this->getValueForCombination('finalrates_births', $combination) *
                env('BIRTHS_BY_AGE_OF_MOTHER_MULTIPLIER');

            $this->assertEquals($calculated_value_from_hugos_db, $calculated_value->PREDICT_VALUE);

        }
    }

    public function testBirths()
    {
        $this->buildBaseData();

        $births_by_age_of_mother_total =
            $this->invokeMethod(
                $this->projection,
                'updateCollectionWithTotal',
                [$this->projection->references[$this->year]['projection']['births_by_age_of_mother']]
            );

        $births_females_totals = (float)$births_by_age_of_mother_total->get('total')->PREDICT_VALUE *
            env('PROJECTION_BIRTHS_FEMALE_MULTIPLIER') / env('PROJECTION_BIRTHS_DIVIDER');

        $births_males_totals = (float)$births_by_age_of_mother_total->get('total')->PREDICT_VALUE *
            env('PROJECTION_BIRTHS_MALE_MULTIPLIER') / env('PROJECTION_BIRTHS_DIVIDER');

        $this->assertEquals($births_females_totals, $this->final_data['births']['total females']['PREDICT_VALUE']);

        $this->assertEquals($births_males_totals, $this->final_data['births']['total males']['PREDICT_VALUE']);
    }

    public function testDeaths()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCDeaths($this->year);

        $this->checkFinalDataForComponentOfChange('deaths', true);
    }

    public function testInternalIn()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCInternalIn($this->year);

        $this->checkFinalDataForComponentOfChange('internal_in', true);
    }

    public function testInternalOut()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCInternalOut($this->year);

        $this->checkFinalDataForComponentOfChange('internal_out', true);
    }

    public function testInternationalIn()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCInternationalIn($this->year);

        $this->checkFinalDataForComponentOfChange('international_in', true);
    }

    public function testInternationalOut()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCInternationalOut($this->year);

        $this->checkFinalDataForComponentOfChange('international_out', true);
    }

    public function testCrossBorderIn()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCCrossBorderIn($this->year);

        $this->checkFinalDataForComponentOfChange('cross_border_in', true);
    }

    public function testCrossBorderOut()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCCrossBorderOut($this->year);

        $this->checkFinalDataForComponentOfChange('cross_border_out', true);
    }

    public function testPopulationAtEndOfYear()
    {
        $this->buildBaseData();

        $this->projection->calculateCOCPopulationAtEndOfYear($this->year);

        $this->checkFinalDataForComponentOfChange('population_at_end_of_year', true);
    }
    
    //  PRIVATE FUNCTIONS

    private function checkFinalDataForComponentOfChange($component_of_change, $test_age_zero_and_age_ninety = null)
    {
        for ($i = 0; $i <= $this->num_tests_for_data; $i++) {
            $random_combination = $this->randCombination();
            $this->assertEquals(
                $this->final_data[$component_of_change][$random_combination]['PREDICT_VALUE'],
                $this->projection->final_data[$this->year][$component_of_change]->get($random_combination)->PREDICT_VALUE,
                'test('.$this->final_data[$component_of_change][$random_combination]['PREDICT_VALUE'].') does not equal system('.
                $this->projection->final_data[$this->year][$component_of_change]->get($random_combination)->PREDICT_VALUE.') for '.
                '['.$component_of_change.'].['.$random_combination.']'
            );
        }

        if ($test_age_zero_and_age_ninety) {

            $combination = 'males, 00';
            $this->assertEquals(
                $this->final_data[$component_of_change][$combination]['PREDICT_VALUE'],
                $this->projection->final_data[$this->year][$component_of_change]->get($combination)->PREDICT_VALUE,
                'test('.$this->final_data[$component_of_change][$combination]['PREDICT_VALUE'].') does not equal system('.
                $this->projection->final_data[$this->year][$component_of_change]->get($combination)->PREDICT_VALUE.') for '.
                '['.$component_of_change.'].['.$combination.']'
            );

            $combination = 'males, 90';
            $this->assertEquals(
                $this->final_data[$component_of_change][$combination]['PREDICT_VALUE'],
                $this->projection->final_data[$this->year][$component_of_change]->get($combination)->PREDICT_VALUE,
                'test('.$this->final_data[$component_of_change][$combination]['PREDICT_VALUE'].') does not equal system('.
                $this->projection->final_data[$this->year][$component_of_change]->get($combination)->PREDICT_VALUE.') for '.
                '['.$component_of_change.'].['.$combination.']'
            );

        }
    }
    
    private function getTotalFor($table_name, $year = null)
    {
        if ($year == null) { $year = $this->year; }

        $row = DB::table($table_name)->select(DB::raw('SUM(PREDICT_VALUE) AS total'))
            ->where('PREDICT_YEAR', '=', $year)->where('AREA_NAME', 'like', $this->local_authority)->first();

        return $row->total;
    }

    private function getValueForCombination($table_name, $combination, $year = null)
    {
        if ($year == null) { $year = $this->year; }

        $row = DB::table($table_name)->where('PREDICT_YEAR', '=', $year)
            ->where('AREA_NAME', 'like', $this->local_authority)
            ->where('SEX', '=', strtoupper(explode(', ', $combination)[0]))
            ->where('AGE_VAL', '=', explode(', ', $combination)[1])->first();

        return (float)$row->PREDICT_VALUE;
    }

    private function randCombination()
    {
        return $this->combinations[rand(1, count($this->combinations) - 1)];
    }
}
