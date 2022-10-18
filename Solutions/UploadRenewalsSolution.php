<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

class UploadRenewalsSolution extends UploadInsuranceAndRenewalsCombinedSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD_RENEWALS;

	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		if ($parsedDeal->parsed_deal_class != Renewals::class || $deal->getAmount() == 0) {
			return false;
		}

		if ($parser
			&& !$parser->getEoLicenseChecks(
				$parsedDeal->parsed_deal_class ?? '',
				$deal->getUserId(),
				$deal->getShareAdvisorId(),
				$deal->getStateId()
			)
		) {
			return false;
		}

		return ($parsedDeal->parsed_deal_class === get_class($deal)
			&& (get_class($deal) !== InsuranceDeals::class || !$deal->getAttribute('parent_deal_id')));
	}

	/**
	 * @return string
	 */
	public function getLabel(): string
	{
		return 'Upload record as a <span class="font_size_16">New Renewal</span> deal';
	}
}