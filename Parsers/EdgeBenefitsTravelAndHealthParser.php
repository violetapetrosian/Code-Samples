<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\EdgeBenefitsTravelAndHealthResolver;

use frontend\models\Company;
use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;


/**
 * Class EdgeBenefitsTravelAndHealthParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class EdgeBenefitsTravelAndHealthParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::EDGE_BENEFITS_COMPANY_ID, Company::EDGE_COMPANY_ID];
	const DEAL_TYPES = ['Health/Dental', 'Travel'];

	const ORIGINAL_ADVISOR_NAME_KEYS = ['Advisor'];

	protected $resolverClass = EdgeBenefitsTravelAndHealthResolver::class;

	/**
	 * @var string $dealClass full name of deal class
	 */
	protected $dealClass = InsuranceDeals::class;

	protected $titlesIndex = 5;
	protected $columnsRange = ['A', 'O'];

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Advisor Code',
		'Policy',
		'Client',
		'Advisor',
		'Total Commission',
		'Premium',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Advisor Code' => [
			'match' => 'contract_code',
			'method' => 'parseAdvisorContractCode',
		],
		'Policy' => [
			'match' => 'policy_number',
			'method' => false,
		],
		'Client' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Advisor' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
		],
		'Total Commission' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Premium' => [
			'match' => 'premium',
			'method' => 'parsePremium',
			'money' => true,
		],
		'Commission First Year' => [
			'money' => true,
		],
		'Commission Renewal' => [
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
	public function getCompanies (): array
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
		if (!$value) {
			return;
		}

		$splitCode = explode('/', $value);
		if (count($splitCode) > 1) {
			$deal->contract_code = reset($splitCode) ? reset($splitCode) : null;
			$deal->additional_data['userContractCode'] = $deal->contract_code;
			$deal->additional_data['sharedUserContractCode'] = end($splitCode) ? end($splitCode) : null;
			$deal->is_shared = 1;

			$deal->additional_data['user'] =  $this->getAgentByContractCode(Company::EDGE_BENEFITS_COMPANY_ID, reset($splitCode))
				?? $this->getAgentByContractCode(Company::EDGE_COMPANY_ID, reset($splitCode));
			$deal->additional_data['sharedUser'] =  $this->getAgentByContractCode(Company::EDGE_BENEFITS_COMPANY_ID, end($splitCode))
				?? $this->getAgentByContractCode(Company::EDGE_COMPANY_ID, end($splitCode));
		} else {
			$user = $this->getAgentByContractCode(Company::EDGE_BENEFITS_COMPANY_ID, $value)
				?? $this->getAgentByContractCode(Company::EDGE_COMPANY_ID, $value);
			$deal->additional_data['user'] = $user;
			$deal->contract_code = $value;
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param string        $advisorName
	 */
	protected function parseAdvisorName(DealContainer $deal, ?string $advisorName)
	{
		if (!$advisorName) {
			$deal->advisor_name = '';
			$deal->forceToUnmatch = true;
			return;
		}

		$splitName = explode('/', $advisorName);
		if (count($splitName) > 1) {
			$deal->advisor_name = reset($splitName) ? $this->getParseSimpleName(reset($splitName)) : null;
			$deal->share_advisor_name = end($splitName) ? $this->getParseSimpleName(end($splitName)) : null;
			$deal->is_shared = 1;
		} else {
			$deal->advisor_name = $this->getParseSimpleName($advisorName);
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param string|null   $clientName
	 */
	protected function parseClientName(DealContainer $deal, ?string $clientName)
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
		return ['Client'];
	}

	/**
	 * @return array|string[]
	 */
	public function getOriginalAdvisorNameKeys(): array
	{
		return self::ORIGINAL_ADVISOR_NAME_KEYS;
	}


}