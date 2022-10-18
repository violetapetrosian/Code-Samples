<?php

namespace common\models\CommissionsImport;

use common\models\CommissionsImport\DealContainer as DC;

/**
 * Class ProgressDealsBar
 *
 * @package common\models\CommissionsImport
 */
class ProgressDealsBar
{
	/**
	 * @var DealContainer[]
	 */
	private $_deals;

	/**
	 * @var array
	 */
	private $_dealCounters;

	/**
	 * ProgressDealsBar constructor.
	 *
	 * @param array $deals
	 */
	public function __construct(array $deals)
	{
		$this->_deals = $deals;

		$this->setDealCountersByStatus();
	}

	/**
	 * Returns an array for building loading levels.
	 *
	 * @return array[]
	 */
	public function getBars(): array
	{
		return [
			DC::STATUS_PENDING_MATCH => $this->getPendingMatchBar(),
			DC::STATUS_PENDING_ADMIN => $this->getPendingAdminBar(),
			DC::STATUS_APPLIED => $this->getPendingApplyBar(),
		];
	}

	/**
	 * Returns the overall progress percentage.
	 *
	 * @return float
	 */
	public function getTotalPercent(): float
	{
		$percents = array_column($this->getBars(), 'percent');
		$totalPercent = array_sum($percents) / count($percents);

		return round($totalPercent, 2);
	}

	/**
	 * Returns the deal match level.
	 *
	 * @return array
	 */
	private function getPendingMatchBar(): array
	{
		$countDeals = count($this->_deals);

		$percent = $countDeals
			? (($countDeals - $this->_dealCounters[DC::STATUS_PENDING_MATCH]) * 100 / $countDeals)
			: 100;

		return [
			'percent' => round($percent, 2),
			'label' => 'Match Action',
		];
	}

	/**
	 * Returns the deal admin resolving level.
	 *
	 * @return array
	 */
	private function getPendingAdminBar(): array
	{
		$countDeals = $this->getCountDeals();
		$newDeals = $this->_dealCounters[DC::STATUS_PENDING_MATCH] + $this->_dealCounters[DC::STATUS_PENDING_ADMIN];

		$percent = $countDeals ? (($countDeals - $newDeals) * 100 / $countDeals) : 100;

		return [
			'percent' => round($percent, 2),
			'label' => 'Admin Action',
		];
	}

	/**
	 * Returns the deal apply level.
	 *
	 * @return array
	 */
	private function getPendingApplyBar(): array
	{
		$countApplied = $this->_dealCounters[DC::STATUS_APPLIED] + $this->_dealCounters[DC::STATUS_ERROR];

		$percent = $this->getCountDeals() ? ($countApplied * 100 / $this->getCountDeals()) : 100;

		return [
			'percent' => round($percent, 2),
			'label' => 'Apply Action',
		];
	}

	/**
	 * @return int
	 */
	private function getCountDeals(): int
	{
		return count($this->_deals) - $this->_dealCounters[DC::STATUS_SKIPPED];
	}

	/**
	 * Counts the number of transactions for each status.
	 */
	private function setDealCountersByStatus(): void
	{
		$this->_dealCounters = [
			DC::STATUS_PENDING_MATCH => count(array_filter($this->_deals, function (DC $deal) {
				return $deal->status === DC::STATUS_PENDING_MATCH;
			})),
			DC::STATUS_PENDING_ADMIN => count(array_filter($this->_deals, function (DC $deal) {
				return $deal->status === DC::STATUS_PENDING_ADMIN;
			})),
			DC::STATUS_PENDING => count(array_filter($this->_deals, function (DC $deal) {
				return $deal->status === DC::STATUS_PENDING;
			})),
			DC::STATUS_APPLIED => count(array_filter($this->_deals, function (DC $deal) {
				return $deal->status === DC::STATUS_APPLIED;
			})),
			DC::STATUS_ERROR => count(array_filter($this->_deals, function (DC $deal) {
				return $deal->status === DC::STATUS_ERROR;
			})),
			DC::STATUS_SKIPPED => count(array_filter($this->_deals, function (DC $deal) {
				return $deal->status === DC::STATUS_SKIPPED;
			})),
		];
	}

}