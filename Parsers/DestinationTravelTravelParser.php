<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;

use frontend\models\Company;
use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;

/**
 * Class DestinationTravelTravelParser
 *
 * @package common\models\CommissionsImport
 */
class DestinationTravelTravelParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::DESTINATION_TRAVEL_COMPANY_ID];
	const DEAL_TYPES = ['Travel'];

	/**
	 * @var string $dealClass full name of deal class
	 */
	protected $dealClass = InsuranceDeals::class;

	protected $titlesIndex = 2;
	protected $columnsRange = ['A', 'O'];
	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Policy No',
		'Insured Name',
		'Sub Agent',
		'Commission Amount',
		'Gross Premium',
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
		'Insured Name' => [
			'match' => 'client_name',
			'method' => 'parseClientName',
		],
		'Sub Agent' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
		],
		'Commission Amount' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Gross Premium' => [
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
		if (!$advisorName) {
			$deal->advisor_name = '';
			$deal->forceToUnmatch = true;
			return;
		}

		$advisorName = preg_replace('/[^[:print:]]/', ' ', $advisorName);
		$name = str_replace('Sub-Agency:', '', $advisorName);
		$name = explode(' - ', $name);
		$name = isset($name[1]) ? explode(',', $name[1]) : [$name[0]];
		$lastName = preg_replace("/[^`'\- A-Za-z]/",'', trim($name[0]));
		$firstName = isset($name[1]) ? preg_replace("/[^`'\- A-Za-z]/",'', trim($name[1])) : '';

		$deal->advisor_name = $firstName.' '.$lastName;
	}

	/**
	 * @param DealContainer $deal
	 * @param               $clientName
	 *
	 * @return string
	 */
	protected function parseClientName (DealContainer $deal, ?string $clientName)
	{
		$clientName = preg_replace('/[^[:print:]]/', ' ', $clientName);
		$name = explode(',', $clientName);
		$lastName = preg_replace("/[^`'\- A-Za-z]/",'', trim($name[0]));
		$firstName = isset($name[1]) ? preg_replace("/[^`'\- A-Za-z]/",'', trim($name[1])) : '';

		$deal->client_name = $firstName.' '.$lastName;
	}

	/**
	 * @param DealContainer $deal
	 * @param               $commission
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
		return ['Insured Name'];
	}

}