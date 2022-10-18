<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\EOLicenseChecker;
use common\models\CommissionsImport\Parsers\DocumentParserInterface as DPI;
use common\models\CommissionsImport\Solutions\UnmatchIASolution;

use frontend\models\Company;

/**
 * Class IndustrialAllianceInsuranceAndRenewalsResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class IndustrialAllianceInsuranceAndRenewalsResolver extends InsuranceAndRenewalsCombinedWithCodeResolver
{
	/**
	 * @inheritDoc
	 */
	protected function getContractingCompanyId(): int
	{
		return Company::INDUSTRIAL_COMPANY_ID;
	}

	/**
	 * @inheritDoc
	 */
	protected function getResolverCompanies(): array
	{
		return [Company::INDUSTRIAL_COMPANY_ID, Company::IA_QUEBEC_ONLY_COMPANY_ID];
	}

	protected function getSolutionClassNames(): array
	{
		return array_merge(parent::getSolutionClassNames(), [
			DPI::SOLUTION_UNMATCH => UnmatchIASolution::class,
		]);
	}

	public function findSolutions(): Resolver
	{
		$deals = $this->getProcessingDealContainers();
		$clients = [];

		foreach ($deals as $dealContainer) {
			$clients[$dealContainer->policy_number] = $clients[$dealContainer->policy_number] ?? [];
			$clients[$dealContainer->policy_number][$dealContainer->contract_code] = true;
		}

		foreach ($deals as $key => $dealContainer) {
			$dealContainer->relatedDealsManager->findPossibleSolutionsForRelatedDeals();

			$solutions = $this->getSolutions();

			switch (true) {
				default :
				case isset($solutions[DPI::SOLUTION_UNMATCH]) && $solutions[DPI::SOLUTION_UNMATCH]->isFit($dealContainer, $clients):
					$solutions[DPI::SOLUTION_UNMATCH]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_CHARGE_BACK]) && $solutions[DPI::SOLUTION_CHARGE_BACK]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_CHARGE_BACK]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_REINSTATEMENT]) && $solutions[DPI::SOLUTION_REINSTATEMENT]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_REINSTATEMENT]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_MATCH]) && $solutions[DPI::SOLUTION_MATCH]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_MATCH]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]) && $solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD]) && $solutions[DPI::SOLUTION_UPLOAD]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD_RENEWALS]) && $solutions[DPI::SOLUTION_UPLOAD_RENEWALS]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD_RENEWALS]->setSolutionToDeal($dealContainer);
					break;
			}

			$dealContainer->relatedDealsManager->setDealAsChecked();
		}

		return $this;
	}

	public function needChangeExplanation(DealContainer $dealContainer, $userId, $shareAdvisorId, $stateId): bool
	{
		return $dealContainer->getIsShared()
			&& EOLicenseChecker::check($dealContainer->parsed_deal_class, $userId, $shareAdvisorId, $stateId, true);
	}

}