<?php

namespace common\models\CommissionsImport;

use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Solutions\BaseSolution;

use frontend\models\DealInterface;

/**
 * Class RelatedDealContainer
 *
 * @package common\models\CommissionsImport
 */
class RelatedDealContainer
{
	/**
	 * @var DealInterface
	 */
	protected $deal;

	/**
	 * @var BaseSolution[]
	 */
	protected $solutions;

	/**
	 * @var bool
	 */
	protected $checked = false;

	/**
	 * @param DealInterface $deal
	 *
	 * @return RelatedDealContainer
	 */
	public function setDeal(DealInterface $deal): RelatedDealContainer
	{
		$this->deal = $deal;

		return $this;
	}

	/**
	 * @param BaseSolution[] $solutions
	 *
	 * @return RelatedDealContainer
	 */
	public function setSolutions(array $solutions): RelatedDealContainer
	{
		$this->solutions = $solutions;

		return $this;
	}

	/**
	 * @param BaseSolution $solution
	 */
	public function addSolution(BaseSolution $solution)
	{
		$this->solutions[] = $solution;
	}

	/**
	 * @return BaseSolution[]
	 */
	public function getSolutions(): array
	{
		return $this->solutions;
	}

	/**
	 * @return DealInterface
	 */
	public function getDeal(): DealInterface
	{
		return $this->deal;
	}

	/**
	 * @param bool $checked
	 *
	 * @return RelatedDealContainer
	 */
	public function setChecked(bool $checked): RelatedDealContainer
	{
		$this->checked = $checked;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isChecked(): bool
	{
		return $this->checked;
	}

	/**
	 * @return string
	 */
	public function getActiveSolutionsJson(): string
	{
		$activeSolutions = [];
		foreach ($this->solutions as $solution) {
			$activeSolutions[] = $solution::SOLUTION;
		}

		return json_encode($activeSolutions);
	}

	/**
	 * @return string
	 */
	public function getPrioritySolution(): string
	{
		if (!$this->solutions) {
			return DocumentParserInterface::SOLUTION_UNMATCH;
		}

		return $this->solutions[array_key_first($this->solutions)]::SOLUTION;
	}

}
