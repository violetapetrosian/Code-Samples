<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Solution to update amount of existing deal.
 *
 * Class MatchSolution
 *
 * @package Solutions
 */
class MatchSolution extends BaseSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_MATCH;

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		return !$deal->hasAgentWithoutEoOrLicense
			&& $deal->relatedDealsManager->existsDealsWithSolution(self::SOLUTION);
	}

	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		if ($parsedDeal->commission < 0 && $deal->getAmount() <= 0) {
			$parsedDeal->setExplanationToDeal('The Amount of the Chargeback exceeds the Amount of the Deal.');

			return false;
		}

		return $parsedDeal->parsed_deal_class != Renewals::class
			&& $deal->getAmount() == 0
			&& $parsedDeal->commission > 0;
	}

	/**
	 * Updates existing not paid deal using policy number and commission amount
	 * Adds errors
	 *
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function applySolution(DealContainer $deal): bool
	{
		/**
		 * @var DealInterface $matchedDeal
		 */
		$matchedDeal = $deal->parsed_deal_class::findOne($deal->deal_id);

		if (!$matchedDeal) {
			$deal->addError('parser', 'Matched deal not found.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		/** TODO: Add method hasChargeBack to DealInterface */
		if ($matchedDeal->hasPaidMember()
			|| (method_exists($deal, 'hasChargeBack') && $deal->hasChargeBack())
		) {
			$deal->addError('parser', 'Matched deal already paid or has charge back.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		if (get_class($matchedDeal) === InsuranceDeals::class) {
			$_GET['type'] = $matchedDeal->type;
		}

		if (!$matchedDeal->matchImportedDeal($deal)) {
			$deal->addError('parser', 'Error occurred when matching deal.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		$deal->matched_deal_class = $deal->parsed_deal_class;
		$deal->status = $deal::STATUS_APPLIED;

		return $deal->save();
	}
}