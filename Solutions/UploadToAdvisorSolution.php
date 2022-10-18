<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use common\models\User;
use frontend\models\DealInterface;

class UploadToAdvisorSolution extends BaseSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD_TO_ADVISOR;

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		if ($deal->forceToUnmatch) {
			return false;
		}

		return $deal->commission > 0
			&& !empty($deal->relevantAdvisors)
			&& !$deal->hasAgentWithoutEoOrLicense
			&& $deal->hasDealWithoutBlockedCompany !== false;
	}

	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		return false;
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function applySolution(DealContainer $deal): bool
	{
		$newDealId = $deal->parsed_deal_class::uploadDealToAdvisor($deal);

		if (!$newDealId) {
			$deal->addError('parser', 'Error occurred when inserting new deal.');

			return false;
		}

		$deal->created_deal_id = $newDealId;
		$deal->created_deal_class = $deal->parsed_deal_class;
		$deal->status = $deal::STATUS_APPLIED;

		return $deal->save();
	}

	/**
	 * @param DealContainer $deal
	 * @param array         $data
	 * @param bool          $onlyCounters
	 */
	public function updateSolutionToDeal(DealContainer $deal, array $data = [], bool $onlyCounters = false): void
	{
		$deal->solution = $data['solution'];

		if (empty($data['matched_advisor_id'])) {
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;
		}

		$deal->matched_advisor_id = $data['matched_advisor_id'] ?? null;
		$deal->matched_share_advisor_id = $data['matched_share_advisor_id'] ?? null;

		$deal->additional_data['deal_type'] = $data['deal_type'] ?? null;
		$deal->additional_data['company_id'] = $data['company_id'] ?? null;

		if (!$onlyCounters) {
			$users = User::find()->where(['user_id' => [$deal->matched_advisor_id, $deal->matched_share_advisor_id]])
				->indexBy(['user_id'])->all();

			$deal->status = $deal::STATUS_PENDING;

			$deal->advisor_name = $deal->matched_advisor_id && !empty($users[$deal->matched_advisor_id])
				? $users[$deal->matched_advisor_id]->getLegalFullName()
				: $deal->advisor_name;

			$deal->share_advisor_name = $deal->matched_share_advisor_id && !empty($users[$deal->matched_share_advisor_id])
				? $users[$deal->matched_share_advisor_id]->getLegalFullName()
				: $deal->share_advisor_name;
		}
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getInputHtml(DealContainer $deal): string
	{
		return '';
	}

}