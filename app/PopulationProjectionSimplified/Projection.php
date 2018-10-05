<?php

namespace App\PopulationProjectionSimplified;

use Illuminate\Support\Facades\DB;

/**
 * Don't worry. Half of the code (sorry that's a lie) more then half the code is for debugging
 * So please remember if you update any of the equations, update the debugging code as well
 *
 * Class Projection
 * @package App\PopulationProjectionSimplified
 */
class Projection extends ProjectionBase
{
    private $excel;
    private $combinations;
    private $births_by_age_of_mother_range = ['females, 15', 'females, 16', 'females, 17', 'females, 18',
        'females, 19', 'females, 20', 'females, 21', 'females, 22', 'females, 23', 'females, 24', 'females, 25',
        'females, 26', 'females, 27', 'females, 28', 'females, 29', 'females, 30', 'females, 31', 'females, 32',
        'females, 33', 'females, 34', 'females, 35', 'females, 36', 'females, 37', 'females, 38', 'females, 39',
        'females, 40', 'females, 41', 'females, 42', 'females, 43', 'females, 44'];

    public function __construct($database, $local_authority, $start_year, $end_year)
    {
        parent::__construct($database, $local_authority, $start_year, $end_year);

        // only gets used by $this->runProjection();
        $this->excel = new SimpleExcel($database, $local_authority, $start_year, $end_year);

        $this->ifExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike('deaths');

        $this->checkDatabaseHasDataForStartAndEndYear();

        foreach (['females', 'males'] as $sex) {
            foreach (range(0, 90) as $age) {
                $this->combinations[] = $sex . ', ' . str_pad($age, 2, '0', STR_PAD_LEFT);
            }
        }
    }

    /**
     * The MAIN function (NOT USED FOR COMMANDS)
     */
    public function runProjection()
    {

        for ($year = $this->start_year; $year <= $this->end_year; $year++) {

            $this->references[$year] = ['national', 'local', 'rates'];
            $this->final_data[$year] = [];

            // needed for 'Deaths'
            $this->insertReferenceFor($year, 'rates', 'deaths', $this->getDataFor('finalrates_deaths', $year));

            // needed for 'Internal In'
            $this->insertReferenceFor($year, 'rates', 'internal_in', $this->getDataFor('finalrates_internal_in', $year));
            $this->insertReferenceFor($year, 'national', 'births', $this->getDataFor('national_birth', $year));
            $this->insertReferenceFor($year, 'national', 'deaths', $this->getDataFor('national_death', $year));
            $this->insertReferenceFor($year, 'national', 'population_at_start_of_year', $this->getDataFor('national_pop', $year - 1));

            // needed for 'Internal Out'
            $this->insertReferenceFor($year, 'rates', 'internal_out', $this->getDataFor('finalrates_internal_out', $year));

            // needed for 'International In'
            $this->insertReferenceFor($year, 'local', 'international_in', $this->getDataFor('international_in', $year));

            // needed for 'International Out'
            $this->insertReferenceFor($year, 'rates', 'international_out', $this->getDataFor('finalrates_international_out', $year));

            // needed for 'Cross Border In'
            $this->insertReferenceFor($year, 'local', 'cross_border_in', $this->getDataFor('x_border_in', $year));

            // needed for 'Cross Border Out'
            $this->insertReferenceFor($year, 'rates', 'cross_border_out', $this->getDataFor('finalrates_x_border_out', $year));


            $this->calculateCOCPopulationAtStartOfYear($year);

            $this->calculateCOCBirthsByAgeOfMother($year);

            $this->calculateCOCBirths($year);

            $this->calculateCOCDeaths($year);

            $this->calculateCOCInternalIn($year);

            $this->calculateCOCInternalOut($year);

            $this->calculateCOCInternationalIn($year);
            
        }

        $this->excel->excelFileBuildFinalDataIntoExcelData($this->final_data);

        foreach ($this->excel->getExcelData() as $row) {

            $this->excel->excelFileWriteRow('projection', $row);

        }

        if (env('DEBUG_CALCULATIONS') == true) {

            foreach ($this->debug_data as $row) {

                $this->excel->excelFileWriteProjectionDebugRow($row);

            }

        }

        $this->comment('File written to\''.base_path().'/storage/export/'.$this->excel->excel_file_name.'\'');

    }

