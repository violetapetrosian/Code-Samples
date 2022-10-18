<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\Parsers\DocumentParserInterface as DPI;
use common\models\CommissionsImport\Parsers\EdgeBenefitsTravelAndHealthParser;
use common\models\CommissionsImport\Solutions\ChargeBackSolution;
use common\models\CommissionsImport\Solutions\MatchSolution;
use common\models\CommissionsImport\Solutions\ReinstatementSolution;
use common\models\CommissionsImport\Solutions\UnmatchSolution;
use common\models\CommissionsImport\Solutions\UploadSolution;
use common\models\CommissionsImport\Solutions\UploadSubDealSolution;
use common\models\CommissionsImport\Solutions\UploadToAdvisorSolution;

/**
 * Class EdgeBenefitsTravelAndHealthResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class EdgeBenefitsTravelAndHealthResolver extends StandardResolver
{
	protected function getSolutionClassNames(): array
	{
		return [
			DPI::SOLUTION_MATCH => MatchSolution::class,
			DPI::SOLUTION_CHARGE_BACK => ChargeBackSolution::class,
			DPI::SOLUTION_REINSTATEMENT => ReinstatementSolution::class,
			DPI::SOLUTION_UPLOAD_AS_SUB_DEAL => UploadSubDealSolution::class,
			DPI::SOLUTION_UPLOAD => UploadSolution::class,
			DPI::SOLUTION_UPLOAD_TO_ADVISOR => UploadToAdvisorSolution::class,
			DPI::SOLUTION_UNMATCH => UnmatchSolution::class,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function findSolutions(): Resolver
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as $key => $dealContainer) {
			$dealContainer->relatedDealsManager->findPossibleSolutionsForRelatedDeals();

			$solutions = $this->getSolutions();

			switch (true) {
				default :
				case isset($solutions[DPI::SOLUTION_UNMATCH]) && $solutions[DPI::SOLUTION_UNMATCH]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UNMATCH]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_CHARGE_BACK]) && $solutions[DPI::SOLUTION_CHARGE_BACK]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_CHARGE_BACK]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_REINSTATEMENT]) && $solutions[DPI::SOLUTION_REINSTATEMENT]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_REINSTATEMENT]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]) && $solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_MATCH]) && $solutions[DPI::SOLUTION_MATCH]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_MATCH]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD]) && $solutions[DPI::SOLUTION_UPLOAD]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD]->setSolutionToDeal($dealContainer);
					break;
			}

			$dealContainer->relatedDealsManager->setDealAsChecked();
		}

		return $this;
	}
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

		$baseQuery->andWhere(['company_id' => EdgeBenefitsTravelAndHealthParser::COMPANIES_IDS, 'parent_deal_id' => null])
			->advisorContractCode($dealContainer, EdgeBenefitsTravelAndHealthParser::COMPANIES_IDS)
			->orderBy(['id' => SORT_DESC])
			->limit(self::RELATED_DEALS_LIMIT);

		if (!$baseQuery->count()) {
			$dealContainer->setExplanationToDeal("No relevant Agent’s Name found in the system");
		}

		return $baseQuery;
	}

}