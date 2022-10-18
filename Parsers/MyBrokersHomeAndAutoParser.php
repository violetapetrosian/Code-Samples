<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\UnlicensedResolver;
use common\models\NBTUnlicensed;

use frontend\models\Company;
use frontend\models\UnlicensedDeal;
use frontend\models\VirtgateUniversalForm;

/**
 * Class MyBrokersHomeAndAutoParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class MyBrokersHomeAndAutoParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::AHA_UNLICENSED_DEALS_ID];
	const DEAL_TYPES = [NBTUnlicensed::PRODUCT_TYPE_AHA];

	protected $titlesIndex = 1;
	protected $columnsRange = ['A', 'R'];

	protected $resolverClass = UnlicensedResolver::class;

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Advisor',
		'Billing',
		'First Name',
		'Last Name',
		'Business Name',
		'Policy#',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Advisor' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
		],
		'Billing' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'First Name' => [
			'match' => 'client_name',
			'method' => 'parseClientFirstName',
		],
		'Last Name' => [
			'match' => 'client_name',
			'method' => 'parseClientLastName',
		],
		'Business Name' => [
			'match' => 'client_name',
			'method' => 'parseBusinessName',
		],
		'Policy#' => [
			'match' => 'policy_number',
			'method' => 'parsePolicyNumber',
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
	 * @inheritDoc
	 */
	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = UnlicensedDeal::class;
		$deal->premium = 0.0;
	}

	/**
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [UnlicensedDeal::class];
	}

	/**
	 * @inheritDoc
	 */
	public function getFormCompanyId()
	{
		return VirtgateUniversalForm::MY_BROKERS_COMPANY_ID;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $firstName
	 */
	protected function parseClientFirstName(DealContainer $deal, ?string $firstName)
	{
		if (!$firstName) {

			return;
		}
		if ($deal->client_name) {
			$deal->client_name = $this->parseName($firstName) . $deal->client_name;
		} else {
			$deal->client_name = $this->parseName($firstName);
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $lastName
	 */
	protected function parseClientLastName(DealContainer $deal, ?string $lastName)
	{
		if (!$lastName) {

			return;
		}
		$deal->client_name .= ' ' . $this->parseName($lastName);
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $businessName
	 */
	protected function parseBusinessName(DealContainer $deal, ?string $businessName)
	{
		$businessName = trim($businessName);
		if (!$businessName) {

			return;
		}
		$deal->client_name = trim($businessName);
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $advisorName
	 */
	protected function parseAdvisorName(DealContainer $deal, ?string $advisorName)
	{
		if (!$advisorName) {
			$deal->advisor_name = '';
			$deal->forceToUnmatch = true;
			return;
		}
		$deal->advisor_name = $this->parseName($advisorName);
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
	protected function parsePolicyNumber(DealContainer $deal, ?string $value)
	{
		$deal->policy_number = $value;
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
	 * @param string $name
	 *
	 * @return string
	 */
	private function parseName(string $name)
	{
		$name = preg_replace('/[^a-zA-Z \-`\']/', '', $name);
		$name = explode(' ', trim($name));
		foreach ($name as &$part) {
			$part = ucfirst(strtolower(trim($part)));
		}
		unset($part);

		return implode(' ', $name);
	}

	public function getClientNameKeys(): array
	{
		return ['First Name', 'Last Name'];
	}

}