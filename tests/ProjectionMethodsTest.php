<?php

use App\PopulationProjectionSimplified\Projection;

use Illuminate\Support\Facades\DB;

class ProjectionMethodsTest extends TestCase
{
    private $combinations;
    private $year = 2013;
    private $start_year = 2013;
    private $end_year = 2023;

    public function setUp()
    {
        parent::setUp();

        config(['database.connections.mysql.database' => $this->database]);

        $this->projection = new Projection($this->database, $this->local_authority, $this->start_year, $this->end_year);

        foreach (['females', 'males'] as $sex) {
            foreach (range(0, 90) as $age) {
                $this->combinations[] = $sex . ', ' . str_pad($age, 2, '0', STR_PAD_LEFT);
            }
        }
    }

    public function testAverageFunction()
    {
        try {
            $this->invokeMethod($this->projection, 'average', ['a', 'b']);
        } catch(Exception $e) {

        }
    }

    public function testCheckDatabaseHasDataForStartAndEndYear()
    {
        for ($i = 0; $i < 20; $i++) {

            $this->projection->start_year = rand(2013, 2024);
            $this->projection->end_year = rand(2025, 2037);
            try {
                $this->invokeMethod($this->projection, 'checkDatabaseHasDataForStartAndEndYear', []);
                $this->assertTrue(true);
            } catch (Exception $e) {
                $this->assertTrue(false, 'The start_year(' . $this->projection->start_year .
                    ') and end_year(' . $this->projection->end_year . ') should pass');
            }

            $this->projection->start_year = rand(2013, 2024);
            $this->projection->end_year = rand(2038, 2050);
            try {
                $this->invokeMethod($this->projection, 'checkDatabaseHasDataForStartAndEndYear', []);
                $this->assertTrue(false, 'The start_year(' . $this->projection->start_year .
                    ') and end_year(' . $this->projection->end_year . ') should FAIL');
            } catch (Exception $e) {
                $this->assertTrue(true);
            }

            $this->projection->start_year = rand(2000, 2012);
            $this->projection->end_year = rand(2025, 2037);
            try {
                $this->invokeMethod($this->projection, 'checkDatabaseHasDataForStartAndEndYear', []);
                $this->assertTrue(false, 'The start_year(' . $this->projection->start_year .
                    ') and end_year(' . $this->projection->end_year . ') should FAIL');
            } catch (Exception $e) {
                $this->assertTrue(true);
            }

        }
    }
    
    public function testIfExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike()
    {
        $this->projection->local_authority = 'adur';
        $this->invokeMethod($this->projection, 'ifExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike', ['births']);
        $this->assertEquals($this->projection->local_authority, 'adur', $this->projection->local_authority.' came back');

        $this->projection->local_authority = 'stockton';
        $this->invokeMethod($this->projection, 'ifExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike', ['births']);
        $this->assertEquals($this->projection->local_authority, 'stockton%');

        $this->projection->local_authority = 'bristol';
        $this->invokeMethod($this->projection, 'ifExactMatchForTheLocalAuthorityDoesntExistAddPercentageSignForLike', ['births']);
        $this->assertEquals($this->projection->local_authority, 'bristol%');
    }
    
    public function testCheckDataForComponentOfChangeHasCorrectNumberOfEntries()
    {
        try {
            $this->invokeMethod(
                $this->projection,
                'checkDataForComponentOfChangeHasCorrectNumberOfEntries',
                [$this->year, 'births', [1,2]]
            );
            $this->assertTrue(true);
        } catch(Exception $e) {
            $this->assertTrue(false, '\'Births\' is supposed to have 2 entries');
        }

        try {
            $this->invokeMethod(
                $this->projection,
                'checkDataForComponentOfChangeHasCorrectNumberOfEntries',
                [$this->year, 'births_by_age_of_mother', array_fill(0, 30, '')]
            );
            $this->assertTrue(true);
        } catch(Exception $e) {
            $this->assertTrue(false, '\'Births by Age of Mother\' is supposed to have 30 entries');
        }

        foreach ($this->components_of_change_with_182_entries as $component_of_change) {

            $entries = rand(1, 180);

            try {
                $this->invokeMethod(
                    $this->projection,
                    'checkDataForComponentOfChangeHasCorrectNumberOfEntries',
                    [$this->year, $component_of_change, array_fill(0, $entries, '')]
                );
                $this->assertTrue(false, '\''.title_case(str_replace('_', ' ', $component_of_change)).
                    '\' is NOT supposed to have '.$entries.' entries');
            } catch (Exception $e) {
                $this->assertTrue(true);
            }

        }
    }

    public function testCombinationContainsMales()
    {
        $this->assertEquals(true, $this->invokeMethod($this->projection, 'combinationContainsMales', ['males, 00']));

        $this->assertEquals(true, $this->invokeMethod($this->projection, 'combinationContainsMales', ['males, 12']));

        $this->assertEquals(true, $this->invokeMethod($this->projection, 'combinationContainsMales', ['males, 67']));

        $this->assertEquals(false, $this->invokeMethod($this->projection, 'combinationContainsMales', ['females, 9']));

        $this->assertEquals(false, $this->invokeMethod($this->projection, 'combinationContainsMales', ['females,52']));
    }

    public function testCombinationContainsFemales()
    {
        $this->assertEquals(true, $this->invokeMethod($this->projection, 'combinationContainsFemales', ['females, 00']));

        $this->assertEquals(true, $this->invokeMethod($this->projection, 'combinationContainsFemales', ['females, 12']));

        $this->assertEquals(true, $this->invokeMethod($this->projection, 'combinationContainsFemales', ['females, 67']));

        $this->assertEquals(false, $this->invokeMethod($this->projection, 'combinationContainsFemales', ['males, 9']));

        $this->assertEquals(false, $this->invokeMethod($this->projection, 'combinationContainsFemales', ['males,52']));
    }

    public function testCombinationYounger()
    {
        $this->assertEquals(
            'females, 00',
            $this->invokeMethod($this->projection, 'combinationYounger', ['females, 00'])
        );

        $this->assertEquals(
            'females, 12',
            $this->invokeMethod($this->projection, 'combinationYounger', ['females, 13'])
        );

        $this->assertEquals(
            'females, 88',
            $this->invokeMethod($this->projection, 'combinationYounger', ['females, 89'])
        );

        $this->assertEquals(
            'males, 34',
            $this->invokeMethod($this->projection, 'combinationYounger', ['males, 35'])
        );
    }
}
