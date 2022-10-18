<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\helpers\DealQueryHelper;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use common\models\User;
use frontend\models\Deal;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Class UploadGmsGreenShieldSolution
 *
 * @package common\models\CommissionsImport\Solutions
 */
class UploadInsuranceAndRenewalsCombinedSolution extends UploadSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD;

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		if ($deal->parsed_deal_class === Renewals::class) {
			return !$deal->hasAgentWithoutEoOrLicense
				&& !$deal->relatedDealsManager->existsDealsWithSolution(DocumentParserInterface::SOLUTION_MATCH)
				&& $deal->relatedDealsManager->existsDealsWithSolution(static::SOLUTION);
		}

		return parent::isFit($deal);
	}

	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		$policyCheck = DealQueryHelper::comparePolicyNumber($parsedDeal->policy_number, $deal->getClientPolicy());

		if (
			!$policyCheck
			&& $deal->isPaid()
		) {
			return false;
		}

		return parent::isFitRelatedDeal($parsedDeal, $deal, $parser);
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 * @throws \yii\db\Exception
	 */
	public function applySolution(DealContainer $deal): bool
	{
		if (intval($deal->deal_id) && $deal->parsed_deal_class !== Renewals::class) {

			return parent::applySolution($deal);
		}

		$advisor = $deal->getSavedAgent();

		if (!$advisor) {
			/**
			 * @var Deal $matchedDeal
			 */
			if (!$deal->deal_id || !$matchedDeal = $deal->matched_deal_class::findOne($deal->deal_id)) {
				$deal->addError('parser', 'Matched deal not found.');
				$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

				return false;
			}

			$advisor = $matchedDeal->user;

			if (!$advisor) {
				$deal->addError('parser', 'Advisor not found.');
				$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

				return false;
			}
		}

		try {
			$advisorId = $advisor['user_id'] ?? null;

			if (!$advisorId) {
				throw new \Exception('Agent not found');
			}

			$newDeal = new Renewals();
			$newDeal->user_id = $advisorId;
			$newDeal->company_id = current($deal->getParser()->getCompanies())->id;
			$newDeal->client_name = $deal->client_name;
			$newDeal->gross_amount = $deal->commission;

			if ($newDeal->save(false) && $newDeal->generateMembers()) {
				$deal->created_deal_id = $newDeal->id;
				$deal->created_deal_class = Renewals::class;
				$deal->status = $deal::STATUS_APPLIED;

				return $deal->save();
			}
			$deal->addError('parser', 'Error occurred when inserting new deal.');
		} catch (\Exception $e) {
			$deal->addError(
				'parser', 'Error occurred when inserting new deal (' . $e->getMessage() . ').'
			);
		}

		return false;
	}

	/**
	 * @return string
	 */
	public function getLabel(): string
	{
		return 'Upload record as <span class="font_size_16">New Deal</span>';
	}

}