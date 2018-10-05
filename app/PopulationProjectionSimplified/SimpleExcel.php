<?php

namespace App\PopulationProjectionSimplified;

use Illuminate\Support\Facades\Log;
use Excel;

class SimpleExcel
{
    private $start_year;
    private $end_year;
    private $database;
    private $local_authority;

    private $excel_data;
    public $excel_file_name;

    private $excel_sheet_projection_debug;
    private $excel_sheet_household_debug;

    public $household_data;
    
    private $variables;
    private $excel;
    private $excel_sheet_projection;
    private $excel_sheet_household;
    private $excel_sheet_dwelling;
    private $excel_sheet_name_for_projection = 'Projection';
    private $excel_sheet_name_for_variables  = 'Variables';
    private $excel_sheet_name_for_household  = 'Head of Household';
    private $excel_sheet_name_for_dwelling  = 'Dwellings';
    
    public function __construct($database, $local_authority, $start_year, $end_year)
    {
        $this->database = $database;
        $this->local_authority = $local_authority;
        $this->start_year = $start_year;
        $this->end_year = $end_year;

        $this->excel_file_name =
            date('YmdHi') . '_' .
            $local_authority . '_' .
            str_replace('_', '', $database) . '_' .
            'projection_for_' .
            $start_year . '_' .
            'to_' .
            $end_year;

        $this->variables = [
            'INTERNATIONAL_IN_HELD_CONSTANT',
            'CROSS_BORDER_IN_HELD_CONSTANT',
            'BIRTHS_BY_AGE_OF_MOTHER_MULTIPLIER',
            'DEATHS_MULTIPLIER',
            'INTERNAL_IN_MULTIPLIER',
            'INTERNAL_OUT_MULTIPLIER',
            'INTERNATIONAL_IN_MULTIPLIER',
            'INTERNATIONAL_OUT_MULTIPLIER',
            'CROSS_BORDER_IN_MULTIPLIER',
            'CROSS_BORDER_OUT_MULTIPLIER'
        ];
    }

    /**
     * NOT USED BY COMMAND
     */
    /*protected function writeOutFile()
    {
        $this->excelFileBuildFinalDataIntoExcelData();

        $this->excelFileCreate();
        
        foreach ($this->excel_data as $row) {

            $this->excelFileWriteRow($row);

        }

        $this->excelFileWriteOut();
    }*/

    /**
     * (Ronseal)
     */
    public function excelFileCreate()
    {
        $this->excel = Excel::create($this->excel_file_name, function($excel) {

            $excel->sheet($this->excel_sheet_name_for_projection, function($sheet) {

                $col_titles = array_merge(
                    ['Component of Change', 'Sub Division'],
                    range($this->start_year, $this->end_year)
                );

                $sheet->row(1, $col_titles);

                $sheet->row(2, ['']);

                $this->excel_sheet_projection = $sheet;

            });

            $excel->sheet($this->excel_sheet_name_for_household, function($sheet) {

                $col_titles = array_merge(
                    ['Calculation', 'Combination'],
                    range($this->start_year, $this->end_year)
                );

                $sheet->row(1, $col_titles);

                $this->excel_sheet_household = $sheet;

            });

            $excel->sheet($this->excel_sheet_name_for_dwelling, function($sheet) {

                $col_titles = array_merge(
                    ['Calculation', 'Combination'],
                    range($this->start_year, $this->end_year)
                );

                $sheet->row(1, $col_titles);

                $this->excel_sheet_dwelling = $sheet;

            });

            if (env('DEBUG_CALCULATIONS') == true) {

                $excel->sheet('DEBUG - Projection', function ($sheet) {

                    $sheet->row(1, ['Year', 'Component of Change', 'Sub Division', 'Formula', 'Values']);

                    $this->excel_sheet_projection_debug = $sheet;

                });

                $excel->sheet('DEBUG - Household', function ($sheet) {

                    $sheet->row(1, ['Year', 'Stage', 'Sub Division', 'Formula', 'Values', 'Final']);

                    $this->excel_sheet_household_debug = $sheet;

                });

            }

            $excel->sheet($this->excel_sheet_name_for_variables, function($sheet) {

                $sheet->row(1, ['The following values were used in creating the Projection:']);

                $sheet->row(2, ['']);

                $sheet->row(3, ['Created at:', date('H:i d/m/Y')]);

                $sheet->row(4, ['']);

                $sheet->row(5, ['Local Authority:', str_replace('%', '', title_case($this->local_authority))]);

                $sheet->row(6, ['Database:', strtoupper($this->database)]);

                $sheet->row(7, ['Start Year:', $this->start_year]);

                $sheet->row(8, ['End Year:', $this->end_year]);

                $sheet->row(9, ['']);

                $i = 10;

                foreach ($this->variables as $variable) {
                    $sheet->row($i++, [$variable, env($variable)]);
                }

            });

        });
    }

    /**
     * (Ronseal)
     *
     * @param string $sheet
     * @param $row_data
     */
    public function excelFileWriteRow($sheet, $row_data)
    {
        if ($sheet == 'projection') {
            $this->excel_sheet_projection->appendRow($row_data);
        }
        if ($sheet == 'household') {
            $this->excel_sheet_household->appendRow($row_data);
        }
        if ($sheet == 'dwelling') {
            $this->excel_sheet_dwelling->appendRow($row_data);
        }
    }

    /**
     * (Ronseal)
     *
     * @param $row_data
     */
    public function excelFileWriteProjectionDebugRow($row_data)
    {
        $this->excel_sheet_projection_debug->appendRow($row_data);
    }

    /**
     * (Ronseal)
     *
     * @param $row_data
     */
    public function excelFileWriteHouseholdDebugRow($row_data)
    {
        $this->excel_sheet_household_debug->appendRow($row_data);
    }

    /**
     * (Ronseal)
     *
     * @param $final_data
     */
    public function excelFileBuildFinalDataIntoExcelData($final_data)
    {
        try {

            foreach ($final_data as $year => $year_data) {

                $i = 0;

                foreach ($year_data as $component_of_change => $component_of_change_data) {

                    $this->excel_data[$i++] = [''];

                    foreach ($component_of_change_data as $combination_data) {

                        if ($year == $this->start_year) {

                            $this->excel_data[$i++] = [
                                $component_of_change,
                                $combination_data->combination,
                                $combination_data->PREDICT_VALUE
                            ];

                        } else {

                            $this->excel_data[$i++][] = $combination_data->PREDICT_VALUE;

                        }

                    }

                }

            }

        } catch(\Exception $e) {
            $this->error('Well this is embarrassing. the array with the data was not in the right format');
            Log::error($e);
            exit;
        }
    }

    /**
     * (Ronseal)
     */
    public function excelFileWriteOut()
    {
        $this->excel->store('xls');
    }

    public function getExcelData()
    {
        return $this->excel_data;
    }

    public function emptyExcelData()
    {
        $this->excel_data = [];
    }
}
