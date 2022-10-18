<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\DealContainer;

use frontend\models\Company;
use frontend\models\queries\DealQuery;

/**
 * Class IngleTravelResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class IngleTravelResolver extends StandardResolver
{
	/**
	 * @param DealContainer $dealContainer
	 *
	 * @return string[]
	 */
	protected function getPriorityUserCondition(DealContainer $dealContainer): array
	{
		return $dealContainer->getContractCodeCondition(Company::INGLE_COMPANY_ID);
	}

	/**
	 * @param DealContainer $dealContainer
	 *
	 * @return DealQuery
	 */
	protected function getBaseQueryForRelevantDeals(DealContainer $dealContainer)
	{
		$baseQuery = $this->getBaseDealFindClass($dealContainer)::find()
			->alias('base_deal')
			->select(['base_deal.*']);
		$baseQuery = $this->parser->additionalQuerySettings($baseQuery);

		$baseQuery->andWhere(['company_id' => array_keys($this->parser->getCompanies())])
			->advisorContractCode($dealContainer, Company::INGLE_COMPANY_ID)
			->orderBy(['id' => SORT_DESC])
			->limit(self::RELATED_DEALS_LIMIT);

		if (!$baseQuery->count()) {
			$dealContainer->setExplanationToDeal("No relevant Agent’s Name found in the system");
		}

		if ($this->getBaseDealFindClass($dealContainer)::hasColumnInDealTable('parent_deal_id')) {
			$baseQuery->andWhere(['parent_deal_id' => null]);
		}

		return $baseQuery;
	}

}
