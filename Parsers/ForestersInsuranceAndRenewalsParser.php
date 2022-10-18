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
 * Class ForestersInsuranceAndRenewalsParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class ForestersInsuranceAndRenewalsParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::FORESTERS_COMPANY_ID];
	const DEAL_TYPES = ['Insurance'];

	const ORIGINAL_ADVISOR_NAME_KEYS = ['Commission Agent Name'];

	protected $titlesIndex = 1;
	protected $columnsRange = ['A', 'O'];

	protected $resolverClass = InsuranceAndRenewalsCombinedResolver::class;
	protected $experiorAdvisorName = 'Experior Financial Group Inc.';

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'PolicyName' => 'Policy Name',
		'PolicyNumber' => 'Policy Number',
		'FYC' => 'Fyc',
		'REN' => 'Ren',
		'Commission Id',
		'CommissionAgentName' => 'Commission Agent Name',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Policy Name' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Policy Number' => [
			'match' => 'policy_number',
			'method' => 'parsePolicyNumber'
		],
		'Ren' => [
			'match' => 'parsed_deal_class',
			'method' => 'parseRenewalCommission',
			'money' => true,
		],
		'Fyc' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Fov' => [
			'match' => 'additional_data',
			'method' => null,
			'money' => true,
		],
		'Fov To Experior' => [
			'match' => 'additional_data',
			'method' => null,
			'money' => true,
		],
		'Commission Id' => [
			'match' => 'contract_code',
			'method' => 'parseAdvisorContractCode',
		],
		'Commission Agent Name' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
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
	 * @inheritDoc
	 */
	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = InsuranceDeals::class;
	}

	/**
	 * @param DealContainer $deal
	 * @param               $advisorName
	 */
	protected function parseAdvisorName(DealContainer $deal, ?string $advisorName)
	{
		$advisorName = preg_replace('/[^[:print:]]/', ' ', $advisorName);
		$advisorName = preg_replace('/\s+/', ' ', $advisorName);
		$advisorName = trim($advisorName);
		if ($advisorName == $this->experiorAdvisorName) {
			$deal->additional_data['solution'] = DocumentParserInterface::SOLUTION_UNMATCH;
			$deal->advisor_name = $advisorName;

			return;
		}
		$name = explode(' ', $advisorName);
		$fullName = preg_replace("/[^`'\- A-Za-z]/",'', trim(array_shift($name)));
		if (count($name)) {
			foreach ($name as $item) {
				$fullName .= ' ' . preg_replace("/[^`'\- A-Za-z]/",'', trim($item));
			}
		}

		if ($deal->advisor_name && trim($fullName) && $deal->advisor_name != $fullName) {
			$deal->forceToUnmatch = true;
			$deal->additional_report_data['Explanation'] = 'Advisor name not matching with contract code.';
		} else {
			$deal->advisor_name = $deal->advisor_name ?? trim($fullName);
		}
	}

	protected function parsePolicyNumber(DealContainer $deal, ?string $policyNumber)
	{
		$deal->policy_number = trim($policyNumber);
	}

	/**
	 * @param DealContainer $deal
	 * @param               $clientName
	 */
	protected function parseClientName(DealContainer $deal, ?string $clientName)
	{
		$clientName = preg_replace('/[^[:print:]]/', ' ', $clientName);
		$clientName = trim($clientName);
		$name = explode(' ', $clientName);

		$fullName = preg_replace("/[^`'\- A-Za-z]/",'', trim(array_shift($name)));
		if (count($name)) {
			foreach ($name as $item) {
				$fullName .= ' ' . preg_replace("/[^`'\- A-Za-z]/",'', trim($item));
			}
		}

		$deal->client_name = $fullName;
	}

	/**
	 * @param DealContainer $deal
	 * @param               $commission
	 */
	protected function parseCommission(DealContainer $deal, ?string $commission)
	{
		$commission = preg_match('/[0-9]/', $commission) ? Money::toFloat($commission) : null;

		if ($deal->commission === null && $commission !== null) {
			$deal->commission = $commission;
		}

		if ($commission) {
			$deal->parsed_deal_class = InsuranceDeals::class;
			$deal->additional_data = ['fyc' => $commission];
		}
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $commission
	 */
	protected function parseRenewalCommission(DealContainer $deal, ?string $commission)
	{
		$renewalCommission = preg_match('/[0-9]/', $commission) ? Money::toFloat($commission) : null;

		if ($renewalCommission) {
			$deal->parsed_deal_class = Renewals::class;
			$deal->commission = $renewalCommission;
			$deal->additional_data['renewalCommission'] = $renewalCommission;
		}
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
	 * Divides DealContainer to separate DealContainers if it has both renewal commission and fyc
	 *
	 * @param DealContainer $dealContainer
	 *
	 * @return array
	 */
	protected function divideFycAndRenewalCommissions(DealContainer $dealContainer): array
	{
		$dividedDealContainers[] = $dealContainer;
		if (isset($dealContainer->additional_data['fyc']) && isset($dealContainer->additional_data['renewalCommission'])) {
			$newDealContainer = clone $dealContainer;
			if ($dealContainer->parsed_deal_class == Renewals::class) {
				$newDealContainer->parsed_deal_class = InsuranceDeals::class;
				$newDealContainer->commission = $dealContainer->additional_data['fyc'];
			} else {
				$newDealContainer->parsed_deal_class = Renewals::class;
				$newDealContainer->commission = $dealContainer->additional_data['renewalCommission'];
			}
			$dividedDealContainers[] = $newDealContainer;
		}

		return $dividedDealContainers;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parseAndCreateDealsContainers()
	{
		$rows = array_slice($this->rows, $this->titlesIndex);
		foreach ($rows as $row) {
			$deal = new DealContainer();
			$this->setDealDefaults($deal);
			$deal->original = [$row];

			$this->matchRowColumns($deal, $row);
			if (strpos($deal->client_name, 'Balance') !== false) {
				break;
			}
			$dividedDeals = $this->divideFycAndRenewalCommissions($deal);
			foreach ($dividedDeals as $deal) {
				if (!$this->checkDeal($deal)) {
					continue;
				}

				$this->deals[] = $deal;
			}
		}
	}

	public function getClientNameKeys(): array
	{
		return ['Policy Name'];
	}

	/**
	 * Looking for a contract code agent.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseAdvisorContractCode(DealContainer $deal, ?string $value)
	{
		$user = $this->getAgentByContractCode(Company::FORESTERS_COMPANY_ID, $value);
		$deal->additional_data['user'] = $user;

		$deal->contract_code = $value;

		if ($user) {
			$deal->advisor_name = preg_replace('/\s+/', ' ', $user->legalName);
		}
	}

	/**
	 * @return array|string[]
	 */
	public function getOriginalAdvisorNameKeys(): array
	{
		return self::ORIGINAL_ADVISOR_NAME_KEYS;
	}

}