<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\IndustrialAllianceInsuranceAndRenewalsResolver;
use common\models\Renewals;

use frontend\models\Company;
use frontend\models\InsuranceDeals;

/**
 * Class IndustrialAllianceInsuranceAndRenewalsParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class IndustrialAllianceInsuranceAndRenewalsParser extends SplitParser
{
	const COMPANIES_IDS = [Company::INDUSTRIAL_COMPANY_ID];
	const DEAL_TYPES = ['Insurance'];

	const TRANS_TYPE_SUB_DEAL = 'UL Exc Prem';
	const TRANS_TYPE_INSURANCE = 'Issues PP';
	const TRANS_TYPE_CHARGE_BACK = 'Comm Deferred';
	const TRANS_TYPE_RENEWAL = 'Life Renewal';

	protected $resolverClass = IndustrialAllianceInsuranceAndRenewalsResolver::class;

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Contract Number',
		'From/ To',
		'Comm Premium',
		"Client's Name",
		'Transaction Type',
	];

	/**
	 * @var array $additionalFields
	 */
	protected $additionalFields = [
		'Plan',
		'Amount',
//		'Share%',
	];


	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Transaction Type' => [
			'match' => 'parsed_deal_class',
			'method' => 'parseDealTypeClass',
		],
		'Contract Number' => [
			'match' => 'policy_number',
			'method' => 'parsePolicyNumber',
 		],
		'From/ To' => [
			'match' => 'contract_code',
			'method' => 'parseAdvisorName',
		],
		'Amount' => [
			'match' => 'premium',
			'method' => 'parsePremiumAmount',
			'money' => true,
		],
		'Comm Premium' => [
			'match' => 'commission',
			'method' => 'parseDealCommission',
			'money' => true,
		],
		"Client's Name" => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Plan' => [
			'match' => 'industrialAlliancePlan',
			'method' => 'parseDealPlan',
		],
		/*'Share%' => [
			'match' => 'share_percent',
			'method' => 'parseCommissionSharePercent',
		]*/
	];

	protected function parsePolicyNumber(DealContainer $deal, ?string $value)
	{
		$deal->policy_number = $value;
	}

	protected function parseDealTypeClass(DealContainer $deal, ?string $value)
	{
		$deal->transaction_type = $value;
		$deal->parsed_deal_class = $value == self::TRANS_TYPE_RENEWAL ? Renewals::class : InsuranceDeals::class;
		$deal->matched_deal_class = $deal->parsed_deal_class;

		if ($deal->parsed_deal_class == Renewals::class) {
			$deal->skipToUnmatchReport = true;
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('Renewals Paid Direct by the carrier');
		}
	}

	protected function parseAdvisorName(DealContainer $deal, ?string $value)
	{
		if (!$value) {
			$value = -1;
			$deal->forceToUnmatch = true;

			$deal->setExplanationToDeal('The Contract Code in the statement is missing');
		}

		$user = $this->getAgentByContractCode(Company::INDUSTRIAL_COMPANY_ID, $value);
		$deal->additional_data['user'] = $user;

		$deal->contract_code = $value;
		$deal->advisor_name = $user ? $user->fullName : '';
	}

	protected function parseDealCommission(DealContainer $deal, ?string $value)
	{
		$deal->commission = $this->parseMoneyColumn($value);
	}

	protected function parseDealPlan(DealContainer $deal, ?string $value)
	{
		$deal->industrialAlliancePlan = $value;
	}

	protected function parsePremiumAmount(DealContainer $deal, ?string $value)
	{
		$deal->premium = $this->parseMoneyColumn($value);
	}

	protected function parseMoneyColumn($value)
	{
		return preg_match('/[0-9]/', $value) ? Money::toFloat($value) : null;
	}

	protected function parseClientName(DealContainer $deal, ?string $value)
	{
		$deal->client_name = $this->getParseSimpleName($value) ?? '';
		if (!$deal->client_name) {
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('Empty Client Name');
		}
	}

	public function getClientNameKeys(): array
	{
		return ["Client's Name"];
	}

}