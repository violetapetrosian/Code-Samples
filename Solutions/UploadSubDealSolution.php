<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\EOLicenseChecker;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

class UploadSubDealSolution extends UploadSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL;

	public function isFit(DealContainer $deal): bool
	{
		return $deal->commission != 0
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_MATCH)
			&& $deal->relatedDealsManager->existsDealsWithSolution(static::SOLUTION);
	}

	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		if ($parsedDeal->commission < 0 && $deal->getAmount() <= 0) {
			$parsedDeal->setExplanationToDeal('The Amount of the Chargeback exceeds the Amount of the Deal.');

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

		if ($parsedDeal->parsed_deal_class == Renewals::class && $deal->getAmount() == 0) {
			return false;
		}

		return in_array($parsedDeal->parsed_deal_class, [InsuranceDeals::class, Renewals::class])
			&& get_class($deal) === InsuranceDeals::class;
	}

	/**
	 * @inheritDoc
	 */
	public function updateSolutionToDeal(DealContainer $deal, array $data = [], bool $onlyCounters = false): void
	{
		parent::updateSolutionToDeal($deal, $data, $onlyCounters);

		$deal->matched_deal_class = InsuranceDeals::class;
	}
}