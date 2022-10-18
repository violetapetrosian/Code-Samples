<?php

namespace common\models\CommissionsImport;

use common\models\ForbidDealChecker;
use common\models\Renewals;
use frontend\models\DscDeal;
use frontend\models\InsuranceDeals;
use frontend\models\MutualFundDeals;
use frontend\models\SegregatedDeal;
use frontend\models\TrailDeals;
use frontend\models\VirtgateForm;

use Yii;

class EOLicenseChecker
{
	const UNIQUE_KEY_TEMPLATE = 'userId:%u-type:%s-stateId:%u';

	/**
	 * @var array
	 */
	private static $memo = [];

	/**
	 * @var array
	 */
	private static $checksByType = [
		// segregated (virtgate)
		VirtgateForm::TRAN_TYPE_FIRST_YEAR => ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW,

		// trails (virtgate)
		VirtgateForm::TRAN_TYPE_SERVICE_FEE => ForbidDealChecker::EXPIRED_LESS_YEAR_ALLOW
			| ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW,

		InsuranceDeals::class => ForbidDealChecker::CURRENT_ALLOW,
		Renewals::class => ForbidDealChecker::EXPIRED_LESS_YEAR_ALLOW
			| ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW,
		TrailDeals::class => ForbidDealChecker::EXPIRED_LESS_YEAR_ALLOW
			| ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW,
		SegregatedDeal::class => ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW,
		MutualFundDeals::class => ForbidDealChecker::CURRENT_ALLOW,
		DscDeal::class => ForbidDealChecker::CURRENT_ALLOW,
	];

	/**
	 * @param string $type
	 * @return string
	 */
	public static function getWithoutEoOrLicenseExplanation(string $type)
	{
		$checks = self::getChecksForType($type);
		if ($checks === 0) {
			return '';
		}

		$explanationMap = [
			ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW => 'Agent\'s License expired more than year ago but Agent does not have a negative report.',
			ForbidDealChecker::EXPIRED_LESS_YEAR_ALLOW | ForbidDealChecker::EXPIRED_MORE_YEAR_NEGATIVE_ALLOW => 'Agent\'s License or E&O has expired more than 1 year ago and Agent does not have a negative report.',
			ForbidDealChecker::CURRENT_ALLOW => 'The Agent doesn\'t currently have valid License or E&O',
		];

		return array_key_exists($checks, $explanationMap) ? $explanationMap[$checks] : '';
	}

	/**
	 * @param string $type
	 * @param        $userId
	 * @param null $shareAdvisorId
	 *
	 * @param null $stateId
	 * @return bool
	 */
	public static function check(string $type, $userId, $shareAdvisorId = null, $stateId = null, bool $checkForBoth = false): bool
	{
		$forbidDealChecker = new ForbidDealChecker();

		$checks = self::getChecksForType($type);

		if ($checks === 0) {
			return true;
		}

		$userKey = sprintf(self::UNIQUE_KEY_TEMPLATE, $userId, $type, $stateId);
		if (!array_key_exists($userId, self::$memo)) {
			self::$memo[$userKey] = $forbidDealChecker->run($userId, $checks, false, $stateId);
		}

		$shareUserKey = sprintf(self::UNIQUE_KEY_TEMPLATE, $shareAdvisorId, $type, $stateId);
		if ($shareAdvisorId && !array_key_exists($shareAdvisorId, self::$memo)) {
			self::$memo[$shareUserKey] = $forbidDealChecker->run($shareAdvisorId, $checks, false,  $stateId);
		}

		if ($checkForBoth && $shareAdvisorId) {
			return self::$memo[$userKey] || self::$memo[$shareUserKey];
		}

		return $shareAdvisorId
			? self::$memo[$userKey] && self::$memo[$shareUserKey]
			: self::$memo[$userKey];
	}

	/**
	 * @param $type
	 *
	 * @return int
	 */
	public static function getChecksForType($type): int
	{
		$enableForbidCheck = Yii::$app->params['enable_import_forbid_check'] ?? true;
		if (
			!array_key_exists($type, self::$checksByType)
			|| !$enableForbidCheck
		) {
			return 0;
		}

		return self::$checksByType[$type];
	}
}
