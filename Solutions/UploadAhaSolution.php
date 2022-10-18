<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;

class UploadAhaSolution extends UploadSolution
{
	/**
	 * @param DealContainer $deal
	 *
	 * @return bool
	 */
	public function isFit(DealContainer $deal): bool
	{
		return $deal->commission != 0 && count($deal->relatedDealsManager->getDeals());
	}

}