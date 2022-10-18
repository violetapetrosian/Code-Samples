<?php

namespace common\models\CommissionsImport\Solutions;

use common\models\CommissionsImport\DealContainer;

class SsqUploadToAdvisorSolution extends UploadToAdvisorSolution
{
	public function isFit(DealContainer $deal): bool
	{
		if ($deal->getIsShared()) {
			$deal->excludeFromManualMatch = true;
			$deal->additional_data['info'] = 'The deal will be skipped from the Manual Matching because of the Split';
			$deal->setExplanationToDeal($deal->additional_data['info']);

			return false;
		}

		return parent::isFit($deal);
	}
}