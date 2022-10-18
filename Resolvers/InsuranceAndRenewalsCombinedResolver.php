<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Parsers\DocumentParserInterface as DPI;
use common\models\CommissionsImport\Solutions\ChargeBackSolution;
use common\models\CommissionsImport\Solutions\MatchInsuranceAndRenewalCombinedSolution;
use common\models\CommissionsImport\Solutions\ReinstatementSolution;
use common\models\CommissionsImport\Solutions\UnmatchSolution;
use common\models\CommissionsImport\Solutions\UploadInsuranceAndRenewalsCombinedSolution;
use common\models\CommissionsImport\Solutions\UploadRenewalsSolution;
use common\models\CommissionsImport\Solutions\UploadRenewalSubDealSolution;
use common\models\CommissionsImport\Solutions\UploadSubDealInsuranceRenewalCombinedSolution;
use common\models\CommissionsImport\Solutions\UploadToAdvisorSolution;
use common\models\Renewals;

use frontend\models\queries\DealQuery;

/**
 * Class GmsAndGreenShieldResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class InsuranceAndRenewalsCombinedResolver extends StandardResolver
{
	/**
	 * Pre-founds solutions before user action
	 *
	 * @return $this
	 */
	public function findSolutions(): Resolver
	{
		$deals = $this->getProcessingDealContainers();
		$solutions = $this->getSolutions();

		foreach ($deals as $key => $dealContainer) {
			$dealContainer->relatedDealsManager->findPossibleSolutionsForRelatedDeals();

			if (isset($dealContainer->additional_data['solution'])) {
				$dealContainer->solution = $dealContainer->additional_data['solution'];
				continue;
			}

			switch (true) {
				case $solutions[DocumentParserInterface::SOLUTION_UNMATCH]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_UNMATCH]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DocumentParserInterface::SOLUTION_CHARGE_BACK]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_CHARGE_BACK]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DocumentParserInterface::SOLUTION_REINSTATEMENT]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_REINSTATEMENT]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DocumentParserInterface::SOLUTION_MATCH]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_MATCH]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DocumentParserInterface::SOLUTION_UPLOAD]->isFit($dealContainer) :
					$solutions[DocumentParserInterface::SOLUTION_UPLOAD]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD_RENEWALS]) && $solutions[DPI::SOLUTION_UPLOAD_RENEWALS]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD_RENEWALS]->setSolutionToDeal($dealContainer);
					break;
				default :
					$solutions[DocumentParserInterface::SOLUTION_UNMATCH]->setSolutionToDeal($dealContainer);
					break;
			}

			$dealContainer->relatedDealsManager->setDealAsChecked();
		}

		return $this;
	}

	/**
	 * @return array
	 */
	protected function getSolutionClassNames(): array
	{
		return [
			DocumentParserInterface::SOLUTION_MATCH => MatchInsuranceAndRenewalCombinedSolution::class,
			DocumentParserInterface::SOLUTION_CHARGE_BACK => ChargeBackSolution::class,
			DocumentParserInterface::SOLUTION_REINSTATEMENT => ReinstatementSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL => UploadRenewalSubDealSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL => UploadSubDealInsuranceRenewalCombinedSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD_RENEWALS => UploadRenewalsSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD => UploadInsuranceAndRenewalsCombinedSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD_TO_ADVISOR => UploadToAdvisorSolution::class,
			DocumentParserInterface::SOLUTION_UNMATCH => UnmatchSolution::class,
		];
	}

	/**
	 * Tries to find relevant deal.
	 */
	protected function findRelevantDeals()
	{
		parent::findRelevantDeals();

		$deals = $this->getProcessingDealContainers();

		foreach ($deals as $dealContainer) {
			if ($dealContainer->parsed_deal_class === Renewals::class
				&& !count($dealContainer->relatedDealsManager->getDeals())
			) {
				$amountAttribute = Renewals::getAmountAttribute();

				$baseQuery = $this->getCombinedBaseQueryForRelevantDeals($dealContainer);

				$relatedDeals = $baseQuery
					->clientNameIfNotPolicy($dealContainer->client_name)
					->policyNumber($dealContainer->policy_number, $amountAttribute, false)
					->limit(1)
					->all();

				$dealContainer->relatedDealsManager->addDeals($relatedDeals);
			}
		}
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

	/**
	 * @param DealContainer $dealContainer
	 *
	 * @return DealQuery
	 */
	protected function getCombinedBaseQueryForRelevantDeals(DealContainer $dealContainer): DealQuery
	{
		$baseQuery = Renewals::find()->select(["*"]);
		$baseQuery = $this->parser->additionalQuerySettings($baseQuery);

		return $baseQuery->andWhere([
			'company_id' => array_keys($this->parser->getCompanies()),
			'parent_deal_id' => null,
		])
			->advisorName($dealContainer)
			->orderBy(['id' => SORT_DESC])
			->limit(self::RELATED_DEALS_LIMIT);
	}

}