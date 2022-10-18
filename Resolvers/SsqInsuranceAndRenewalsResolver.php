<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\DealContainer;

use common\models\CommissionsImport\Parsers\DocumentParserInterface as DPI;
use common\models\CommissionsImport\Solutions\SsqUploadToAdvisorSolution;
use common\models\Renewals;
use frontend\models\Company;

/**
 * Class SsqInsuranceAndRenewalsResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class SsqInsuranceAndRenewalsResolver extends InsuranceAndRenewalsCombinedWithCodeResolver
{
	/**
	 * @inheritDoc
	 */
	protected function getContractingCompanyId(): int
	{
		return Company::SSQ_COMPANY_ID;
	}

	/**
	 * @inheritDoc
	 */
	protected function getResolverCompanies(): array
	{
		return [Company::SSQ_COMPANY_ID];
	}

	/**
	 * @param DealContainer $dealContainer
	 */
	protected function searchDealErrorExplanation(DealContainer $dealContainer): void
	{
		parent::searchDealErrorExplanation($dealContainer);

		if (
			$dealContainer->parsed_deal_class !== Renewals::class
			&& ($dealContainer->getIsShared() && empty($dealContainer->relatedDealsManager->getDeals()))
		) {
			$dealContainer->setExplanationToDeal('The split deal not found in the system');
		}
	}

	protected function getSolutionClassNames(): array
	{
		return array_merge(parent::getSolutionClassNames(), [
			DPI::SOLUTION_UPLOAD_TO_ADVISOR => SsqUploadToAdvisorSolution::class,
		]);
	}

}