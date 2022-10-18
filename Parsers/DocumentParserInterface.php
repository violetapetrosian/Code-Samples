<?php

namespace common\models\CommissionsImport\Parsers;

use common\models\CommissionsImport\DealContainer;

use common\models\CommissionsImport\Resolvers\Resolver;
use frontend\models\Company;
use frontend\models\Deal;

use frontend\models\queries\DealQuery;
use yii\db\ActiveQuery;
use yii\db\ActiveRecordInterface;
use yii\web\UploadedFile;

/**
 * Interface DocumentParserInterface
 *
 * @package common\models\CommissionsImport\Parsers
 */
interface DocumentParserInterface extends ActiveRecordInterface
{
	const SOLUTION_SKIP = 'skip';
	const SOLUTION_UPLOAD = 'upload';
	const SOLUTION_UPLOAD_RENEWALS = 'upload_renewal';
	const SOLUTION_UPLOAD_AS_SUB_DEAL = 'upload_sub_deal';
	const SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL = 'upload_renewal_sub_deal';
	const SOLUTION_UPLOAD_TO_ADVISOR = 'upload_to_advisor';
	const SOLUTION_CHARGE_BACK = 'charge_back';
	const SOLUTION_REINSTATEMENT = 'reinstatement';
	const SOLUTION_MATCH = 'match';
	const SOLUTION_UNMATCH = 'unmatch';

	const SOLUTIONS = [
		self::SOLUTION_MATCH => 'Match deal to',
		self::SOLUTION_UPLOAD_AS_SUB_DEAL => 'Insert record as a New FYC sub deal relevant to',
		self::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL => 'Insert Record as a New Renewal sub deal related to',
		self::SOLUTION_UPLOAD => 'Insert record as a New Deal relevant to',
		self::SOLUTION_UPLOAD_RENEWALS => 'Insert record as a New Renewals deal',
		self::SOLUTION_CHARGE_BACK => 'Add as Charge Back to deal',
		self::SOLUTION_REINSTATEMENT => 'Add as Reinstatement to deal',
		self::SOLUTION_UPLOAD_TO_ADVISOR => 'Insert record as a New Deal relevant to',
		self::SOLUTION_UNMATCH => 'To Unmatch Deals',
	];

	const SOLUTION_COUNTER_LABELS = [
		self::SOLUTION_UPLOAD_AS_SUB_DEAL => 'Insert as new FYC sub deal',
		self::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL => 'Insert Record as new Renewal sub deal',
		self::SOLUTION_UPLOAD => 'Insert as new',
		self::SOLUTION_UPLOAD_RENEWALS => 'Insert record as new Renewals',
		self::SOLUTION_MATCH => 'Matched',
		self::SOLUTION_UNMATCH => 'Unmatched',
		self::SOLUTION_UPLOAD_TO_ADVISOR => 'Insert as new manual matching',
		self::SOLUTION_CHARGE_BACK => 'Charged Back',
		self::SOLUTION_REINSTATEMENT => 'Reinstated',
	];

	const MANUAL_MATCH_SOLUTIONS = [
		self::SOLUTION_UPLOAD_TO_ADVISOR,
		self::SOLUTION_UNMATCH,
	];

	/**
	 * Sets a limited number of deals that the resolver will work with.
	 *
	 * @param array $dealContainers
	 */
	public function setDealContainers(array $dealContainers): void;

	/**
	 * Initializes the deal manager based on previously found deals.
	 */
	public function setRelatedManagerToDealContainers(): void;

	/**
	 * @return array
	 */
	public function getPossibleSolutions(): array;

	/**
	 * Returns styled solution names.
	 *
	 * @return array
	 */
	public static function getSolutionLabels(): array;

	/**
	 * Returns HTML string
	 *
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getSolutionsInput(DealContainer $deal): string;

	/**
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getManualMatchingSolutionsInputs(DealContainer $deal): string;

	/**
	 * @return array
	 */
	public function getSolutionsCountersLabels(): array;

	/**
	 * Sets file
	 *
	 * @param UploadedFile $file
	 *
	 * @return $this
	 */
	public function setFile(UploadedFile $file): DocumentParserInterface;

	/**
	 * checks if file contains correctly formatted data
	 *
	 * @return bool
	 */
	public function checkFileFormat(): bool;

	/**
	 * checks all rules and if file contains correctly formatted data
	 *
	 * @return bool
	 */
	public function validate(): bool;

	/**
	 * Extract data from file into array of deals containers
	 *
	 * @return bool
	 */
	public function parseFile(): bool;

	/**
	 * returns array of parsed deals
	 *
	 * @param bool $query
	 *
	 * @return ActiveQuery|DealContainer[]
	 */
	public function getDeals($query = false);

	/**
	 * Returns deals that are waiting for any action.
	 *
	 * @return ActiveQuery
	 */
	public function getPendingDeals(): ActiveQuery;

	/**
	 * Returns deals processed by the admin.
	 *
	 * @return array
	 */
	public function getProcessedDeals(): array;

