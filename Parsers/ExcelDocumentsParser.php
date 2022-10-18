<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\ExcelReadFilter;
use common\helpers\ExperiorExcel;
use common\models\CommissionsImport\DealContainer;

use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;

/**
 * Class ExcelDocumentsParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
abstract class ExcelDocumentsParser extends DocumentParser
{
	/**
	 * @param DealContainer $deal
	 *
	 * @return mixed
	 */
	abstract protected function setDealDefaults(DealContainer $deal);

	/**
	 * @var array
	 */
	protected $rows = [];

	/**
	 * @var int
	 */
	protected $titlesIndex = 1;

	/**
	 * @var array
	 */
	protected $columnsRange = ['A', 'Z'];

	/**
	 * @var int[]
	 */
	protected $rowsRange = [0, 20000];

	/**
	 * @var array
	 */
	protected $formattedColumns = [];

	/**
	 * @var int
	 */
	protected $sheetIndex = 0;

	/**
	 * @var array
	 */
	protected $sheets = [];

	/**
	 * @var array
	 */
	protected $additionalFields = [];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [];

	/**
	 * @var array
	 */
	protected $matchColumns = [];

	/**
	 * @var bool
	 */
	protected $needSortMatchColumns = false;

	/**
	 * Checks file if all required columns exist
	 * Uses $this->requiredFields to check if fields exists.
	 * In case normalized titles not the same as the original title - use array where keys the same as the original title.
	 * If the key is a string - it will be used as the field name in error text.
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function checkFileFormat(): bool
	{
		if ($this->readFile(true) && isset($this->rows[$this->titlesIndex])) {

			$this->titles = $this->normalizeFileTitles($this->rows[$this->titlesIndex]);

			$i = 0;
			foreach ($this->requiredFields as $key => $field) {
				if (!in_array($field, $this->titles)) {
					$i++;
					$fieldName = is_string($key) ? $key : $field;
					$this->addError(
						'temp_file' . $i, 'Required column "' . $fieldName . '" missed in file.');
				}
			}

			return !$this->hasErrors();
		}

		$this->addError('temp_file', 'Please check if your file contains all necessary columns,' .
			' has the right format and doesn`t protect with a password!');

		return false;
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function parseFile(): bool
	{
		$rows = $this->rows;
		$this->titles = $this->normalizeFileTitles($rows[$this->titlesIndex]);

		$this->matchColumns($this->titles);
		$this->setFormattedFields($this->titles);
		$this->readFile();

		if ($this->divideToFilesByDates) {
			$this->delete();
			$this->parseDividedFiles();
		} else {
			$this->parseAndCreateDealsContainers();
			$this->combineDeals();

			if ($this->hasErrors()) {
				return false;
			}
		}

		return true;
	}

	public function parseDividedFiles(): bool
	{
		$rowsByDates = $this->getDividedRowsByDates();

		foreach ($rowsByDates as $date => $rows) {
			$parser = new static();
			$additionalInfo = [];
			if (!static::$activeImportId) {
				$additionalInfo['isFirstPart'] = true;
			}
			$parser->setFileInstance($this->getFile(), $this->file_name, $this->file_hash);
			$additionalInfo['Date'] = $date;
			$parser->file_additional_info = json_encode($additionalInfo);

			$titles = array_slice($this->rows, 0, 2);
			$parser->rows = array_merge($titles, $rows);
			$parser->matchColumns($this->titles);

			$parser->parseAndCreateDealsContainers();
			$parser->combineDeals();

			if ($parser->hasErrors()) {
				return false;
			}

			$parser->saveDeals();
		}
		return true;
	}

	/**
	 * Gets deals from parsed file and checks values.
	 */
	protected function parseAndCreateDealsContainers()
	{
		$rows = array_slice($this->rows, $this->titlesIndex);
		foreach ($rows as $row) {
			$deal = new DealContainer();
			$this->setDealDefaults($deal);
			$deal->original = [$row];

			$this->matchRowColumns($deal, $row);

			if (!$this->checkDeal($deal)) {
				continue;
			}

			$this->deals[] = $deal;
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param array         $row
	 */
	protected function matchRowColumns(DealContainer $deal, array $row)
	{
		foreach ($this->matchColumns as $key => $column) {
			if (isset($column['method']) && $column['method']) {
				$this->{$column['method']}($deal, $row[$key]);
			} else {
				$deal->{$column['match']} = $row[$key];
			}
		}
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	protected function checkDeal(DealContainer $deal): bool
	{
		foreach ($this->matchColumns as $key => $column) {
			if ($deal->{$column['match']} === null) {

				return false;
			}
		}

		return true;
	}

	/**
	 * @param bool $titles
	 *
	 * @return bool
	 * @throws \Exception
	 */
	protected function readFile($titles = false): bool
	{
		$readFilter = new ExcelReadFilter();
		$readFilter->setRowsRange($this->rowsRange[0], $this->rowsRange[1]);

		if ($titles) {
			$readFilter->setRowsRange($this->titlesIndex, $this->titlesIndex);
		}
		$readFilter->setColumnsRange($this->columnsRange[0], $this->columnsRange[1]);

		try {
			$this->rows = ExperiorExcel::import(
				$this->file->temp_file->tempName ?? $this->file->getFullPath(), [
					'setFirstRecordAsKeys' => false,
					'readFilter' => $readFilter,
					'formattedColumns' => $this->formattedColumns,
					'getOnlySheetIndex' => $this->sheetIndex,
			]);
		} catch (\Exception $e) {
			$this->addError('temp_file0', $e->getMessage());

			return false;
		}

		// TODO: rewrite here and in other methods to reed all sheets in file.
		$this->sheets = [$this->rows];
		// remove sheets layer from array if exists
		if (isset($this->rows[$this->sheetIndex]) && is_array(current($this->rows[$this->sheetIndex]))) {
			$this->sheets = $this->rows;
			$this->rows = $this->rows[$this->sheetIndex];
		}

		foreach ($this->rows as $rowIndex => $row) {
			foreach ($row as $columnIndex => $column) {
				if (!$readFilter->readCell($columnIndex, $rowIndex)) {
					unset($this->rows[$rowIndex][$columnIndex]);
				}
			}
		}

		return true;
	}

	/**
	 * Matches columns with rules
	 * @param $titles
	 * @return $this
	 */
	protected function matchColumns($titles): self
	{
		$matchedColumns = [];

		foreach ($this->requiredFields as $field) {
			$key = array_search($field, $titles);
			if (isset($this->rules[$field]) && $this->rules[$field]['match']) {
				$matchedColumns[$key] = $this->rules[$field];
			}
		}

		foreach ($this->additionalFields as $field) {
			$key = array_search($field, $titles);
			if (isset($this->rules[$field]) && $this->rules[$field]['match']) {
				$matchedColumns[$key] = $this->rules[$field];
			}
		}

		$this->matchColumns = $this->getSortedMatchColumns($matchedColumns);

		return $this;
	}

	/**
	 * @param $headers
	 * @return array
	 */
	protected function normalizeFileTitles($headers): array
	{
		return array_map(function ($string) {
			return Inflector::camel2words(preg_replace('/\s+/', '', $string));
		}, $headers);
	}

	/**
	 * Setts columns need to be formatted
	 *
	 * @param array $titles
	 */
	protected function setFormattedFields($titles)
	{
		foreach ($this->rules as $field => $rule) {
			if ($rule['money'] ?? false) {
				$this->formattedColumns[] = array_search($field, $titles);
			}
		}
	}

	/**
	 * @param array $matchedColumns
	 *
	 * @return array
	 */
	protected function getSortedMatchColumns(array $matchedColumns): array
	{
		if ($this->needSortMatchColumns) {
			ArrayHelper::multisort($matchedColumns, 'order');
		}

		return $matchedColumns;
	}

}