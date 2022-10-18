<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\DealContainer;
use common\models\Renewals;

use frontend\models\queries\DealQuery;
use frontend\models\InsuranceDeals;

use yii\db\ActiveQuery;

/**
 * Class InsuranceAndRenewalsCombinedWithCodeResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
abstract class InsuranceAndRenewalsCombinedWithCodeResolver extends InsuranceAndRenewalsCombinedResolver
{
	/**
	 * Returns the company for which the contract will be searched.
	 *
	 * @return int
	 */
	abstract protected function getContractingCompanyId(): int;

	/**
	 * Returns the companies for which deals will be searched.
	 *
	 * @return array
	 */
	abstract protected function getResolverCompanies(): array;

	/**
	 * @param $dealContainer
	 *
	 * @return \yii\db\ActiveQuery
	 */
	protected function getBaseQueryForRelevantDeals($dealContainer)
	{
		$baseQuery = $this->getBaseDealFindClass($dealContainer)::find()
			->alias('base_deal')
			->select(['base_deal.*']);
		$baseQuery = $this->parser->additionalQuerySettings($baseQuery);

		$baseQuery->andWhere(['company_id' => $this->getResolverCompanies(), 'parent_deal_id' => null])
			->advisorContractCode($dealContainer, $this->getContractingCompanyId())
			->orderBy(['id' => SORT_DESC])
			->limit(self::RELATED_DEALS_LIMIT);

		if (!$baseQuery->count()) {
			$dealContainer->setExplanationToDeal("No relevant Agent’s Name found in the system");
		}

		return $baseQuery;
	}

	/**
	 * @param DealContainer $dealContainer
	 *
	 * @return DealQuery|ActiveQuery
	 */
	protected function getCombinedBaseQueryForRelevantDeals(DealContainer $dealContainer): DealQuery
	{
		$baseQuery = Renewals::find()->select(["*"]);
		$baseQuery = $this->parser->additionalQuerySettings($baseQuery);

		return $baseQuery->andWhere([
			'company_id' => array_keys($this->parser->getCompanies()),
			'parent_deal_id' => null,
		])
			->advisorContractCode($dealContainer, $this->getContractingCompanyId())
			->orderBy(['id' => SORT_DESC])
			->limit(self::RELATED_DEALS_LIMIT);
	}

	/**
	 * @param DealContainer $dealContainer
	 *
	 * @return string[]
	 */
	protected function getPriorityUserCondition(DealContainer $dealContainer): array
	{
		return $dealContainer->getContractCodeCondition($this->getContractingCompanyId());
	}

}