    /**
     * --  EQUATION  --
     *
     * {% if year == start_year %}
     *     {{ national.population_at_start_of_year._last_year.value }}
     * {% else %}
     *     {{ projection.population_at_end_of_year._last_year.value }}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCPopulationAtStartOfYear($year)
    {
        $data = null;
        $coc = 'population_at_start_of_year';

        // no calculations have been done yet so pull it from local
        if ($year == $this->start_year) {
            // this is for the 'births_by_age_of_mother' equation
            $data = $this->getDataFor('population', $this->start_year - 1);
        } else {
            // otherwise it's just last years population_at_start_of_year
            $data = $this->references[$year-1]['projection']['population_at_end_of_year'];
        }

        $this->insertReferenceFor($year, 'projection', $coc, $data);

        $this->insertFinalDataFor($year, $coc, $data);
    }

    /**
     * --  EQUATION  --
     *
     * {{ average(projection.population_at_start_of_year._start_year._younger, projection.population_at_start_of_year._last_year.value)
     *    * rate.final_rates.births_by_age_of_mother.value * multiplier }}
     *
     * @param $year
     * @throws \Exception
     */
    public function calculateCOCBirthsByAgeOfMother($year)
    {
        $coc = 'births_by_age_of_mother';
        $data = [];
        $debug = [];

        $this->insertReferenceFor($year, 'rates', 'births_by_age_of_mother', $this->getDataFor('finalrates_births', $year));

        foreach ($this->births_by_age_of_mother_range as $combination) {

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    $coc,
                    $combination,
                    'average(projection.population_at_start_of_year._last_year._younger, projection.population_at_start_of_year._last_year.value)' .
                        '* rate.final_rates.births_by_age_of_mother.value * multiplier',
                    'average(' . $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE .
                        ', ' . $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE . ')' .
                        '* ' . $this->references[$year]['rates']['births_by_age_of_mother']->get($combination)->PREDICT_VALUE .
                        ' * ' . env('BIRTHS_BY_AGE_OF_MOTHER_MULTIPLIER'),
                ];
            }

            // don't need to check for age == 0 because the range is 15 > 45

            $value = $this->average(
                    $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE,
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE
                ) * $this->references[$year]['rates']['births_by_age_of_mother']->get($combination)->PREDICT_VALUE
                * env('BIRTHS_BY_AGE_OF_MOTHER_MULTIPLIER');

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', $coc, $data);
        
        $this->insertFinalDataFor($year, $coc, $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if sex == "male" %}
     *     {{ projection.births_by_age_of_mother._total * 100 / 205 }}
     * {% else %}
     *     {{ projection.births_by_age_of_mother._total * 105 / 205 }}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCBirths($year)
    {
        $data = [];
        $debug = [];

        $births_by_age_of_mother_total =
            $this->updateCollectionWithTotal($this->references[$year]['projection']['births_by_age_of_mother']);

        if ($this->enableDebug($year)) {
            $debug[] = [
                $year,
                'births',
                'total females',
                'projection.births_by_age_of_mother._total * 105 / 205',
                $births_by_age_of_mother_total->get('total')->PREDICT_VALUE.' * '.
                    env('PROJECTION_BIRTHS_FEMALE_MULTIPLIER').' / '.env('PROJECTION_BIRTHS_DIVIDER'),
            ];

            $debug[] = [
                $year,
                'births',
                'total males',
                'projection.births_by_age_of_mother._total * 100 / 205',
                $births_by_age_of_mother_total->get('total')->PREDICT_VALUE.' * '.
                    env('PROJECTION_BIRTHS_MALE_MULTIPLIER').' / '.env('PROJECTION_BIRTHS_DIVIDER'),
            ];
        }

        $data[] = (object)[
            'combination' => 'total females',
            'PREDICT_VALUE' => (float)$births_by_age_of_mother_total->get('total')->PREDICT_VALUE *
                env('PROJECTION_BIRTHS_FEMALE_MULTIPLIER') / env('PROJECTION_BIRTHS_DIVIDER')
        ];

        $data[] = (object)[
            'combination' => 'total males',
            'PREDICT_VALUE' => (float)$births_by_age_of_mother_total->get('total')->PREDICT_VALUE *
                env('PROJECTION_BIRTHS_MALE_MULTIPLIER') / env('PROJECTION_BIRTHS_DIVIDER')
        ];

        if ($this->enableDebug($year)) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'births', $data);

