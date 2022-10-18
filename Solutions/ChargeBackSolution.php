<?php

namespace common\models\CommissionsImport\Solutions;

use common\behaviors\StatusBehavior;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Interfaces\SimpleChargeBackDealInterface;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Class ChargeBackSolution
 *
 * @package common\models\CommissionsImport\Solutions
 */
class ChargeBackSolution extends BaseSolution
{
	public const SOLUTION = DocumentParserInterface::SOLUTION_CHARGE_BACK;

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		return $deal->commission < 0
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
		if ($parsedDeal->parsed_deal_class === get_class($deal)
			&& $parsedDeal->commission < 0
			&& $deal instanceof SimpleChargeBackDealInterface
			&& $deal->isPossibleChargeBack()
			&& $deal->getAmount() > 0
		) {
			return true;
		}

		return false;
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function applySolution(DealContainer $deal): bool
	{
		/**
		 * @var SimpleChargeBackDealInterface $matchedDeal
		 */
		if ($deal->matched_deal_class && $deal->matched_deal_class !== $deal->parsed_deal_class) {
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

		if (!$matchedDeal->isPossibleChargeBack()) {
			$deal->addError('parser', 'Charge back is impossible.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		if ($matchedDeal instanceof InsuranceDeals) {
			$_GET['type'] = $matchedDeal->type;
		}

		if (!$matchedDeal->chargeBack($deal->commission * -1)) {
			$deal->addError('parser', 'Error occurred when charging back.');
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