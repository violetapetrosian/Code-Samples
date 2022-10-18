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
 * Class GreenShieldHealthAndRenewalParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class GreenShieldHealthAndRenewalParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::GREEN_SHIELD_COMPANY_ID];
	const DEAL_TYPES = ['Health/Dental'];

	const ORIGINAL_ADVISOR_NAME_KEYS = ['Advisorfirstname', 'Advisorlastname'];

	protected $titlesIndex = 1;
	protected $columnsRange = ['A', 'AZ'];

	protected $resolverClass = InsuranceAndRenewalsCombinedResolver::class;

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'PLAN MEMBER ID' => 'Planmemberid',
		'ADVISOR CODE' => 'Advisorcode',
		'ADVISOR FIRST NAME' => 'Advisorfirstname',
		'ADVISOR LAST NAME' => 'Advisorlastname',
		'PLAN MEMBER FIRST NAME' => 'Planmemberfirstname',
		'PLAN MEMBER LAST NAME' => 'Planmemberlastname',
		'FIRST YEAR/RENEWAL' => 'Firstyear/ Renewal',
		'TOTAL AMOUNT PAID' => 'Totalamountpaid',
		'TOTAL PREMIUM' => 'Totalpremium',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Planmemberid' => [
			'match' => 'policy_number',
			'method' => false,
		],
		'Advisorcode' => [
			'match' => 'additional_data',
			'method' => 'parseAdvisorCode',
		],
		'Advisorfirstname' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorFirstName',
		],
		'Advisorlastname' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorLastName',
		],
		'Planmemberfirstname' => [
			'match' => 'client_name',
			'method' => 'parseClientFirstName',
		],
		'Planmemberlastname' => [
			'match' => 'client_name',
			'method' => 'parseClientLastName',
		],
		'Firstyear/ Renewal' => [
			'match' => 'parsed_deal_class',
			'method' => 'parseCommissionType',
		],
		'Totalamountpaid' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Totalpremium' => [
			'match' => 'premium',
			'method' => 'parsePremium',
			'money' => true,
		],
	];

	/**
	 * @inheritDoc
	 */
	public function checkFileFormat(): bool
	{
		parent::checkFileFormat();

		if (count($this->sheets) != 1) {
			$this->addError('sheets_count', 'The file should contain exactly one sheet!');
		}

		return !$this->hasErrors();
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
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [InsuranceDeals::class, Renewals::class];
	}

	/**
	 * @param null|string $firstName
	 */
	protected function parseClientFirstName(DealContainer $deal, ?string $firstName)
	{
		$deal->client_name = $this->parseName($firstName);
		$deal->additional_data['clientFirstName'] = $deal->client_name;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $lastName
	 */
	protected function parseClientLastName(DealContainer $deal, ?string $lastName)
	{
		$lastName = $this->parseName($lastName);
		$deal->client_name = $deal->client_name . ' ' . $lastName;
		$deal->additional_data['clientLastName'] = $lastName;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $fsCode
	 */
	protected function parseAdvisorCode(DealContainer $deal, ?string $fsCode)
	{
		$deal->additional_data['fsCode'] = $fsCode;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $firstName
	 */
	protected function parseAdvisorFirstName(DealContainer $deal, ?string $firstName)
	{
		if (!$firstName) {
			$deal->forceToUnmatch = true;
		}

		$deal->advisor_name = $this->parseName($firstName);
		$deal->additional_data['advisorFirstName'] = $deal->advisor_name;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $lastName
	 */
	protected function parseAdvisorLastName(DealContainer $deal, ?string $lastName)
	{
		if (!$lastName) {
			$deal->forceToUnmatch = true;
		}

		$lastName = $this->parseName($lastName);
		$deal->advisor_name = $deal->advisor_name . ' ' . $lastName;
		$deal->additional_data['advisorLastName'] = $lastName;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $value
	 */
	protected function parseCommissionType(DealContainer $deal, ?string $value)
	{
		$value = trim($value);
		switch ($value) {
			case 'FIRST YEAR' :
				$deal->parsed_deal_class = InsuranceDeals::class;
				break;
			case 'RENEWAL' :
				$deal->parsed_deal_class = Renewals::class;
				break;
			default :
				$deal->parsed_deal_class = null;
				break;
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $value
	 */
	protected function parseCommission(DealContainer $deal, ?string $value)
	{
		$deal->commission = $this->parseMoneyColumn($value);
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $value
	 */
	protected function parsePremium(DealContainer $deal, ?string $value)
	{
		$deal->premium = $this->parseMoneyColumn($value);
	}

	/**
	 * @param $value
	 *
	 * @return float|null
	 */
	private function parseMoneyColumn($value)
	{
		$value = str_replace(',', '.', $value);

		return preg_match('/[0-9]/', $value) ? Money::toFloat($value) : null;
	}

	/**
	 * @param null|string $name
	 *
	 * @return string
	 */
	private function parseName(?string $name): string
	{
		if (!$name) {

			return '';
		}
		$name = explode(' ', trim($name));
		foreach ($name as &$part) {
			$part = ucfirst(strtolower($part));
		}
		unset($part);

		return implode(' ', $name);
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

	public function getClientNameKeys(): array
	{
		return ['Planmemberfirstname'];
	}
}