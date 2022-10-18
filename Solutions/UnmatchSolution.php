<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\RelatedDealsManager;
use frontend\models\DealInterface;

/**
 * Class UnmatchSolution
 *
 * @package Solutions
 */
class UnmatchSolution extends BaseSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UNMATCH;

	const UNMATCH_ADVISOR_NAME = 'Experior Financial Group Inc.';
	const UNMATCH_ADVISOR_CODE = 107000;

	/**
	 * @inheritDoc
	 */
	public function isFit(DealContainer $deal): bool
	{
		if ($deal->advisor_name == self::UNMATCH_ADVISOR_NAME
			|| $deal->contract_code == self::UNMATCH_ADVISOR_CODE
		) {
			$deal->setExplanationToDeal('Only on MGA statement - Do not pay');
			return true;
		}

		if ($deal->commission < 0
			&& count($deal->relatedDealsManager->getDeals())
			&& RelatedDealsManager::checkAllDealsAreUnpaid($deal->relatedDealsManager->getDeals())
		) {
			$deal->excludeFromManualMatch = true;
			$deal->additional_data['info'] = 'The Amount of the Chargeback exceeds the Amount of the Deal.';
			$deal->setExplanationToDeal('The amount of the deal is less than “0” and the relevant deals found are not paid - check and reach out to provider if needed');
			return true;
		}

		if ($deal->commission < 0
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_CHARGE_BACK)
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL)
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL)
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_UPLOAD)
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_UPLOAD_RENEWALS)
		) {
			$deal->setExplanationToDeal('No deals found for matching');
			return true;
		}

		if (floatval($deal->commission) == 0) {
			$deal->setExplanationToDeal('Deal equals zero');
			return true;
		}

		if (!count($deal->relatedDealsManager->getDeals())) {
			$deal->setExplanationToDeal('No deals found for matching');
			return true;
		}

		// if deal has zero commission - unmatch it.
		if ($deal->forceToUnmatch
			|| $deal->skipToUnmatchReport
			|| ($deal->hasAgentWithoutEoOrLicense && !$deal->getIsShared())
		) {
			return true;
		}
		return false;
	}

	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		return true;
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function applySolution(DealContainer $deal): bool
	{
		$deal->solution = static::SOLUTION;
		$deal->status = $deal::STATUS_APPLIED;

		return $deal->save(false);
	}

	/**
	 * @param DealContainer $deal
	 * @param array         $data
	 * @param bool          $onlyCounters
	 */
	public function updateSolutionToDeal(DealContainer $deal, array $data = [], bool $onlyCounters = false): void
	{
		$deal->solution = $data['solution'];
		if (!$onlyCounters) {
			$deal->status = $deal::STATUS_APPLIED;
		}
	}

}