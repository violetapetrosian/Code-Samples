<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\EOLicenseChecker;
use common\models\CommissionsImport\helpers\DealQueryHelper;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;

use common\models\UserContracting;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Class UploadRenewalSubDealRelevantToInsurance
 *
 * @package common\models\CommissionsImport\Solutions
 */
class UploadSubDealInsuranceRenewalCombinedSolution extends UploadSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_UPLOAD_AS_SUB_DEAL;

	public function isFit(DealContainer $deal): bool
	{
		return $deal->commission != 0
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
		$policyCheck = DealQueryHelper::comparePolicyNumber($parsedDeal->policy_number, $deal->getClientPolicy());
		if (
			$parsedDeal->parsed_deal_class == Renewals::class
			|| !$policyCheck
			|| ($policyCheck && !$deal->isPaid())
		) {
			return false;
		}

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

		return in_array($parsedDeal->parsed_deal_class, [InsuranceDeals::class, Renewals::class])
			&& get_class($deal) === InsuranceDeals::class;
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 * @throws \yii\db\Exception
	 */
	public function applySolution(DealContainer $deal): bool
	{
		if ($deal->parsed_deal_class === InsuranceDeals::class) {

			return parent::applySolution($deal);
		}

		/** @var InsuranceDeals $matchedDeal */
		$matchedDeal = InsuranceDeals::findOne($deal->deal_id);
		if (!$matchedDeal) {
			$deal->addError('parser', 'Matched deal not found.');
			$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

			return false;
		}
		$newDeal = new Renewals();

		$newDeal->company_id = $matchedDeal->company_id;
		$newDeal->client_name = $deal->client_name;
		$newDeal->gross_amount = $deal->commission;
		$newDeal->parent_deal_id = $matchedDeal->getPrimaryKey();

		if (!$deal->is_shared && $deal->getIsShared()) {
			$agent = UserContracting::findByCode($deal->contract_code, $matchedDeal->company_id);
			$newDeal->user_id = $agent->user_id ?? null;
		} else {
			$newDeal->user_id = $matchedDeal->user_id;
		}

		if ($newDeal->save(false) && $newDeal->generateMembers()) {
			$deal->created_deal_class = Renewals::class;
			$deal->created_deal_id = $newDeal->id;
			$deal->status = $deal::STATUS_APPLIED;

			$success = true;
			if (stripos($matchedDeal->client_policy, 'Pending') !== false) {
				$matchedDeal->client_policy = $deal->policy_number;

				$success = $matchedDeal->save(false);
			}

			return $deal->save() && $success;
		}

		$deal->addError('parser', 'Error occurred when inserting new deal.');
		$deal->solution = DocumentParserInterface::SOLUTION_UNMATCH;

		return false;
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