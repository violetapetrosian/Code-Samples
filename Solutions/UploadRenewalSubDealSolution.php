<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

class UploadRenewalSubDealSolution extends UploadSubDealInsuranceRenewalCombinedSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL;

	/**
	 * @inheritDoc
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		if ($parsedDeal->parsed_deal_class != Renewals::class || $deal->getAmount() == 0) {
			return false;
		}

		if ($parser
			&& $parsedDeal->commission > 0
			&& !$parser->getEoLicenseChecks(
				$parsedDeal->parsed_deal_class ?? '',
				$deal->getUserId(),
				$deal->getShareAdvisorId(),
				$deal->getStateId()
			)
		) {
			return false;
		}

		return get_class($deal) === InsuranceDeals::class;
	}

}