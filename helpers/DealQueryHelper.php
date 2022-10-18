<?php

namespace common\models\CommissionsImport\helpers;

class DealQueryHelper
{
	/**
	 * @param string $policyNumber
	 *
	 * @return array
	 */
	public static function getAvailablePolicyNumbers(string $policyNumber)
	{
		return array_unique([
			trim($policyNumber),
			trim(preg_replace("/\(C\)$/", "", $policyNumber)),
			trim(preg_replace("/\(P\)$/", "", $policyNumber)),
			trim(preg_replace("/^012-/", "", $policyNumber)),
			trim(preg_replace("/^101-/", "", $policyNumber)),
		]);
	}

	/**
	 * @param string $parsedPolicy
	 * @param string $dealPolicy
	 *
	 * @return bool
	 */
	public static function comparePolicyNumber(string $parsedPolicy, string $dealPolicy)
	{
		if (!$parsedPolicy || !$dealPolicy) {
			return false;
		}

		$policyCheck = false;
		foreach (self::getAvailablePolicyNumbers($parsedPolicy) as $availablePolicyNumber) {
			if (strpos(strtolower($dealPolicy), strtolower($availablePolicyNumber)) === false) {
				continue;
			}
			$policyCheck = true;
		}

		return $policyCheck;
	}
}