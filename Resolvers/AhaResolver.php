<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Solutions\UnmatchSolution;
use common\models\CommissionsImport\Solutions\UploadAhaSolution;
use common\models\CommissionsImport\Solutions\UploadToAdvisorSolution;
use common\models\CommissionsImport\DealContainer;

use frontend\models\queries\DealQuery;

/**
 * Class AhaResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class AhaResolver extends StandardResolver
{
	/**
	 * @return array
	 */
	public function getSolutionsCountersLabels(): array
	{
		return array_intersect_key(
			DocumentParserInterface::SOLUTION_COUNTER_LABELS,
			$this->getSolutionClassNames()
		);
	}

	/**
	 * @return array
	 */
	protected function getSolutionClassNames(): array
	{
		return [
			DocumentParserInterface::SOLUTION_UPLOAD => UploadAhaSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD_TO_ADVISOR => UploadToAdvisorSolution::class,
			DocumentParserInterface::SOLUTION_UNMATCH => UnmatchSolution::class,
		];
	}

	/**
	 * Tries to find relevant deal.
	 */
	protected function findRelevantDeals()
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as &$dealContainer) {
			/**
			 * @var DealQuery $query
			 */
			$query = $this->getBaseDealFindClass($dealContainer)::find()->select(["*"]);
			$query = $this->parser->additionalQuerySettings($query);

			$relatedDealsQuery = $query->andWhere(['company_id' => array_keys($this->parser->getCompanies())])
				->advisorName($dealContainer);

			if (!$relatedDealsQuery->count()) {
				$dealContainer->setExplanationToDeal("No relevant Agent’s Name found in the system");
			}

			$relatedDeals = $relatedDealsQuery->clientNameIfNotPolicy($dealContainer->client_name)
				->orderBy(['id' => SORT_DESC])
				->limit(self::RELATED_DEALS_LIMIT)
				->all();

			$this->setRelatedManagerToDeal($dealContainer, $relatedDeals);
		}
	}

	/**
	 * Pre-founds solutions before user action
	 *
	 * @return $this
	 */
	public function findSolutions(): Resolver
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as $key => &$dealContainer) {
			$dealContainer->relatedDealsManager->findPossibleSolutionsForRelatedDeals();
			$solutions = $this->getSolutions();

			switch (true) {
				default :
				case $solutions[DocumentParserInterface::SOLUTION_UNMATCH]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_UNMATCH]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DocumentParserInterface::SOLUTION_UPLOAD]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_UPLOAD]->setSolutionToDeal($dealContainer);
					break;
			}

			$dealContainer->relatedDealsManager->setDealAsChecked();
		}

		return $this;
	}

	/**
	 * @param DealContainer $dealContainer
	 * @param               $deal
	 * @param               $key2
	 */
	protected function skipDealWithoutNbt(DealContainer $dealContainer, $deal, $key2): void
	{
		return;
	}

}