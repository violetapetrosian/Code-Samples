<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\HArray;
use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\Renewals;
use frontend\models\InsuranceDeals;

class SplitParser extends ExcelDocumentsParser
{
	protected function parseAndCreateDealsContainers()
	{
		$rows = array_slice($this->rows, $this->titlesIndex);
		$deals = [];
		foreach ($rows as $row) {
			$deal = new DealContainer();
			$this->setDealDefaults($deal);
			$deal->original = [$row];
			$this->matchRowColumns($deal, $row);

			if (!$this->checkDeal($deal)) {
				continue;
			}

			if ($deal->parsed_deal_class === Renewals::class || $deal->share_percent == 100) {
				$deals[][] = $deal;
				continue;
			}

			$splitKey = $deal->policy_number . $deal->client_name;
			// combine split deals based on the $splitKey
			if (array_key_exists($splitKey, $deals)) {
				$deals[$splitKey][] = $deal;
				continue;
			}

			$deals[$splitKey][] = $deal;
		}

		foreach ($deals as $key => $splitDeal) {
			// if found split deal based on $splitKey
			if (count($splitDeal) > 1) {
				$uniqueAdvisors = HArray::map(HArray::toArray($splitDeal), 'contract_code', 'advisor_name');
				foreach ($splitDeal as &$deal) {
					$this->parseSplitAdvisorName($deal, $uniqueAdvisors);
					$this->parseSplitContractCode($deal, array_keys($uniqueAdvisors), static::COMPANIES_IDS[0]);
				}

				$this->deals = array_merge($this->deals, $splitDeal);

				continue;
			}

			$this->deals = array_merge($this->deals, $splitDeal);
		}
	}

	/**
	 * Determines if there is a split deal.
	 *
	 * @param DealContainer $deal
	 * @param string|null   $value
	 */
	protected function parseCommissionSharePercent(DealContainer $deal, ?string $value)
	{
		$deal->share_percent = (int) $this->parseMoneyColumn($value);
	}

	/**
	 * @param DealContainer $deal
	 * @param array $uniqueAdvisors
	 *
	 * @return $this
	 */
	private function parseSplitAdvisorName(DealContainer $deal, array $uniqueAdvisors)
	{
		if (count($uniqueAdvisors) > 1) {
			$deal->advisor_name = reset($uniqueAdvisors) ? $this->getParseSimpleName(reset($uniqueAdvisors)) : null;
			$deal->share_advisor_name = end($uniqueAdvisors) ? $this->getParseSimpleName(end($uniqueAdvisors)) : null;
			$deal->is_shared = 1;
		}

		return $this;
	}

	/**
	 * @param DealContainer $deal
	 * @param array $uniqueCodes
	 * @param int $companyId
	 *
	 * @return $this
	 */
	private function parseSplitContractCode(DealContainer $deal, array $uniqueCodes, int $companyId)
	{
		if (count($uniqueCodes) > 1) {
			$deal->additional_data['userContractCode'] = reset($uniqueCodes);
			if ($user = $this->getAgentByContractCode($companyId, reset($uniqueCodes))) {
				$deal->additional_data['user'] = $user;
			}

			$deal->additional_data['sharedUserContractCode'] = end($uniqueCodes);
			if ($sharedUser = $this->getAgentByContractCode($companyId, end($uniqueCodes))) {
				$deal->additional_data['sharedUser'] = $sharedUser;
			}
		}

		return $this;
	}

	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = InsuranceDeals::class;
	}

	/**
	 * @param $value
	 *
	 * @return float|null
	 */
	protected function parseMoneyColumn($value)
	{
		$position = strrpos($value, ',');
		if ($position !== false) {
			$value[$position] = '.';
		}

		return preg_match('/[0-9]/', $value) ? Money::toFloat($value) : null;
	}

	/**
	 * @return bool
	 */
	public function isSkippedSplitRenewals(): bool
	{
		return false;
	}
}