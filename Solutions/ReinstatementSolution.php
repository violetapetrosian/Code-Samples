<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;

use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Class ReinstatementSolution
 *
 * @package common\models\CommissionsImport\Solutions
 */
class ReinstatementSolution extends BaseSolution
{
	public const SOLUTION = DocumentParserInterface::SOLUTION_REINSTATEMENT;

	/**
	 * @inheritDoc
	 */
	public function isFit(DealContainer $deal): bool
	{
		return $deal->commission > 0
			&& $deal->relatedDealsManager->existsDealsWithSolution(self::SOLUTION);
	}

	/**
	 * @inheritDoc
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		return get_class($deal) == InsuranceDeals::class
			&& $parsedDeal->parsed_deal_class === InsuranceDeals::class
			&& $deal->isPossibleReinstatement()
			&& $parsedDeal->commission > 0;
	}
	/**
	 * @inheritDoc
	 */
	public function applySolution(DealContainer $deal): bool
	{
		if (!$deal->matched_deal_class
			|| $deal->matched_deal_class !== $deal->parsed_deal_class
			|| $deal->matched_deal_class != InsuranceDeals::class
		) {
			$deal->addError('parser', 'Matched class should be equal to parsed deal class.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}
		$matchedDeal = $deal->parsed_deal_class::findOne($deal->deal_id);
		if (!$matchedDeal) {
			$deal->addError('parser', 'Matched deal not found.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		if (!$matchedDeal->lastChargeBack || !empty($this->lastChargeBack->reinstatement_amount)) {
			$deal->addError('parser', 'Reinstatement is impossible.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		$matchedDeal->reinstatementAmount = $deal->commission;

		if (!$matchedDeal->reinstatement()) {
			$deal->addError('parser', 'Error occurred when reinstating.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		$deal->solution = static::SOLUTION;
		$deal->status = $deal::STATUS_APPLIED;

		return $deal->save(false);
	}

	/**
	 * @inheritDoc
	 */
	public function updateSolutionToDeal(DealContainer $deal, array $data = [], bool $onlyCounters = false): void
	{
		parent::updateSolutionToDeal($deal, $data, $onlyCounters);

		$deal->matched_deal_class = $deal->parsed_deal_class;
	}

}