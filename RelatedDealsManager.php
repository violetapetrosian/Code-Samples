<?php

namespace common\models\CommissionsImport;

use common\behaviors\StatusBehavior;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Solutions\BaseSolution;

use frontend\models\DealInterface;
use frontend\models\Deal;

/**
 * Class RelatedDealsManager
 *
 * @package common\models\CommissionsImport
 */
class RelatedDealsManager
{
	/**
	 * @var DocumentParserInterface
	 */
	protected $parser;

	/**
	 * @var DealContainer
	 */
	protected $parsedDeal;

	/**
	 * @var DealInterface[]
	 */
	protected $deals = [];

	/**
	 * @var RelatedDealContainer[]
	 */
	protected $dealContainers = [];

	/**
	 * @var BaseSolution[]
	 */
	private $solutions = [];

	/**
	 * @var array
	 */
	private $dealsBySolutions = [];

	/**
	 * @var RelatedDealContainer
	 */
	private $checkedDeal;

	/**
	 * @return $this
	 */
	protected function createDealContainers()
	{
		$this->dealContainers = [];
		foreach ($this->deals as $deal) {
			$this->dealContainers[] = (new RelatedDealContainer())->setDeal($deal);
		}

		return $this;
	}

	/**
	 * @return $this
	 */
	public function findPossibleSolutionsForRelatedDeals()
	{
		$this->createDealContainers();

		foreach ($this->solutions as $solution) {
			$this->dealsBySolutions[$solution::SOLUTION] = [];

			foreach ($this->dealContainers as $dealContainer) {
				if ($solution->isFitRelatedDeal($this->parsedDeal, $dealContainer->getDeal(), $this->parser)) {
					$dealContainer->addSolution($solution);
					$this->dealsBySolutions[$solution::SOLUTION][] = $dealContainer;
				}
			}
		}

		return $this;
	}

	public function setDealAsChecked()
	{
		/** @var RelatedDealContainer[] $dealsContainers */
		$dealsContainers = $this->getDealsBySolutions()[$this->parsedDeal->solution];

		if (count($dealsContainers)) {
			if (self::checkAllDealsArePaid($dealsContainers)) {
				foreach ($dealsContainers as $dealContainer) {
					if ($dealContainer->getDeal()->getAttribute('premium') > 0) {
						$this->checkedDeal = $dealContainer->setChecked(true);
						break;
					}
				}
			}
			if (!$this->checkedDeal) {
				$this->checkedDeal = current($dealsContainers)->setChecked(true);
			}
		}
	}

	public static function checkAllDealsArePaid(array $dealsContainers): bool
	{
		foreach ($dealsContainers as $dealsContainer) {
			if ($dealsContainer->getDeal()->getAmount() == 0) {
				return false;
			}
		}
		return true;
	}

	public static function checkAllDealsAreUnpaid(array $deals): bool
	{
		foreach ($deals as $deal) {
			if ($deal->getAmount() != 0) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return RelatedDealContainer|null
	 */
	public function getCheckedDeal(): ?RelatedDealContainer
	{
		return $this->checkedDeal;
	}

	/**
	 * @param string $solution
	 *
	 * @return bool
	 */
	public function existsDealsWithSolution(string $solution): bool
	{
		$deals = $this->dealsBySolutions[$solution] ?? [];

		return boolval(count($deals));
	}

	/**
	 * @param DocumentParserInterface $parser
	 *
	 * @return $this
	 */
	public function setParser(DocumentParserInterface $parser): RelatedDealsManager
	{
		$this->parser = $parser;

		return $this;
	}

	/**
	 * @param BaseSolution[] $solutions
	 *
	 * @return RelatedDealsManager
	 */
	public function setSolutions(array $solutions): RelatedDealsManager
	{
		$this->solutions = $solutions;

		return $this;
	}

	/**
	 * @param DealInterface[] $deals
	 *
	 * @return RelatedDealsManager
	 */
	public function setDeals(array $deals): RelatedDealsManager
	{
		$this->deals = $deals;

		return $this;
	}

	/**
	 * @param array $deals
	 *
	 * @return RelatedDealsManager
	 */
	public function addDeals(array $deals): RelatedDealsManager
	{
		$this->deals = array_merge($this->deals, $deals);

		return $this;
	}

	/**
	 * @return Deal[]
	 */
	public function getDeals(): array
	{
		return $this->deals;
	}

	/**
	 * @return array
	 */
	public function getDealsWithClass(): array
	{
		$deals = [];

		foreach ($this->deals as $deal) {
			$deals[] = [
				'dealClass' => get_class($deal),
				'dealId' => $deal->getPrimaryKey(),
			];
		}

		return $deals;
	}

	/**
	 * @param $key
	 */
	public function unsetDeal($key)
	{
		unset($this->deals[$key]);
	}

	/**
	 * @param DealContainer $parsedDeal
	 *
	 * @return RelatedDealsManager
	 */
	public function setParsedDeal(DealContainer $parsedDeal): RelatedDealsManager
	{
		$this->parsedDeal = $parsedDeal;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getDealsBySolutions(): array
	{
		return $this->dealsBySolutions;
	}

	/**
	 * @return RelatedDealContainer[]
	 */
	public function getDealContainers(): array
	{
		return $this->dealContainers;
	}

}
