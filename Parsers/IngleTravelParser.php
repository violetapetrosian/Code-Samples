<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\IngleTravelResolver;

use frontend\models\Company;
use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;

/**
 * Class IngleTravelParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class IngleTravelParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::INGLE_COMPANY_ID];
	const DEAL_TYPES = ['Travel'];

	/**
	 * @var string $dealClass full name of deal class
	 */
	protected $dealClass = InsuranceDeals::class;

	/**
	 * @var string
	 */
	protected $resolverClass = IngleTravelResolver::class;

	protected $titlesIndex = 3;
	protected $columnsRange = ['A', 'Q'];

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Agent Id',
		'Policy ID *=monthly',
		'Client Name',
		'Amount Due to Agent or (Payable) to Administrator',
		'Premium',
	];

	/**
	 * Array of matching rules for file columns
	 *
	 * @var array
	 */
	protected $rules = [
		'Agent Id' => [
			'match' => 'contract_code',
			'method' => 'parseAdvisorContractCode',
		],
		'Policy ID *=monthly' => [
			'match' => 'policy_number',
			'method' => false,
		],
		'Client Name' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Amount Due to Agent or (Payable) to Administrator' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Premium' => [
			'match' => 'premium',
			'method' => 'parsePremium',
			'money' => true,
		],
	];

	/**
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [InsuranceDeals::class];
	}

	/**
	 * @return Company[]
	 */
	public function getCompanies(): array
	{
		if (!$this->companies) {
			$this->companies = Company::find()->where(['id' => static::COMPANIES_IDS])->indexBy(Company::primaryKey())->all();
		}

		return $this->companies;
	}

	/**
	 * @inheritDoc
	 */
	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = InsuranceDeals::class;
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
			$deal->contract_code = '';
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('The Contract Code in the statement is missing');
			return;
		}

		$user = $this->getAgentByContractCode(Company::INGLE_COMPANY_ID, $value);
		$deal->additional_data['user'] = $user;

		$deal->contract_code = $value;
		$deal->advisor_name = $user->fullName ?? '';
	}

	/**
	 * @param DealContainer $deal
	 * @param string        $clientName
	 */
	protected function parseClientName(DealContainer $deal, $clientName)
	{
		$name = preg_replace('/[^[:print:]]/', ' ', $clientName);
		$name = preg_replace('/\s+/', ' ', $name);
		$name = explode('-', $name);
		$name = preg_replace("/[^`'\- A-Za-z]/",'', trim($name[0]));
		$name = explode(' ', $name);
		$name = array_map(function($elem)
		{
			return trim($elem);
		}, $name);
		$name = implode(' ', $name);

		$deal->client_name = $name;
	}

	/**
	 * @param DealContainer $deal
	 * @param               $commission
	 *
	 * @return void
	 */
	protected function parseCommission(DealContainer $deal, $commission)
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

	/**
	 * @param $headers
	 * @return array
	 */
	protected function normalizeFileTitles($headers): array
	{
		return array_map(function ($string) {
			return trim(preg_replace('/\s+/', ' ', $string));
		}, $headers);
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function parseFile(): bool
	{
		parent::parseFile();

		foreach ($this->deals as &$deal) {

			if (strpos($deal->policy_number, '*')) {
				$deal->policy_number = str_replace('*', '', $deal->policy_number);
			}
		}
		unset($deal);

		return !$this->hasErrors();
	}

	public function getClientNameKeys(): array{
		return ['Client Name'];
	}

}