<?php

namespace common\models\CommissionsImport\Solutions;

use common\helpers\Html;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParser;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use frontend\models\DealInterface;

/**
 * Class BaseSolution
 *
 * @package Solutions
 *
 * Base class for solutions. Checks if the solution fits the deal. Applies solution after a user action.
 */
abstract class BaseSolution
{
	/**
	 * key from array of possible solutions. Should be redefined in child classes.
	 */
	const SOLUTION = '';

	/**
	 * Checks if deal fits to this solution
	 *
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	abstract public function isFit(DealContainer $deal): bool;

	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	abstract public function isFitRelatedDeal(
		DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null
	): bool;

	/**
	 * Applies solution after user action
	 *
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	abstract public function applySolution(DealContainer $deal): bool;

	/**
	 * Setts values of deal fields relevant to this solution.
	 *
	 * @param DealContainer $deal
	 */
	public function setSolutionToDeal(DealContainer $deal): void
	{
		$deal->solution = static::SOLUTION;
	}

	/**
	 * Updates values of deal fields relevant to this solution after a user action.
	 *
	 * @param DealContainer $deal
	 * @param array         $data Additional data for solution such as matched deal id or matched deal class.
	 * @param bool          $onlyCounters
	 */
	public function updateSolutionToDeal(DealContainer $deal, array $data = [], bool $onlyCounters = false): void
	{
		$deal->solution = $data['solution'];
		$deal->deal_id = $data['matched_id'] ?? null;
		if (!$onlyCounters) {
			$deal->status = $deal::STATUS_PENDING;
		}
	}

	/**
	 * @return string
	 */
	public function getLabel(): string
	{
		return DocumentParser::getSolutionLabels()[static::SOLUTION];
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getInputHtml(DealContainer $deal): string
	{
		$checkedDeal = $deal->relatedDealsManager->getCheckedDeal();

		$solution = DocumentParserInterface::SOLUTION_UNMATCH;
		$checked = $this::SOLUTION === $solution;
		$pOptions = $checked ? [] : ['class' => 'disabled'];

		if ($checkedDeal) {
			$checked = $deal->solution === static::SOLUTION;
			$solution = static::SOLUTION;
			$pOptions = in_array($this, $checkedDeal->getSolutions()) ? [] : ['class' => 'disabled'];
		}

		return Html::beginTag('p', $pOptions) . Html::beginTag('label')
			. Html::input('radio',
				'deals[' . $deal->id . '][solution]',
				$solution,
				['checked' => $checked]) . static::getLabel()
			. Html::endTag('label') . Html::endTag('p');
	}

}