<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;

class UnmatchIASolution extends UnmatchSolution
{
	public function isFit(DealContainer $deal, $clients = []): bool
	{
		if (!empty($clients[$deal->policy_number]) && count($clients[$deal->policy_number]) > 1) {
			$deal->forceToUnmatch = true;
			$deal->setExplanationToDeal('Split deal for ' . $deal->policy_number);
			return true;
		}

		if ($deal->getIsShared() && (!$deal->advisor_name || !$deal->share_advisor_name)) {
			$deal->setExplanationToDeal('No match found for one of the split agents');

			$deal->forceToUnmatch = true;
			$deal->skipToUnmatchReport = true;
			return true;
		}

		return parent::isFit($deal) || $deal->forceToUnmatch;
	}
}