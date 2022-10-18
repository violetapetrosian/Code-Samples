<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;

use common\models\User;
use frontend\models\Company;
use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;

/**
 * Class BerkleyTravelParser
 *
 * @package common\models\CommissionsImport
 */
class BerkleyTravelParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::BERKLEY_COMPANY_ID];
	const DEAL_TYPES = ['Travel'];

	const ORIGINAL_ADVISOR_NAME_KEYS = ['Advisor Name'];

	/**
	 * @var string $dealClass full name of deal class
	 */
	protected $dealClass = InsuranceDeals::class;

	/**
	 * @var int
	 */
	protected $titlesIndex = 1;

	/**
	 * @var string[]
	 */
	protected $columnsRange = ['A', 'AI'];
	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Policy No',
		'Advisor Name',
		'Fundserv Code',
		'Policy Holder Name',
		'Commission',
		'Amount( Cad)',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Policy No' => [
			'match' => 'policy_number',
			'method' => false,
		],
		'Advisor Name' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
		],
		'Fundserv Code' => [
			'match' => 'additional_data',
			'method' => 'parseAdvisorCode',
		],
		'Policy Holder Name' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Commission' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Amount( Cad)' => [
			'match' => 'premium',
			'method' => 'parsePremium',
			'money' => true,
		]
	];

	/**
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [InsuranceDeals::class];
	}

	/**
	 * @return array|string[]
	 */
	public function getOriginalAdvisorNameKeys(): array
	{
		return self::ORIGINAL_ADVISOR_NAME_KEYS;
	}

	/**
	 * @inheritDoc
	 */
	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = InsuranceDeals::class;
	}

	/**
	 * @param DealContainer $deal
	 * @param string|null   $advisorName
	 */
	protected function parseAdvisorName(DealContainer $deal, ?string $advisorName)
	{
		if (!$advisorName) {
			$deal->advisor_name = '';
			$deal->forceToUnmatch = true;
			return;
		}
		$deal->advisor_name = $this->getParseSimpleName($advisorName);
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $fsCode
	 */
	protected function parseAdvisorCode(DealContainer $deal, ?string $fsCode)
	{
		$deal->additional_data['fsCode'] = $fsCode;

		if (!$deal->advisor_name) {
			$advisor = User::findOne(['advisor_fs_code_number' => $fsCode]);
			$deal->advisor_name = $advisor->fullName ?? '';
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param string|null   $clientName
	 */
	protected function parseClientName (DealContainer $deal, ?string $clientName)
	{
		$deal->client_name = $this->getParseSimpleName($clientName);
	}

	/**
	 * @param DealContainer $deal
	 * @param string|null   $commission
	 */
	protected function parseCommission(DealContainer $deal, ?string $commission)
	{
		$deal->commission = preg_match('/[0-9]/', $commission) ? Money::toFloat($commission) : null;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $premium
	 */
	protected function parsePremium(DealContainer $deal, ?string $premium)
	{
		$deal->premium = preg_match('/[0-9]/', $premium) ? Money::toFloat($premium) : null;
	}

	/**
	 * @inheritDoc
	 */
	public function additionalQuerySettings(DealQuery $query): DealQuery
	{
		$query = parent::additionalQuerySettings($query);

		return $query->andWhere(['type' => $this::DEAL_TYPES]);
	}

	public function getClientNameKeys(): array
	{
		return ['Policy Holder Name'];
	}

}