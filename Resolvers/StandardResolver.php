<?php

namespace common\models\CommissionsImport\Resolvers;

use common\behaviors\StatusBehavior;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\EOLicenseChecker;
use common\models\CommissionsImport\Parsers\DocumentParserInterface as DPI;
use common\models\CommissionsImport\ProgressDealsBar;
use common\models\CommissionsImport\RelatedDealsManager;
use common\models\CommissionsImport\Solutions\ChargeBackSolution;
use common\models\CommissionsImport\Solutions\MatchSolution;
use common\models\CommissionsImport\Solutions\ReinstatementSolution;
use common\models\CommissionsImport\Solutions\UnmatchSolution;
use common\models\CommissionsImport\Solutions\UploadRenewalsSolution;
use common\models\CommissionsImport\Solutions\UploadRenewalSubDealSolution;
use common\models\CommissionsImport\Solutions\UploadSolution;
use common\models\CommissionsImport\Solutions\UploadSubDealSolution;
use common\models\CommissionsImport\Solutions\UploadToAdvisorSolution;
use common\models\Renewals;
use common\models\User;

use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Class StandardResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class StandardResolver extends Resolver
{
	/**
	 * @inheritDoc
	 */
	final public function matchDeals(): void
	{
		try {
			$this->findRelevantDeals();
			$this->filterRelevantDeals();

			$this->findSolutions();
			$this->findRelevantAdvisors();

			$this->saveDeals(DealContainer::STATUS_PENDING_ADMIN);
		} catch (\Exception $e) {
			$this->saveDeals(DealContainer::STATUS_ERROR, $e->getMessage());
		}
	}

	/**
	 * @inheritDoc
	 */
	final public function setRelatedManagerToDeal(DealContainer $dealContainer, array $relatedDeals): void
	{
		$relatedDealsManager = new RelatedDealsManager();
		$relatedDealsManager->setParsedDeal($dealContainer)
			->setDeals($relatedDeals)
			->setParser($this->parser)
			->setSolutions($this->getSolutions());

		$dealContainer->relatedDealsManager = $relatedDealsManager;
	}

	/**
	 * @return array
	 */
	public function getSolutionsCountersLabels(): array
	{
		return array_intersect_key(
			DPI::SOLUTION_COUNTER_LABELS,
			$this->getSolutionClassNames()
		);
	}

	/**
	 * @return array
	 */
	protected function getSolutionClassNames(): array
	{
		return [
			DPI::SOLUTION_MATCH => MatchSolution::class,
			DPI::SOLUTION_CHARGE_BACK => ChargeBackSolution::class,
			DPI::SOLUTION_REINSTATEMENT => ReinstatementSolution::class,
			DPI::SOLUTION_UPLOAD_AS_SUB_DEAL => UploadSubDealSolution::class,
			DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL => UploadRenewalSubDealSolution::class,
			DPI::SOLUTION_UPLOAD_RENEWALS => UploadRenewalsSolution::class,
			DPI::SOLUTION_UPLOAD => UploadSolution::class,
			DPI::SOLUTION_UPLOAD_TO_ADVISOR => UploadToAdvisorSolution::class,
			DPI::SOLUTION_UNMATCH => UnmatchSolution::class,
		];
	}

	/**
	 * Tries to find relevant deal.
	 */
	protected function findRelevantDeals()
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as &$dealContainer) {
			$baseQuery = $this->getBaseQueryForRelevantDeals($dealContainer);
			$amountAttribute = $this->getBaseDealFindClass($dealContainer)::getAmountAttribute();

			$query = clone $baseQuery;
			$relatedDealsQuery = $query
				->policyNumber($dealContainer->policy_number, $amountAttribute, false)
				->clientNameIfNotPolicy($dealContainer->client_name);

			if (!$relatedDealsQuery->count()) {
				$query = clone $baseQuery;
				$relatedDealsQuery = $query->clientName($dealContainer->client_name)
					->policyNumber($dealContainer->policy_number, $amountAttribute, true);

				if (!$relatedDealsQuery->count()) {
					$dealContainer->setExplanationToDeal('No relevant Client’s Name found in the system');
				}
			}

			$relatedDealsCount = $relatedDealsQuery->count();
			$relatedDealsQuery = $relatedDealsQuery->advisorShareUser($dealContainer);
			$shareRelatedDealsCount = $relatedDealsQuery->count();

			if ($relatedDealsCount && !$shareRelatedDealsCount) {
				$dealContainer->forceToUnmatch = true;
				$dealContainer->skipToUnmatchReport = true;
				if ($dealContainer->getIsShared()) {
					$dealContainer->setExplanationToDeal('The deal in the system is not split - Check and adjust accordingly');
				} else {
					$dealContainer->setExplanationToDeal('The deal in the system is split - Check and adjust accordingly');

				}
			}

			$relatedDeals = $relatedDealsQuery->all();

			$this->setRelatedManagerToDeal($dealContainer, $relatedDeals);
		}
	}

	protected function filterRelevantDeals()
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as $key => $dealContainer) {
			foreach ($dealContainer->relatedDealsManager->getDeals() as $key2 => $deal) {
				if ($this->skipIncorrectDeal($dealContainer, $deal)) {
					$dealContainer->relatedDealsManager->unsetDeal($key2);
					continue;
				}

				$userId = $deal->getUserId();
				$shareAdvisorId = $deal->getShareAdvisorId();

				if ($this->isUserBlockedForCompany([$userId, $shareAdvisorId], $deal->company_id)) {
					$dealContainer->hasDealWithoutBlockedCompany = $dealContainer->hasDealWithoutBlockedCompany ?? false;
					$dealContainer->relatedDealsManager->unsetDeal($key2);
					continue;
				} else {
					$dealContainer->hasDealWithoutBlockedCompany = true;
				}

				$this->skipDealWithoutNbt($dealContainer, $deal, $key2);
				if (
					($dealContainer->commission > 0 && $deal->getAmount() == 0)
					|| $dealContainer->commission < 0 && $deal->getAmount() > 0
					|| (new ReinstatementSolution())->isFitRelatedDeal($dealContainer, $deal)
				) {
					continue;
				}

				$stateId = $deal->hasAttribute('state_id') ? $deal->state_id : null;
				$dealContainer->hasAgentWithoutEoOrLicense
					= !$this->parser->getEoLicenseChecks($dealContainer->parsed_deal_class, $userId, $shareAdvisorId, $stateId)
					&& !$dealContainer->getIsShared();

				if ($dealContainer->hasAgentWithoutEoOrLicense) {
					$dealContainer->withoutEoOrLicenseExplanation = EOLicenseChecker::getWithoutEoOrLicenseExplanation($dealContainer->parsed_deal_class);
					$dealContainer->relatedDealsManager->unsetDeal($key2);
				}
			}

			if ($dealContainer->relatedDealsManager->getDeals()) {
				$dealContainer->hasAgentWithoutEoOrLicense = false;
				$dealContainer->withoutEoOrLicenseExplanation = '';
			}

			$this->searchDealErrorExplanation($dealContainer);
		}
	}

	/**
	 * Tries to find correct advisor based on parsed deal
	 */
	public function findRelevantAdvisors(): void
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as $dealContainer) {
			if ($dealContainer->solution !== DPI::SOLUTION_UNMATCH
				|| $dealContainer->advisor_name === 'Experior'
				|| $dealContainer->forceToUnmatch
			) {
				continue;
			}

			if (!$dealContainer->relevantAdvisors) {
				$activeAdvisors = $this->searchAdvisorsByCondition(
					$dealContainer,
					[
						'status' => [
							StatusBehavior::STATUS_ACTIVE,
						],
					]
				);

				if (!$activeAdvisors) {
					$activeAdvisors = $this->searchAdvisorsByCondition(
						$dealContainer,
						[]
					);
				}

				if ($activeAdvisors) {
					$dealContainer->relevantAdvisors = array_filter($activeAdvisors, function ($relevantAdvisor) use ($dealContainer) {
						return $this->parser->getEoLicenseChecks(
							$dealContainer->parsed_deal_class,
							$relevantAdvisor['user_id'],
							null,
							$relevantAdvisor['state_id']
						) || $dealContainer->getIsShared();
					});

					if (empty($dealContainer->relevantAdvisors)) {
						$dealContainer->hasAgentWithoutEoOrLicense = true;
						$dealContainer->withoutEoOrLicenseExplanation = EOLicenseChecker::getWithoutEoOrLicenseExplanation($dealContainer->parsed_deal_class);
					}
				}
			}

			if ($this->getSolutions()[DPI::SOLUTION_UPLOAD_TO_ADVISOR]->isFit($dealContainer)) {
				$this->getSolutions()[DPI::SOLUTION_UPLOAD_TO_ADVISOR]
					->setSolutionToDeal($dealContainer);
			}

			$this->searchDealErrorExplanation($dealContainer);
		}
	}

	/**
	 * @param DealContainer $dealContainer
	 * @param array         $conditions
	 *
	 * @return array|\yii\db\ActiveRecord[]
	 */
	protected function searchAdvisorsByCondition(DealContainer $dealContainer, array $conditions = [])
	{
		$user = ($dealContainer->additional_data['user'] ?? null);
		$sharedUser = ($dealContainer->additional_data['sharedUser'] ?? null);

		switch (true) {
			case $user && $sharedUser :
				return [$user, $sharedUser];
			case $user && !$sharedUser :
				return [$user];
			case !$user && $sharedUser :
				return [$sharedUser];
		}

		$fsCode = $dealContainer->additional_data['fsCode'] ?? null;

		$query = User::find();

		if ($fsCode) {
			$query->andWhere(['advisor_fs_code_number' => $fsCode]);
		} else {
			$query->andWhere($this->getPriorityUserCondition($dealContainer));
		}

		if (!empty($conditions)) {
			$query->andWhere($conditions);
		}

		return $query->all();
	}

	/**
	 * @param DealContainer $dealContainer
	 *
	 * @return string[]
	 */
	protected function getPriorityUserCondition(DealContainer $dealContainer): array
	{
		return $dealContainer->getRegUserNameCondition();
	}

	/**
	 * Pre-founds solutions before user action
	 *
	 * @return $this
	 */
	public function findSolutions(): Resolver
	{
		$deals = $this->getProcessingDealContainers();

		foreach ($deals as $key => $dealContainer) {
			$dealContainer->relatedDealsManager->findPossibleSolutionsForRelatedDeals();

			$solutions = $this->getSolutions();

			switch (true) {
				default :
				case isset($solutions[DPI::SOLUTION_UNMATCH]) && $solutions[DPI::SOLUTION_UNMATCH]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UNMATCH]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_CHARGE_BACK]) && $solutions[DPI::SOLUTION_CHARGE_BACK]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_CHARGE_BACK]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_REINSTATEMENT]) && $solutions[DPI::SOLUTION_REINSTATEMENT]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_REINSTATEMENT]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_MATCH]) && $solutions[DPI::SOLUTION_MATCH]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_MATCH]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_UPLOAD_AS_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case $solutions[DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD]) && $solutions[DPI::SOLUTION_UPLOAD]->isFit($dealContainer) :
					$solutions[DPI::SOLUTION_UPLOAD]->setSolutionToDeal($dealContainer);
					break;
				case isset($solutions[DPI::SOLUTION_UPLOAD_RENEWALS]) && $solutions[DPI::SOLUTION_UPLOAD_RENEWALS]->isFit($dealContainer):
					$solutions[DPI::SOLUTION_UPLOAD_RENEWALS]->setSolutionToDeal($dealContainer);
					break;
			}

			$dealContainer->relatedDealsManager->setDealAsChecked();
		}

		return $this;
	}

	/**
	 * @param array $userSolutions
	 * @param bool  $onlyCounters
	 *
	 * @return bool
	 */
	final public function updateSolutions(array $userSolutions, bool $onlyCounters): bool
	{
		$solutions = $this->getSolutions();

		$deals = $this->getProcessingDealContainers();

		foreach ($deals as &$deal) {
			foreach ($userSolutions as $deal_id => $solution) {
				if ($deal->getPrimaryKey() == $deal_id) {
					$solutions[$solution['solution']]->updateSolutionToDeal($deal, $solution, $onlyCounters);
					$deal->save();
				}
			}
		}

		return !$this->parser->hasErrors();
	}

	/**
	 * @param DealContainer $dealContainer
	 */
	protected function searchDealErrorExplanation(DealContainer $dealContainer): void
	{
		if ($dealContainer->hasDealWithoutBlockedCompany === false) {
			$dealContainer->setExplanationToDeal('This Agent is blocked from the deal creation. ');
		} elseif (trim($dealContainer->advisor_name)) {
			if ($dealContainer->hasAgentWithoutEoOrLicense) {
				$dealContainer->setExplanationToDeal($dealContainer->withoutEoOrLicenseExplanation);
			}
		} elseif (strpos(strtolower($dealContainer->industrialAlliancePlan), 'ex') !== false
			&& empty($dealContainer->relatedDealsManager->getDeals()))
		{
			$dealContainer->setExplanationToDeal('No deals found for matching');
		}
	}

	/**
	 * @param DealContainer $dealContainer
	 * @param               $deal
	 *
	 * @return bool
	 */
	protected function skipIncorrectDeal(DealContainer $dealContainer, $deal): bool
	{
		if (!($deal instanceof InsuranceDeals)) {
			return false;
		}

		if ($dealContainer->getIsShared() != $deal->is_shared) {
			return true;
		}

		// @todo @expfin-5034 Remove this condition
		if ($deal->parent_deal_id && !$deal->parentDeal->hasPaidMember()) {
			return true;
		}

		return false;
	}

	/**
	 * @param DealContainer $dealContainer
	 * @param               $deal
	 * @param               $key2
	 */
	protected function skipDealWithoutNbt(DealContainer $dealContainer, $deal, $key2): void
	{
		if (get_class($deal) != Renewals::class && !$deal->existNbtDeal($deal->getPrimaryKey())) {
			$dealContainer->relatedDealsManager->unsetDeal($key2);
		}
	}

	protected function saveDeals(?string $status = null, ?string $errorMessage = null)
	{
		$this->parser->saveDeals($status, $errorMessage);
	}

	protected function getBaseQueryForRelevantDeals(DealContainer $dealContainer)
	{
		$baseQuery = $this->getBaseDealFindClass($dealContainer)::find()
			->alias('base_deal')
			->select(['base_deal.*']);
		$baseQuery = $this->parser->additionalQuerySettings($baseQuery);

		$baseQuery->andWhere(['company_id' => array_keys($this->parser->getCompanies())])
			->advisorName($dealContainer)
			->orderBy(['id' => SORT_DESC])
			->limit(self::RELATED_DEALS_LIMIT);

		if (!$baseQuery->count()) {
			$dealContainer->setExplanationToDeal("No relevant Agent’s Name found in the system");
		}

		if ($this->getBaseDealFindClass($dealContainer)::hasColumnInDealTable('parent_deal_id')) {
			$baseQuery->andWhere(['parent_deal_id' => null]);
		}

		return $baseQuery;
	}

	/**
	 * Returns the class of deals in which the priority search for connected deals will be performed.
	 *
	 * @param DealContainer $dealContainer
	 *
	 * @return DealInterface|string
	 */
	protected function getBaseDealFindClass(DealContainer $dealContainer)
	{
		if ($dealContainer->parsed_deal_class === Renewals::class) {
			return InsuranceDeals::class;
		}

		return $dealContainer->parsed_deal_class;
	}

	public function needChangeExplanation(DealContainer $dealContainer, $userId, $shareAdvisorId, $stateId): bool
	{
		return false;
	}

}
