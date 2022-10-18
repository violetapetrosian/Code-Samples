<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\EOLicenseChecker;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Solution to add new deal relevant to matched deal from db.
 *
 * Class UploadSolution
 *
 * @package Solutions
 */
class UploadSolution extends BaseSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD;

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		return !$deal->hasAgentWithoutEoOrLicense
			&& $deal->commission != 0
			&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_MATCH)
			&& $deal->relatedDealsManager->existsDealsWithSolution(static::SOLUTION);
	}

	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		if ($parsedDeal->parsed_deal_class == Renewals::class) {
			return false;
		}

		if ($parsedDeal->commission < 0 && $deal->getAmount() <= 0) {
			$parsedDeal->setExplanationToDeal('The Amount of the Chargeback exceeds the Amount of the Deal.');
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
	 * Adds new deal using existing paid deal as template.
	 * Adds errors
	 *
	 * @param DealContainer $deal
	 *
	 * @return bool
	 * @throws \yii\db\Exception
	 */
	public function applySolution(DealContainer $deal): bool
	{
		/**
		 * @var DealInterface $matchedDeal
		 */
		$matchedDeal = $deal->matched_deal_class::findOne($deal->deal_id);
		if (!$matchedDeal) {
			$deal->addError('parser', 'Matched deal not found.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}

		if ($matchedDeal instanceof InsuranceDeals) {
			$_GET['type'] = $matchedDeal->type;
			if ($matchedDeal->parent_deal_id) {
				$matchedDeal = InsuranceDeals::findOne($matchedDeal->parent_deal_id);
			}
		}

		$newDealId = $matchedDeal->uploadImportedDeal(
			$deal,
			$deal->solution === DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL
		);

		if (!$newDealId) {
			$deal->addError('parser', 'Error occurred when inserting new deal.');

			return false;
		}

		$deal->created_deal_id = $newDealId;
		$deal->created_deal_class = $deal->matched_deal_class;
		$deal->status = $deal::STATUS_APPLIED;

		return $deal->save();
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