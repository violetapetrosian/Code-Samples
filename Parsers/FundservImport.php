<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;

use frontend\models\Company;
use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;

use yii\db\ActiveQuery;

/**
 * Class FundservImport
 *
 * @package common\models\CommissionsImport
 */
class FundservImport extends ExcelDocumentsParser
{
	//TODO: Rewrite this class. This is only kostil to implement file format validation in fundserv!
	const COMPANIES_IDS = [Company::EDGE_BENEFITS_COMPANY_ID, Company::EDGE_COMPANY_ID];
	const DEAL_TYPES = ['Health/Dental', 'Travel'];

	/**
	 * @var string $dealClass full name of deal class
	 */
	protected $dealClass = InsuranceDeals::class;

	protected $titlesIndex = 1;
	protected $columnsRange = ['A', 'T'];

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Tran Date',
		'Manufacturer',
		'Product',
		'Client',
		'Writing Advisor',
		'Writing Fs Code',
		'Tran Type',
		'Premium',
		'Total',
		'Payment',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [

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
			$this->companies = Company::find()->where(['id' => static::COMPANIES_IDS])
				->indexBy(Company::primaryKey())->all();
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
	 * @param string $advisorName
	 *
	 * @return string|null
	 */
	protected function parseAdvisorName($advisorName)
	{
		return $this->getParseSimpleName($advisorName);
	}

	/**
	 * @param string $clientName
	 *
	 * @return string|null
	 */
	protected function parseClientName($clientName)
	{
		return $this->getParseSimpleName($clientName);
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
	 * @inheritDoc
	 */
	public function additionalQuerySettings(DealQuery $query): DealQuery
	{
		$query = parent::additionalQuerySettings($query);

		return $query->andWhere(['type' => $this::DEAL_TYPES]);
	}

}