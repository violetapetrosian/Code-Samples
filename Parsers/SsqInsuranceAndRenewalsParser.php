<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\HArray;
use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\SsqInsuranceAndRenewalsResolver;
use common\models\Renewals;

use Exception;
use frontend\models\Company;
use frontend\models\InsuranceDeals;

/**
 * Class SsqInsuranceAndRenewalsParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class SsqInsuranceAndRenewalsParser extends SplitParser
{
	const COMPANIES_IDS = [Company::SSQ_COMPANY_ID];
	const DEAL_TYPES = ['Insurance'];

	const REASON_F = 'F';
	const REASON_P = 'P';
	const REASON_R = 'R';

	const ORIGINAL_ADVISOR_NAME_KEYS = ['Advisorname'];

	public $divideToFilesByDates = true;

	/**
	 * @var int
	 */
	protected $titlesIndex = 2;

	/**
	 * @var string
	 */
	protected $resolverClass = SsqInsuranceAndRenewalsResolver::class;

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Advisornumber',
		'Advisorname',
		'Insured Name',
		'Policy Number',
		'Comm Premium',
		'Comm Share',
		'Comm Amount',
		'Reason',
	];


	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Reason' => [
			'match' => 'parsed_deal_class',
			'method' => 'parseDealTypeClass',
		],
		'Advisornumber' => [
			'match' => 'contract_code',
			'method' => 'parseAdvisorContractCode',
		],
		'Advisorname' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
		],
		'Insured Name' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Policy Number' => [
			'match' => 'policy_number',
			'method' => 'parsePolicyNumber',
 		],
		'Comm Premium' => [
			'match' => 'premium',
			'method' => 'parseDealPremium',
		],
		'Comm Amount' => [
			'match' => 'commission',
			'method' => 'parseDealCommission',
		],
		'Comm Share' => [
			'match' => 'share_percent',
			'method' => 'parseCommissionSharePercent',
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
	 * @return array|string[]
	 */
	public function getOriginalAdvisorNameKeys(): array
	{
		return self::ORIGINAL_ADVISOR_NAME_KEYS;
	}

	/**
	 * Determines the type based on the reason.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseDealTypeClass(DealContainer $deal, ?string $value)
	{
		$parsedDealClassMap = [
			self::REASON_F => InsuranceDeals::class,
			self::REASON_P => InsuranceDeals::class,
			self::REASON_R => Renewals::class,
		];

		$deal->transaction_type = $value;
		$deal->parsed_deal_class = $parsedDealClassMap[$value] ?? null;
		$deal->matched_deal_class = $deal->parsed_deal_class;

		if (!isset($parsedDealClassMap[$value])) {
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('Unsupported reason type');
		}
	}

	/**
	 * Looking for a contract code agent.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseAdvisorContractCode(DealContainer $deal, ?string $value)
	{
		if ($deal->forceToUnmatch) return;

		if (!$value) {
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('The Contract Code in the statement is missing');
			return;
		}

		$user = $this->getAgentByContractCode(Company::SSQ_COMPANY_ID, $value);
		$deal->additional_data['user'] = $user;

		$deal->contract_code = $value;

		if ($user) {
			$deal->advisor_name = $user->fullName;
		}
	}

	/**
	 * Specifies the agent name from the file.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseAdvisorName(DealContainer $deal, ?string $value)
	{
		if (!$value) {
			$deal->forceToUnmatch = true;
		}

		if ($deal->advisor_name) return;

		$deal->advisor_name = preg_replace('/Experior Financial Group Inc.( - )?/', '', $value);
	}

	/**
	 * Specifies the policy number.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parsePolicyNumber(DealContainer $deal, ?string $value)
	{
		$deal->policy_number = $value ?? '';
	}

	/**
	 * Determines the commission on the policy.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseDealCommission(DealContainer $deal, ?string $value)
	{
		$deal->commission = $this->parseMoneyColumn($value);
	}

	/**
	 * Determines the premium from the policy.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseDealPremium(DealContainer $deal, ?string $value)
	{
		$deal->premium = $this->parseMoneyColumn($value);
	}

	/**
	 * Looks up the name of the client from the document.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseClientName(DealContainer $deal, ?string $value)
	{
		$deal->client_name = $this->getParseSimpleName($value) ?? '';
		if (!$deal->client_name) {
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('Empty Client Name');
		}
	}

	public function getDividedRowsByDates(): array
	{
		$rows = array_slice($this->rows, $this->titlesIndex);
		$columnIndex = array_search('Trans Effective Date', $this->titles);

		$rowsByDates = [];
		$rowsWithoutDate = [];
		foreach ($rows as $row) {
			if ($row[$columnIndex]) {
				$rowsByDates[date('Y-m-d', strtotime($row[$columnIndex]))][] = $row;
			} else {
				$rowsWithoutDate[] = $row;
			}
		}
		
		ksort($rowsByDates);

		if ($rowsWithoutDate) {
			$firstKey = array_keys($rowsByDates)[0];
			$rowsByDates[$firstKey] = array_merge($rowsByDates[$firstKey], $rowsWithoutDate);
		}

		return $rowsByDates;
	}

	public function isAbleToPushQueue(): bool
	{
		return $this->id == static::$activeImportId;
	}

	public function getClientNameKeys(): array
	{
		return ['Insured Name'];
	}
}