        $this->insertFinalDataFor($year, 'births', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if age == 0 %}
     *     {{ projection.births.value * rate.final_rates.deaths.value * multiplier }}
     * {% else %}
     *     {% if age == 90 %}
     *         {{ (projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value) * rate.final_rates.deaths.value * multiplier }}
     *     {% else %}
     *         {{ projection.population_at_start_of_year._younger * rate.final_rates.deaths.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCDeaths($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            $rates_deaths = $this->references[$year]['rates']['deaths']->get($combination)->PREDICT_VALUE;
            $multiplier = env('DEATHS_MULTIPLIER');

            if (strpos($combination, '00') !== false) {
                $debug_formula = 'projection.births.value * rate.final_rates.deaths.value * multiplier';
                if ($this->combinationContainsFemales($combination)) {
                    if ($this->enableDebug($year)) {
                        $debug_equation = $this->references[$year]['projection']['births']->get('total females')->PREDICT_VALUE.' * '.
                            $rates_deaths.' * '.$multiplier;
                    }
                    $value = $this->references[$year]['projection']['births']->get('total females')->PREDICT_VALUE *
                        $rates_deaths * $multiplier;
                } else {
                    if ($this->enableDebug($year)) {
                        $debug_equation = $this->references[$year]['projection']['births']->get('total males')->PREDICT_VALUE.' * '.
                            $rates_deaths.' * '.$multiplier;
                    }
                    $value = $this->references[$year]['projection']['births']->get('total males')->PREDICT_VALUE *
                        $rates_deaths * $multiplier;
                }
            } elseif (strpos($combination, '90') !== false) {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value) '.
                        '* rate.final_rates.deaths.value * multiplier';
                    $debug_equation = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' + '.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE).' * '.
                        $rates_deaths.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE) *
                    $rates_deaths * $multiplier;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'projection.population_at_start_of_year._younger * rate.final_rates.deaths.value * multiplier';
                    $debug_equation = $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' * '.
                        $rates_deaths.' * '.$multiplier;
                }
                $value = $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE *
                    $rates_deaths * $multiplier;
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'deaths',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if ($this->enableDebug($year)) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'deaths', $data);

        $this->insertFinalDataFor($year, 'deaths', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if age == 0 %}
     *     {{ ((national.births.value - national.deaths.value) - (projection.births.value - projection.deaths.value)) * final_rates_rates.internal_in.value * multiplier }}
     * {% else %}
     *     {% if age == 90 %}
     *         {{ ((national.population_at_start_of_year._last_year._younger + national.population_at_start_of_year._last_year.value - national.deaths.value) - (projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - projection.deaths.value)) * final_rates_rates.internal_in.value * multiplier }}
     *     {% else %}
     *         {{ ((national.population_at_start_of_year._last_year._younger - national.deaths.value) - (projection.population_at_start_of_year._younger - projection.deaths.value)) * final_rates_rates.internal_in.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCInternalIn($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            $rates_internal_in = $this->references[$year]['rates']['internal_in']->get($combination)->PREDICT_VALUE;
            $multiplier = env('INTERNAL_IN_MULTIPLIER');

            if (strpos($combination, '00') !== false) {

                $national_births = $this->updateCollectionWithTotal($this->references[$year]['national']['births']);
                $projection_births = $this->updateCollectionWithTotal($this->references[$year]['projection']['births']);

                if ($this->combinationContainsFemales($combination)) {
                    $national_births_value = $national_births->get('total females')->PREDICT_VALUE;
                    $projection_births_value = $projection_births->get('total females')->PREDICT_VALUE;
                } else {
                    $national_births_value = $national_births->get('total males')->PREDICT_VALUE;
                    $projection_births_value = $projection_births->get('total males')->PREDICT_VALUE;
                }
                if ($this->enableDebug($year)) {
                    $debug_formula = '((national.births.value - national.deaths.value) - (projection.births.value - projection.deaths.value))'.
                        ' * final_rates_rates.internal_in.value * multiplier';
                    $debug_equation = '(('.$national_births_value - $this->references[$year]['national']['deaths']->get($combination)->PREDICT_VALUE.') - ('.
                        $projection_births_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.')) * '.
                        $rates_internal_in.' * '.$multiplier;
                }

                $value = (($national_births_value - $this->references[$year]['national']['deaths']->get($combination)->PREDICT_VALUE) -
                    ($projection_births_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE)) *
                    $rates_internal_in * $multiplier;

            } elseif (strpos($combination, '90') !== false) {
                if ($this->enableDebug($year)) {
                    $debug_formula = '((national.population_at_start_of_year._younger + national.population_at_start_of_year.value - '.
                        'national.deaths.value) - (projection.population_at_start_of_year._younger + '.
                        'projection.population_at_start_of_year.value - projection.deaths.value)) * '.
                        'final_rates_rates.internal_in.value * multiplier';
                    $debug_equation = '(('.$this->references[$year]['national']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' + '.
                        $this->references[$year]['national']['population_at_start_of_year']->get($combination)->PREDICT_VALUE.' - '.
                        $this->references[$year]['national']['deaths']->get($combination)->PREDICT_VALUE.') - ('.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' + '.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.')) * '.
                        $rates_internal_in.' * '.$multiplier;
                }
                $value = (($this->references[$year]['national']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['national']['population_at_start_of_year']->get($combination)->PREDICT_VALUE -
                    $this->references[$year]['national']['deaths']->get($combination)->PREDICT_VALUE) -
                    ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE)) *
                    $rates_internal_in * $multiplier;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = '((national.population_at_start_of_year._younger - national.deaths.value) - (projection.population_at_start_of_year._younger - projection.deaths.value)) * final_rates_rates.internal_in.value * multiplier';
                    $debug_equation = '(('.$this->references[$year]['national']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' - '.
                        $this->references[$year]['national']['deaths']->get($combination)->PREDICT_VALUE.') - ('.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.')) * '.
                        $rates_internal_in.' * '.$multiplier;
                }
                $value = (($this->references[$year]['national']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE -
                    $this->references[$year]['national']['deaths']->get($combination)->PREDICT_VALUE) -
                    ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE)) *
                    $rates_internal_in * $multiplier;
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'internal_in',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'internal_in', $data);

        $this->insertFinalDataFor($year, 'internal_in', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if age == 0 %}
     *     {{ (projection.births.value - projection.deaths.value) * rate.final_rates.internal_out.value * multiplier }}
     * {% else %}
     *     {% if age == 90 %}
     *         {{ (projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - projection.deaths.value) * rate.final_rates.internal_out.value * multiplier }}
     *     {% else %}
     *         {{ (projection.population_at_start_of_year._younger - projection.deaths.value) * rate.final_rates.internal_out.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCInternalOut($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            $rates_internal_out = $this->references[$year]['rates']['internal_out']->get($combination)->PREDICT_VALUE;
            $multiplier = env('INTERNAL_OUT_MULTIPLIER');

            if (strpos($combination, '00') !== false) {

                $projection_births = $this->updateCollectionWithTotal($this->references[$year]['projection']['births']);

                if ($this->combinationContainsFemales($combination)) {
                    $projection_births_value = $projection_births->get('total females')->PREDICT_VALUE;
                } else {
                    $projection_births_value = $projection_births->get('total males')->PREDICT_VALUE;
                }

                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.births.value - projection.deaths.value) * rate.final_rates.internal_out.value * multiplier';
                    $debug_equation = '('.$projection_births_value.' - '.$this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_internal_out.' * '.$multiplier;
                }
                $value = ($projection_births_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_internal_out * $multiplier;

            } elseif (strpos($combination, '90') !== false) {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - '.
                        'projection.deaths.value) * rate.final_rates.internal_out.value * multiplier';
                    $debug_equation = '('.$this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' + '.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_internal_out.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_internal_out * $multiplier;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger - projection.deaths.value) * '.
                        'rate.final_rates.internal_out.value * multiplier';
                    $debug_equation = '('.$this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_internal_out.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_internal_out * $multiplier;
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'internal_out',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'internal_out', $data);

        $this->insertFinalDataFor($year, 'internal_out', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if year == start_year %}
     *     {{ local.international_in.value * multiplier }}
     * {% else %}
     *     {% if held_constant %}
     *         {{ local.international_in._last_year.value }}
     *     {% else %}
     *         {{ local.international_in.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCInternationalIn($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            if ($year == $this->start_year) {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'local.international_in.value * multiplier';
                    $debug_equation = $this->references[$year]['local']['international_in']->get($combination)->PREDICT_VALUE.' * '.
                        env('INTERNATIONAL_IN_MULTIPLIER');
                }
                $value = $this->references[$year]['local']['international_in']->get($combination)->PREDICT_VALUE *
                    env('INTERNATIONAL_IN_MULTIPLIER');
            } elseif (env('INTERNATIONAL_IN_HELD_CONSTANT') == true) {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'local.international_in._last_year.value';
                    $debug_equation = $this->references[$this->start_year]['local']['international_in']->get($combination)->PREDICT_VALUE;
                }
                $value = $this->references[$this->start_year]['local']['international_in']->get($combination)->PREDICT_VALUE;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'local.international_in.value * multiplier';
                    $debug_equation = $this->references[$year]['local']['international_in']->get($combination)->PREDICT_VALUE.' * '.
                        env('INTERNATIONAL_IN_MULTIPLIER');
                }
                $value = $this->references[$year]['local']['international_in']->get($combination)->PREDICT_VALUE *
                    env('INTERNATIONAL_IN_MULTIPLIER');
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'international_in',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'international_in', $data);

        $this->insertFinalDataFor($year, 'international_in', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if age == 0 %}
     *     {{ (projection.births.value - projection.deaths.value) * rate.final_rates.international_out.value * multiplier }}
     * {% else %}
     *     {% if age == 90 %}
     *         {{ (projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - projection.deaths.value) * rate.final_rates.international_out.value * multiplier }}
     *     {% else %}
     *         {{ (projection.population_at_start_of_year._younger - projection.deaths.value) * rate.final_rates.international_out.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCInternationalOut($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            $rates_international_out = $this->references[$year]['rates']['international_out']->get($combination)->PREDICT_VALUE;
            $multiplier = env('INTERNATIONAL_OUT_MULTIPLIER');

            if (strpos($combination, '00') !== false) {

                $projection_births = $this->updateCollectionWithTotal($this->references[$year]['projection']['births']);

                if ($this->combinationContainsFemales($combination)) {
                    $projection_births_value = $projection_births->get('total females')->PREDICT_VALUE;
                } else {
                    $projection_births_value = $projection_births->get('total males')->PREDICT_VALUE;
                }

                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.births.value - projection.deaths.value) * rate.final_rates.international_out.value * multiplier';
                    $debug_equation = '('.$projection_births_value.' - '.$this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_international_out.' * '.$multiplier;
                }
                $value = ($projection_births_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_international_out * $multiplier;

            } elseif (strpos($combination, '90') !== false) {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - '.
                        'projection.deaths.value) * rate.final_rates.international_out.value * multiplier';
                    $debug_equation = '('.$this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' + '.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_international_out.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_international_out * $multiplier;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger - projection.deaths.value) * rate.final_rates.international_out.value * multiplier';
                    $debug_equation = '('.$this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' - '.
                            $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_international_out.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_international_out * $multiplier;
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'international_out',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'international_out', $data);

        $this->insertFinalDataFor($year, 'international_out', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if year == start_year %}
     *     {{ local.cross_boarder_in.value * multiplier }}
     * {% else %}
     *     {% if held_constant %}
     *         {{ local.cross_boarder_in._start_year.value }}
     *     {% else %}
     *         {{ local.cross_boarder_in.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCCrossBorderIn($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            if ($year == $this->start_year) {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'local.cross_boarder_in.value * multiplier';
                    $debug_equation = $this->references[$year]['local']['cross_border_in']->get($combination)->PREDICT_VALUE.' * '.
                        env('CROSS_BORDER_IN_MULTIPLIER');
                }
                $value = $this->references[$year]['local']['cross_border_in']->get($combination)->PREDICT_VALUE *
                    env('CROSS_BORDER_IN_MULTIPLIER');
            } elseif (env('CROSS_BORDER_IN_HELD_CONSTANT')) {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'local.cross_boarder_in.value';
                    $debug_equation = $this->references[$this->start_year]['local']['cross_border_in']->get($combination)->PREDICT_VALUE;
                }
                $value = $this->references[$this->start_year]['local']['cross_border_in']->get($combination)->PREDICT_VALUE;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'local.cross_boarder_in.value * multiplier';
                    $debug_equation = $this->references[$year]['local']['cross_border_in']->get($combination)->PREDICT_VALUE.' * '.
                        env('CROSS_BORDER_IN_MULTIPLIER');
                }
                $value = $this->references[$year]['local']['cross_border_in']->get($combination)->PREDICT_VALUE *
                    env('CROSS_BORDER_IN_MULTIPLIER');
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'cross_border_in',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'cross_border_in', $data);

        $this->insertFinalDataFor($year, 'cross_border_in', $data);
    }

    /**
     * --  EQUATION  --
     *
     * {% if age == 0 %}
     *     {{ (projection.births.value - projection.deaths.value) * rate.final_rates.cross_border_out.value * multiplier }}
     * {% else %}
     *     {% if age == 90 %}
     *         {{ (projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - projection.deaths.value) * rate.final_rates.cross_border_out * multiplier }}
     *     {% else %}
     *         {{ (projection.population_at_start_of_year._younger - projection.deaths.value) * rate.final_rates.cross_border_out.value * multiplier }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCCrossBorderOut($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            $rates_cross_border_out = $this->references[$year]['rates']['cross_border_out']->get($combination)->PREDICT_VALUE;
            $multiplier = env('CROSS_BORDER_OUT_MULTIPLIER');

            if (strpos($combination, '00') !== false) {

                $projection_births = $this->updateCollectionWithTotal($this->references[$year]['projection']['births']);

                if ($this->combinationContainsFemales($combination)) {
                    $projection_births_value = $projection_births->get('total females')->PREDICT_VALUE;
                } else {
                    $projection_births_value = $projection_births->get('total males')->PREDICT_VALUE;
                }

                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.births.value - projection.deaths.value) * rate.final_rates.cross_border_out.value * multiplier';
                    $debug_equation = '('.$projection_births_value.' - '.$this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_cross_border_out.' * '.$multiplier;
                }
                $value = ($projection_births_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_cross_border_out * $multiplier;

            } elseif (strpos($combination, '90') !== false) {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - '.
                        'projection.deaths.value) * rate.final_rates.cross_border_out * multiplier';
                    $debug_equation = '('.$this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' + '.
                        $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_cross_border_out.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_cross_border_out * $multiplier;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = '(projection.population_at_start_of_year._younger - projection.deaths.value) * '.
                        'rate.final_rates.cross_border_out.value * multiplier';
                    $debug_equation = '('.$this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE.' - '.
                        $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.') * '.
                        $rates_cross_border_out.' * '.$multiplier;
                }
                $value = ($this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE -
                    $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE) *
                    $rates_cross_border_out * $multiplier;
            }

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'cross_border_out',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'cross_border_out', $data);

        $this->insertFinalDataFor($year, 'cross_border_out', $data);
    }

    /**
     *  --  EQUATION  --
     *
     * {% if age == 0 %}
     *     {{ projection.births.value - projection.deaths.value + projection.internal_in.value - projection.internal_out.value + projection.international_in.value - projection.international_out.value + projection.cross_border_in.value - projection.cross_border_out.value }}
     * {% else %}
     *     {% if age == 90 %}
     *         {{ projection.population_at_start_of_year._younger + projection.population_at_start_of_year.value - projection.deaths.value + projection.internal_in.value - projection.internal_out.value + projection.international_in.value - projection.international_out.value + projection.cross_border_in.value - projection.cross_border_out.value }}
     *     {% else %}
     *         {{ projection.population_at_start_of_year._younger - projection.deaths.value + projection.internal_in.value - projection.internal_out.value + projection.international_in.value - projection.international_out.value + projection.cross_border_in.value - projection.cross_border_out.value }}
     *     {% endif %}
     * {% endif %}
     *
     * @param $year
     */
    public function calculateCOCPopulationAtEndOfYear($year)
    {
        $data = [];
        $debug = [];
        $debug_formula = '';
        $debug_equation = '';

        foreach ($this->combinations as $combination) {

            if (strpos($combination, '00') !== false) {

                $projection_births = $this->updateCollectionWithTotal($this->references[$year]['projection']['births']);

                if ($this->combinationContainsFemales($combination)) {
                    $projection_births_value = $projection_births->get('total females')->PREDICT_VALUE;
                } else {
                    $projection_births_value = $projection_births->get('total males')->PREDICT_VALUE;
                }

                if ($this->enableDebug($year)) {
                    $debug_formula = 'projection.births.value - projection.deaths.value + projection.internal_in.value - '.
                        'projection.internal_out.value + projection.international_in.value - projection.international_out.value + '.
                        'projection.cross_border_in.value - projection.cross_border_out.value';
                }

                $beginning_value = $projection_births_value;

            } elseif (strpos($combination, '90') !== false) {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'projection.population_at_start_of_year._younger.value + projection.population_at_start_of_year.value - '.
                        'projection.deaths.value + projection.internal_in.value - projection.internal_out.value + '.
                        'projection.international_in.value - projection.international_out.value + '.
                        'projection.cross_border_in.value - projection.cross_border_out.value';
                }
                $beginning_value = $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE +
                    $this->references[$year]['projection']['population_at_start_of_year']->get($combination)->PREDICT_VALUE;
            } else {
                if ($this->enableDebug($year)) {
                    $debug_formula = 'projection.population_at_start_of_year._younger - projection.deaths.value + '.
                        'projection.internal_in.value - projection.internal_out.value + projection.international_in.value - '.
                        'projection.international_out.value + projection.cross_border_in.value - projection.cross_border_out.value';
                }
                $beginning_value = $this->references[$year]['projection']['population_at_start_of_year']->get($this->combinationYounger($combination))->PREDICT_VALUE;
            }
            if ($this->enableDebug($year)) {
                $debug_equation = $beginning_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE.' + '.
                    $this->references[$year]['projection']['internal_in']->get($combination)->PREDICT_VALUE.' - '.
                    $this->references[$year]['projection']['internal_out']->get($combination)->PREDICT_VALUE.' + '.
                    $this->references[$year]['projection']['international_in']->get($combination)->PREDICT_VALUE.' - '.
                    $this->references[$year]['projection']['international_out']->get($combination)->PREDICT_VALUE.' + '.
                    $this->references[$year]['projection']['cross_border_in']->get($combination)->PREDICT_VALUE.' - '.
                    $this->references[$year]['projection']['cross_border_out']->get($combination)->PREDICT_VALUE;
            }
            $value = $beginning_value - $this->references[$year]['projection']['deaths']->get($combination)->PREDICT_VALUE +
                $this->references[$year]['projection']['internal_in']->get($combination)->PREDICT_VALUE -
                $this->references[$year]['projection']['internal_out']->get($combination)->PREDICT_VALUE +
                $this->references[$year]['projection']['international_in']->get($combination)->PREDICT_VALUE -
                $this->references[$year]['projection']['international_out']->get($combination)->PREDICT_VALUE +
                $this->references[$year]['projection']['cross_border_in']->get($combination)->PREDICT_VALUE -
                $this->references[$year]['projection']['cross_border_out']->get($combination)->PREDICT_VALUE;

            if ($this->enableDebug($year)) {
                $debug[] = [
                    $year,
                    'population_at_end_of_year',
                    $combination,
                    $debug_formula,
                    $debug_equation
                ];
            }

            $data[] = (object)[
                'combination' => $combination,
                'PREDICT_VALUE' => $value
            ];

        }

        if (env('DEBUG_CALCULATIONS') == true) { $this->insertDebugData($debug); }

        $this->insertReferenceFor($year, 'projection', 'population_at_end_of_year', $data);

        $this->insertFinalDataFor($year, 'population_at_end_of_year', $data);
    }

    /**
     * (Ronseal)
     *
     * @param $val1
     * @param $val2
     * @return float
     * @throws \Exception
     */
    private function average($val1, $val2)
    {
        try {
            return ((float)$val1 + (float)$val2) / 2;
        } catch(\Exception $e) {
            throw new \Exception('can not average \''.$val1.'\' and \''.$val2.'\'');
        }
    }
}