	/**
	 * Returns deals pending queue processing.
	 *
	 * @return array
	 */
	public function getPendingMatchDeals(): array;

	/**
	 * Returns deals that are waiting for processing by the admin.
	 *
	 * @return array
	 */
	public function getPendingAdminDeals(): array;

	/**
	 * Returns an array of deals that are being processed by the resolver.
	 *
	 * @return DealContainer[]
	 */
	public function getProcessingDealContainers(): array;

	/**
	 * Saves all deals. If self is new - saves self before save deals
	 *
	 * @param string|null $status
	 * @param string|null $errorMessage
	 *
	 * @return DocumentParser
	 */
	public function saveDeals(?string $status = null, ?string $errorMessage = null): DocumentParser;

	/**
	 * Returns deal classes full names used in parser class for matching deals
	 *
	 * @return Deal[]
	 */
	public function getMatchingDealClasses(): array;

	/**
	 * @return DocumentParserInterface
	 */
	public function matchDeals(): DocumentParserInterface;

	/**
	 * Looks for available solutions already at the stage of processing by the admin.
	 *
	 * @return DocumentParserInterface
	 */
	public function findSolutionsToMatchDeals(): DocumentParserInterface;

	/**
	 * Checks if parsing results need to be updated.
	 *
	 * @return bool
	 */
	public function isNeedRecreateParseResults(): bool;

	/**
	 * @return Resolver
	 */
	public function getResolver(): Resolver;

	/**
	 * returns array of parsed deals filtered by solution unmatched
	 *
	 * @return ActiveQuery
	 */
	public function getUnmatchedDeals(): ActiveQuery;

	/**
	 * @inheritDoc
	 */
	public function getUploadToAdvisorDeals(): ActiveQuery;

	/**
	 * @return DealContainer[]
	 */
	public function getUnmatchedParseResults(): array;

	/**
	 * @return DealContainer[]
	 */
	public function getDealsForManualMatching(): array;

	/**
	 * @param string $type
	 * @param        $userId
	 * @param null $shareUserId
	 *
	 * @param null $stateId
	 * @return bool|mixed
	 */
	public function getEoLicenseChecks(string $type, $userId, $shareUserId = null, $stateId = null): bool;

	/**
	 * returns array of parsed deals grouped by advisor
	 *
	 * @param DealContainer[] $deals
	 *
	 * @return array
	 */
	public function groupByAdvisor(array $deals): array;

	/**
	 * updates solutions after user action
	 *
	 * @param array $solutions
	 * @param bool  $onlyCounters
	 *
	 * @return bool
	 */
	public function updateSolutions(array $solutions, bool $onlyCounters): bool;

	/**
	 * applies solutions
	 *
	 * @return bool
	 */
	public function applySolutions(): bool;

	/**
	 * returns array of unmatched originals from file
	 *
	 * @return array
	 */
	public function getReportUnmatched(): array;

	/**
	 * @param null $attribute
	 *
	 * @return array
	 */
	public function getErrors($attribute = null);

	/**
	 * @param null $attribute
	 *
	 * @return mixed
	 */
	public function hasErrors($attribute = null);

	/**
	 * checks if uploaded file was uploaded earlier
	 *
	 * @return bool
	 */
	public function isFileUnique(): bool;

	/**
	 * @return bool
	 */
	public function isPossibleMatchDealsByAdmin(): bool;

	/**
	 * @return bool
	 */
	public function isPossibleDownloadUnmatchedDeals(): bool;

	/**
	 * @return bool
	 */
	public function isPossibleDelete(): bool;

	/**
	 * @return bool
	 */
	public function hasNotClosedPreviousImport(): bool;

	/**
	 * @return bool
	 */
	public function hasAccessMatchDeals(): bool;

	/**
	 * @param DealQuery $query
	 *
	 * @return DealQuery
	 */
	public function additionalQuerySettings(DealQuery $query): DealQuery;

	/**
	 * @return Company[]
	 */
	public function getCompanies(): array;

	/**
	 * Returns the company ID that comes from the form.
	 *
	 * @return int|string|null
	 */
	public function getFormCompanyId();

	/**
	 * Returns the types of deals that are displayed in the form.
	 *
	 * @return string|null
	 */
	public function getFormDealType(): ?string;

	/**
	 * @return string
	 */
	public function getCompanyNames(): string;

	/**
	 * @return mixed
	 */
	public function getId();

	/**
	 * @return string
	 */
	public function getModelName(): string;

	/**
	 * @return DocumentParser
	 */
	public function getInitializedParser(): DocumentParser;

	/**
	 * Returns deal types for insurance
	 *
	 * @return array
	 */
	public function getDealSubTypes(): array;

	/**
	 * @return bool
	 */
	public function hasPendingDeals(): bool;

	/**
	 * @return array
	 */
	public function getOriginalAdvisorNameKeys(): array;

	/**
	 * @return bool
	 */
	public function isSkippedSplitRenewals(): bool;

	/**
	 * @return array
	 */
	public function getDuplicatedAdvisorNames(): array;
}