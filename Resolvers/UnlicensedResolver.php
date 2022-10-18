<?php

namespace common\models\CommissionsImport\Resolvers;

use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Solutions\MatchSolution;
use common\models\CommissionsImport\Solutions\UnmatchSolution;
use common\models\CommissionsImport\Solutions\UploadSolution;
use common\models\CommissionsImport\Solutions\UploadToAdvisorSolution;

/**
 * Class UnlicensedResolver
 *
 * @package common\models\CommissionsImport\Resolvers
 */
class UnlicensedResolver extends StandardResolver
{
	/**
	 * @return array
	 */
	protected function getSolutionClassNames(): array
	{
		return [
			DocumentParserInterface::SOLUTION_UPLOAD => UploadSolution::class,
			DocumentParserInterface::SOLUTION_UPLOAD_TO_ADVISOR => UploadToAdvisorSolution::class,
			DocumentParserInterface::SOLUTION_MATCH => MatchSolution::class,
			DocumentParserInterface::SOLUTION_UNMATCH => UnmatchSolution::class,
		];
	}

}