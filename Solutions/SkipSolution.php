<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;

use frontend\models\DealInterface;

/**
 * @deprecated
 * Class SkipSolution
 *
 * @package common\models\CommissionsImport\Solutions
 */
class SkipSolution extends BaseSolution
{
	const SOLUTION = DocumentParserInterface::SOLUTION_SKIP;

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		return $deal->commission < 0;
	}

	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		return true;
	}

	/**
	 * @param DealContainer $deal
	 * @param array         $data
	 * @param bool          $onlyCounters
	 */
	public function updateSolutionToDeal(DealContainer $deal, array $data = [], bool $onlyCounters = false): void
	{
		$deal->solution = $data['solution'];
		if (!$onlyCounters) {
			$deal->status = $deal::STATUS_APPLIED;
		}
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function applySolution(DealContainer $deal): bool
	{
		$deal->solution = static::SOLUTION;
		$deal->status = $deal::STATUS_APPLIED;

		return $deal->save(false);
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