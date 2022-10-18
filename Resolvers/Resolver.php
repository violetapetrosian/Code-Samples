<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Solutions\BaseSolution;
use common\models\UserBlockedCompany;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * Class Resolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
abstract class Resolver
{
	const RELATED_DEALS_LIMIT = 5;

	/**
	 * @var DocumentParserInterface
	 */
	protected $parser;

	/**
	 * @var BaseSolution[]
	 */
	protected $solutions = [];

	/**
	 * @var array
	 */
	protected $blockedCompanyUsers = [];

	/**
	 * @var DealContainer[]
	 */
	protected $dealContainers = [];

	/**
	 * @var DealContainer[]
	 */
	protected $updatedDealContainers = [];

	/**
	 * Resolver constructor.
	 *
	 * @param DocumentParserInterface $parser
	 */
	public function __construct(DocumentParserInterface $parser)
	{
		$this->parser = $parser;
	}

	/**
	 * Tries to found exact deal from database for each parsed deal.
	 * Adds right solution for each of them
	 */
	abstract public function matchDeals(): void;

	/**
	 * Initializes a manager for managing tally deals.
	 *
	 * @param DealContainer $dealContainer
	 * @param array         $relatedDeals
	 */
	abstract public function setRelatedManagerToDeal(DealContainer $dealContainer, array $relatedDeals): void;

	/**
	 * Looks for available solutions for deals.
	 */
	abstract public function findSolutions(): Resolver;

	/**
	 * Looks for agents for unlisted deals, and checks the ability to set the solution for creating a deal by agent.
	 */
	abstract public function findRelevantAdvisors(): void;

	/**
	 * Updates solution to parsed deals after user action
	 *
	 * @param array $userSolutions
	 * @param bool  $onlyCounters
	 *
	 * @return bool
	 */
	abstract public function updateSolutions(array $userSolutions, bool $onlyCounters): bool;

	/**
	 * Returns array of solutions 'solution_key' => 'solutionClass'
	 *
	 * @return array
	 */
	abstract protected function getSolutionClassNames(): array;

	/**
	 * @param array $dealContainers
	 *
	 * @return $this
	 */
	public function setDealContainers(array $dealContainers): Resolver
	{
		$this->dealContainers = $dealContainers;

		return $this;
	}

	/**
	 * @return DealContainer[]
	 */
	public function getProcessingDealContainers(): array
	{
		return $this->dealContainers ?: $this->parser->deals;
	}

	/**
	 * Returns array of solution instances 'solution_name' => Solution
	 *
	 * @return BaseSolution[]
	 */
	public function getSolutions(): array
	{
		if (!$this->solutions) {
			foreach ($this->getSolutionClassNames() as $solution => $className) {
				$this->solutions[$solution] = new $className();
			}
		}

		return $this->solutions;
	}

	/**
	 * Saves the current solution for the container.
	 */
	public function saveActualSolution(): void
	{
		foreach ($this->getProcessingDealContainers() as $dealContainer) {
			if ($dealContainer->getOldAttribute('solution') === $dealContainer->solution) continue;

			$dealContainer->save();

			$this->updatedDealContainers[] = $dealContainer;
		}
	}

	/**
	 * @return bool
	 */
	public function hasDealsUpdatedActualSolution(): bool
	{
		return boolval($this->updatedDealContainers);
	}

	/**
	 * @return bool
	 */
	public function applySolutions()
	{
		$query = $this->parser->getDeals(true)
			->where(['status' => DealContainer::STATUS_PENDING])
			->limit(100);

		$success = true;
		$solutions = $this->getSolutions();
		while ($deals = $query->all()) {
			foreach ($deals as $deal) {
				if (!$solutions[$deal->solution]->applySolution($deal)) {
					$deal->errors = $deal->getErrors();
					$deal->status = $deal::STATUS_ERROR;
					$deal->save(false);
					$success = false;
				}
			}
		}

		return $success;
	}

	/**
	 * @return DocumentParserInterface
	 */
	public function getParser(): DocumentParserInterface
	{
		return $this->parser;
	}

	/**
	 * @return array
	 */
	public function getPossibleSolutions(): array
	{
		return array_keys($this->getSolutionClassNames());
	}

	/**
	 * Returns radio buttons set
	 *
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getSolutionsInput(DealContainer $deal): string
	{
		$html = '';

		foreach ($this->getSolutions() as $solution) {
			$html .= $solution->getInputHtml($deal);
		}

		return $html;
	}

	/**
	 * @param     $advisorIds
	 * @param int $companyId
	 *
	 * @return bool
	 */
	public function isUserBlockedForCompany($advisorIds, int $companyId): bool
	{
		if (!$advisorIds || !$companyId) {
			return false;
		}

		if (!isset($this->blockedCompanyUsers[$companyId])) {
			$this->loadBlockedCompanyUsers($companyId);
		}

		$advisorIds = ArrayHelper::toArray($advisorIds);
		$isBlocked = false;

		foreach ($advisorIds as $advisorId) {
			$isBlocked = $isBlocked || in_array($advisorId, $this->blockedCompanyUsers[$companyId]);
		}

		return $isBlocked;
	}

	/**
	 * @param int $companyId
	 */
	protected function loadBlockedCompanyUsers(int $companyId): void
	{
		$this->blockedCompanyUsers[$companyId] = UserBlockedCompany::find()
			->select('user_id')
			->where(['company_id' => $companyId])
			->column();
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getManualMatchingSolutionsInputs(DealContainer $deal): string
	{
		return Yii::$app->controller->renderPartial(
			'_manualMatchingSolutions',
			['deal' => $deal, 'solutions' => $this->getSolutions(), 'parser' => $this->parser,]
		);
	}

}