<?php

namespace common\models\CommissionsImport\Solutions;

use common\helpers\HArray;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\Renewals;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

/**
 * Class MatchGmsGreenShieldSolution
 *
 * @package common\models\CommissionsImport\Solutions
 */
class MatchInsuranceAndRenewalCombinedSolution extends MatchSolution
{
	/**
	 * @param DealContainer $parsedDeal
	 * @param DealInterface $deal
	 *
	 * @return bool
	 */
	public function isFitRelatedDeal(DealContainer $parsedDeal, DealInterface $deal, ?DocumentParserInterface $parser = null): bool
	{
		if ($parsedDeal->commission < 0 && $deal->getAmount() <= 0) {
			$parsedDeal->setExplanationToDeal('The Amount of the Chargeback exceeds the Amount of the Deal.');
			return false;
		}

		return $parsedDeal->parsed_deal_class === get_class($deal)
			&& (get_class($deal) !== InsuranceDeals::class || !$deal->getAttribute('parent_deal_id'))
			&& $deal->getAmount() == 0;
	}

}