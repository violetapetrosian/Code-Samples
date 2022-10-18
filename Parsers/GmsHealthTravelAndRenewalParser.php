<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\InsuranceAndRenewalsCombinedResolver;
use common\models\Renewals;

use frontend\models\Company;
use frontend\models\Deal;
use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;

/**
 * Class GmsHealthTravelAndRenewalParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class GmsHealthTravelAndRenewalParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::GMS_COMPANY_ID];
	const DEAL_TYPES = ['Health/Dental', 'Travel'];
	const EXPERIOR_BROKER_ID = '669241';

	protected $titlesIndex = 5;
	protected $columnsRange = ['A', 'O'];

	protected $resolverClass = InsuranceAndRenewalsCombinedResolver::class;

	protected $commissionTypes = [
		'book of business' => Renewals::class,
		'new' => InsuranceDeals::class,
		'refund' => InsuranceDeals::class,
		'reversal' => InsuranceDeals::class,
		'renewal' => Renewals::class,
	];

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Policy Number',
		'Insured\'s Name',
		'Commission Type',
		'Commission($)',
		'Premium($)',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Policy Number' => [
			'match' => 'policy_number',
			'method' => 'parsePolicy',
			'brokenMethod' => 'parseBrokenPolicy',
		],
		'Insured\'s Name' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
			'brokenMethod' => 'parseBrokenClientName',
		],
		'Commission Type' => [
			'match' => 'parsed_deal_class',
			'method' => 'parseCommissionType',
			'brokenMethod' => 'parseBrokenCommissionType',
		],
		'Commission($)' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'brokenMethod' => 'parseBrokenCommission',
			'money' => true,
		],
		'Premium($)' => [
			'match' => 'premium',
			'method' => 'parseCommission',
			'brokenMethod' => 'parseBrokenCommission',
			'money' => true,
		],
	];

	/**
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [InsuranceDeals::class, Renewals::class];
	}

	/**
	 * @return Company[]
	 */
	public function getCompanies (): array
	{
		if (!$this->companies) {
			$this->companies = Company::find()->where(['id' => static::COMPANIES_IDS])
				->indexBy(Company::primaryKey())->all();
		}

		return $this->companies;
	}

	/**
	 * @inheritDoc
	 */
	public function additionalQuerySettings(DealQuery $query): DealQuery
	{
		if ($query->modelClass === InsuranceDeals::class) {
			$query = parent::additionalQuerySettings($query);

			return $query->andWhere(['type' => $this::DEAL_TYPES]);
		}

		$query->addSelect([new \yii\db\Expression("'" . Deal::TYPE_RENEWALS . "' as type")])
			->addSelect(['gross_amount as amount']);

		return $query;
	}

	/**
	 * @inheritDoc
	 */
	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = InsuranceDeals::class;
	}

	/**
	 * Gets deals from parsed file and checks values.
	 */
	protected function parseAndCreateDealsContainers()
	{
		$advisorInfo = ['name' => '', 'fsCode' => ''];
		foreach ($this->rows as $row) {
			if (!$advisorInfo['name']) {
				$prevRow = $prevRow ?? [];
				$this->parseAdvisor($row, $prevRow, $advisorInfo);
			}
			$prevRow = $row;

			if (strpos($row['A'], 'Total:') !== false) {
				$advisorInfo['name'] = '';
				$advisorInfo['fsCode'] = '';

				continue;
			}
			if ($this->countDealsInRow($row) > 1) {
				try {
					$this->parseBrokenRow($row, $advisorInfo);
				} catch (\Exception $e) {

					return;
				}

				continue;
			}
			$this->parseRow($row, $advisorInfo);
		}
		$this->appendTitlesWithAdvisor();
	}

	/**
	 * @param $row
	 * @param $prevRow
	 * @param $advisorInfo
	 */
	protected function parseAdvisor($row, $prevRow, &$advisorInfo) {
		if (strpos($row['A'], 'BROKER ID') !== false) {
			// Skip records if advisor is Experior.
			if (strpos($row['A'], static::EXPERIOR_BROKER_ID) !== false) {

				$advisorInfo['name'] = 'Experior';
				$advisorInfo['fsCode'] = null;

				return;
			}

			$advisorInfo['name'] = $prevRow['A'] ?? '';
			foreach ($prevRow as $value) {
				if ($value && $value !== $advisorInfo['name'] && preg_match('/[0-9]/', $value)) {
					$advisorInfo['fsCode'] = preg_replace('/[^0-9]/', '', $value);
				}
			}
		} elseif (!$advisorInfo['name']) {
			foreach ($row as $column) {
				if (strpos($column, 'BROKER ID') !== false && !empty($row['A'])) {
					$advisor = str_replace('Experior Financial Group Inc. Re:', '', $row['A']);
					preg_match('/[A-Za-z ]{1,}/', $advisor, $advisorName);
					preg_match('/[0-9]{4,6}/', $advisor, $advisorCode);
					$advisorInfo['name'] = $advisorName[0] ?? '';
					$advisorInfo['fsCode'] = $advisorCode[0] ?? '';
				}
			}
		}
	}

	protected function appendTitlesWithAdvisor()
	{
		$lastKey = array_key_last($this->titles);
		$lastKey++;
		$this->titles[$lastKey] = 'Advisor Name';
		$lastKey++;
		$this->titles[$lastKey] = 'Advisor FS code';
		$this->save(false);
	}

	/**
	 * @param $row
	 *
	 * @return int
	 */
	protected function countDealsInRow($row): int
	{
		foreach ($this->matchColumns as $key => $matchColumn) {
			if ($matchColumn['match'] == 'policy_number') {
				preg_match_all('/([A-Za-z0-9]{7,10})|([A-Za-z0-9]{2}\s{1}[A-Za-z0-9]{4}\s{1}[A-Za-z0-9]{4})/',
					$row[$key], $policies
				);

				return count($policies[0]);
			}
		}

		return 0;
	}

	/**
	 * Detects if few deals placed in one row.
	 *
	 * @param array  $row
	 * @param array $advisorInfo
	 */
	protected function parseRow(array $row, array $advisorInfo)
	{
		$deal = new DealContainer();
		$this->setDealDefaults($deal);
		$original = $row;
		$lastKey = array_key_last($this->titles);
		$lastKey++;
		$original[$lastKey] = $advisorInfo['name'];
		$lastKey++;
		$original[$lastKey] = $advisorInfo['fsCode'];
		$deal->original = [$original];
		$deal->advisor_name = $this->parseAdvisorName($advisorInfo['name']);
		$deal->additional_data['fsCode'] = $advisorInfo['fsCode'];
		if ($advisorInfo['name'] === 'Experior') {
			$deal->additional_data['solution'] = DocumentParserInterface::SOLUTION_UNMATCH;
		}
		if (!$deal->advisor_name) {
			$deal->forceToUnmatch = true;
			$deal->toUnmatchWithoutAdvisorName = true;
		}

		foreach ($this->matchColumns as $key => $column) {
			$deal->{$column['match']} = $row[$key];

			if ($column['method']) {
				$deal->{$column['match']} = $this->{$column['method']}($row[$key]);
			}
		}
		if (!$this->checkDeal($deal)) {

			return;
		}

		$this->deals[] = $deal;
	}

	/**
	 * Parses rows which contains more then one deal in row.
	 *
	 * @param array  $row
	 * @param array $advisorInfo
	 *
	 * @throws \Exception
	 */
	protected function parseBrokenRow(array $row, array $advisorInfo)
	{
		$countDeals = $this->countDealsInRow($row);
		$rows = [];

		foreach ($this->matchColumns as $key => $column) {
			$this->{$column['brokenMethod']}($key, $row, $countDeals, $rows);
		}
		foreach ($rows as $row) {
			$this->parseRow($row, $advisorInfo);
		}
	}

	/**
	 * @param string $name
	 *
	 * @return string|null
	 */
	protected function parseClientName($name)
	{
		return $this->getParseSimpleName($name);
	}

	/**
	 * @param $name
	 *
	 * @return null|string
	 */
	protected function parseAdvisorName($name)
	{
		if ($name) {
			$name = str_replace('Experior Financial Group Inc. Re:', '', $name);

			return trim(preg_replace('/[^`\'\- A-Za-z ]/', '', $name));
		}

		return null;
	}

	/**
	 * @param $commission
	 *
	 * @return float|null
	 */
	protected function parseCommission($commission)
	{
		return preg_match('/[0-9]/', $commission) ? Money::toFloat($commission) : null;
	}

	/**
	 * @param $commissionType
	 *
	 * @return string|null
	 */
	protected function parseCommissionType($commissionType)
	{
		$commissionType = strtolower(trim($commissionType));
		$commissionType = preg_replace('/[^[:print:]]/', '', $commissionType);

		return $this->commissionTypes[$commissionType] ?? null;
	}

	/**
	 * @param $policy
	 *
	 * @return string
	 */
	public function parsePolicy($policy)
	{
		return trim(preg_replace('/[^[:print:]]/', '', $policy));
	}

	/**
	 * @param string $rowKey
	 * @param array  $row
	 * @param int    $countDeals
	 * @param array  $rows
	 */
	protected function parseBrokenPolicy(string $rowKey, array $row, int $countDeals, array &$rows)
	{
		preg_match_all('/([A-Za-z0-9]{7,10})|([A-Za-z0-9]{2}\s{1}[A-Za-z0-9]{4}\s{1}[A-Za-z0-9]{4})/',
			$row[$rowKey], $policies
		);

		foreach ($policies[0] as $key => $policy) {
			$rows[$key][$rowKey] = $policy;
		}
	}

	/**
	 * @param string $rowKey
	 * @param array  $row
	 * @param int    $countDeals
	 * @param array  $rows
	 *
	 * @throws \Exception
	 */
	protected function parseBrokenClientName(string $rowKey, array $row, int $countDeals, array &$rows)
	{
		preg_match_all('/([A-Za-z-.]+,\s[A-Za-z-.]+)/', $row[$rowKey], $names);

		if (count($names[0]) != $countDeals){
			$this->addError('Client name',
				'File has broken row with not equal quantity of policies and client names.');

			throw new \Exception('error');
		}

		foreach ($names[0] as $key => $name) {
			$rows[$key][$rowKey] = $name;
		}
	}

	/**
	 * @param string $rowKey
	 * @param array  $row
	 * @param int    $countDeals
	 * @param array  $rows
	 *
	 * @throws \Exception
	 */
	protected function parseBrokenCommissionType(string $rowKey, array $row, int $countDeals, array &$rows)
	{
		preg_match_all ('/(' . implode('|', array_keys($this->commissionTypes)) . ')/', $row[$rowKey], $types);

		if (count($types[0]) != $countDeals){
			$this->addError('Commission type',
				'File has broken row with not equal quantity of policies and commission types.');

			throw new \Exception('error');
		}

		foreach ($types[0] as $key => $type) {
			$rows[$key][$rowKey] = $type;
		}
	}

	/**
	 * @param string $rowKey
	 * @param array  $row
	 * @param int    $countDeals
	 * @param array  $rows
	 *
	 * @throws \Exception
	 */
	protected function parseBrokenCommission(string $rowKey, array $row, int $countDeals, array &$rows)
	{
		preg_match_all ('/[-0-9]+\.{1}?\d+/', $row[$rowKey], $amounts);
		if (count($amounts[0]) != $countDeals){
			$this->addError('Commission type',
				'File has broken row with not equal quantity of policies and commissions.');

			throw new \Exception('error');
		}

		foreach ($amounts[0] as $key => $amount) {
			$rows[$key][$rowKey] = $amount;
		}
	}

	public function getClientNameKeys(): array
	{
		return ['Insured\'s Name'];
	}

}