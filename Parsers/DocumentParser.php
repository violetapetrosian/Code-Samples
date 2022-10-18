<?php

namespace common\models\CommissionsImport\Parsers;

use common\behaviors\JsonBehavior;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\EOLicenseChecker;
use common\models\CommissionsImport\Resolvers\Resolver;
use common\models\CommissionsImport\Resolvers\StandardResolver;
use common\models\Contracting;
use common\models\User;
use common\models\UserContracting;
use common\models\Media;

use DateTime;
use frontend\models\Company;
use frontend\models\Deal;
use frontend\models\queries\DealQuery;
use frontend\models\VirtgateUniversalForm;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

use ReflectionClass;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\web\UploadedFile;

/**
 * Class DocumentParser
 * @package common\models\CommissionsImport
 *
 * @property int             $user_id
 * @property int             $id
 * @property string          $file_hash
 * @property string          $file_name
 * @property string          $model
 * @property string          $titles_json
 * @property bool            $is_outdated
 * @property string          $file_additional_info
 * @property bool            $is_unmatched_processed
 * @property string          $created_at [datetime]
 * @property string          $updated_at [datetime]
 *
 * @property User            $user
 * @property DealContainer[] $deals
 * @property DealContainer[] $unmatchedDeals
 * @property DealContainer[] $uploadToAdvisorDeals
 */
class DocumentParser extends ActiveRecord implements DocumentParserInterface
{
	const COMPANIES_IDS = [];

	const UNIQUE_KEY_TEMPLATE = 'userId:%u-shareUserId:%u-type:%s-stateId:%u';

	/**
	 * Original titles for columns from file
	 *
	 * @var array|null
	 */
	public $titles;

	/**
	 * @var array
	 */
	public $reportTitles = [];

	/**
	 * @var bool
	 */
	public $divideToFilesByDates = false;

	/**
	 * array of parsed deals
	 *
	 * @var DealContainer[]|null
	 */
	protected $deals = [];

	/**
	 * @var Company[]
	 */
	protected $companies;

	/**
	 * @var string $dealClass full name of deal class
	 */
	protected $dealClass = Deal::class;

	/**
	 * @var Media
	 */
	protected $file;

	/**
	 * @var Resolver
	 */
	protected $resolver;

	/**
	 * @var string
	 */
	protected $resolverClass = StandardResolver::class;

	/**
	 * @var array
	 */
	protected $agentsFoundByCode = [];

	/**
	 * @var array
	 */
	protected $contractingIds = [];

	/**
	 * @var array
	 */
	protected $eoLicenseChecks = [];

	public static $activeImportId;

	/**
	 * @inheritDoc
	 */
	public static function tableName()
	{
		return '{{%commission_import}}';
	}

	/**
	 * @inheritDoc
	 */
	public function getPossibleSolutions(): array
	{
		return $this->resolver->getPossibleSolutions();
	}

	/**
	 * @inheritDoc
	 */
	public function getSolutionsCountersLabels(): array
	{
		return $this->resolver->getSolutionsCountersLabels();
	}

	/**
	 * DocumentParser constructor.
	 *
	 * @param array $config
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);

		$this->resolver = new $this->resolverClass($this);
	}

	/**
	 * @inheritDoc
	 */
	public function behaviors()
	{
		return [
			'timestamp' => [
				'class' => TimestampBehavior::class,
				'value' => date('Y-m-d H:i:s'),
			],
			'json' => [
				'class' => JsonBehavior::class,
				'properties' => ['titles'],
				'jsonFields' => ['titles' => 'titles_json'],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function beforeSave($insert)
	{
		if ($insert) {
			$this->user_id = Yii::$app->user->identity ? Yii::$app->user->identity->id : $this->user_id;
			if (!$this->file_hash) {
				$this->file_hash = md5(file_get_contents($this->file->temp_file->tempName));
				$this->file_name = $this->file->temp_file->name ?? null;
			}
			$this->model = static::class;
		}

		return parent::beforeSave($insert);
	}

	/**
	 * @inheritDoc
	 */
	public function afterSave($insert, $changedAttributes)
	{
		parent::afterSave($insert, $changedAttributes);

		if ($insert) {
			$this->file->setDefaultValuesFromTempFile();
			$this->file->relatedTo($this);
			$this->file->save(false);
		}
	}
	
	public function afterDelete()
	{
		parent::afterDelete();

		$dividedFileIds = self::find()
			->alias('dp')
			->select(['dp.id'])
			->innerJoinWith('pendingMatchDealsQuery')
			->where(['file_hash' => $this->file_hash])
			->andWhere(['!=', 'dp.id', $this->id])
			->column();

		if ($dividedFileIds) {
			self::deleteAll(['id' => $dividedFileIds]);
		}
	}

	public static function getSolutionLabels(): array
	{
		return [
			self::SOLUTION_MATCH => '<span class="font_size_16">Match deal</span> to',
			self::SOLUTION_UPLOAD_AS_SUB_DEAL => 'Insert record as a <span class="font_size_16">New FYC</span> sub deal relevant to',
			self::SOLUTION_UPLOAD_AS_RENEWAL_SUB_DEAL => 'Insert Record as a <span class="font_size_16">New Renewal</span> sub deal related to',
			self::SOLUTION_UPLOAD => 'Insert record as a <span class="font_size_16">New Deal</span> relevant to',
			self::SOLUTION_UPLOAD_RENEWALS => 'Insert record as a <span class="font_size_16">New Renewals</span> deal',
			self::SOLUTION_CHARGE_BACK => 'Add as <span class="font_size_16">Charge Back</span> to deal',
			self::SOLUTION_REINSTATEMENT => 'Add as <span class="font_size_16">Reinstatement</span> to deal',
			self::SOLUTION_UPLOAD_TO_ADVISOR => 'Insert record as a <span class="font_size_16">New Deal</span> relevant to',
			self::SOLUTION_UNMATCH => 'To <span class="font_size_16">Unmatch</span> Deals',
		];
	}

	/**
	 * @return Company[]
	 */
	public function getCompanies(): array
	{
		if (!$this->companies) {
			$this->companies = Company::find()
				->where(['id' => static::COMPANIES_IDS])
				->indexBy(Company::primaryKey())
				->all();
		}

		return $this->companies;
	}

	/**
	 * @inheritDoc
	 */
	public function getFormCompanyId()
	{
		return static::COMPANIES_IDS[0] ?? null;
	}

	/**
	 * @inheritDoc
	 */
	public function getFormDealType(): ?string
	{
		$companyId = $this->getFormCompanyId();

		return VirtgateUniversalForm::getFirstDealTypeByCompany($companyId);
	}

	/**
	 * @return string
	 */
	public function getCompanyNames(): string
	{
		$names = [];
		foreach ($this->getCompanies() as $company) {
			$names[] = $company->name;
		}

		return implode(', ', $names);
	}

	/**
	 * @inheritDoc
	 */
	public function getId()
	{
		return $this->id;
	}

	/**
	 * @inheritDoc
	 */
	public function getModelName(): string
	{
		return (new ReflectionClass($this))->getShortName();
	}

	/**
	 * @inheritDoc
	 */
	public function getInitializedParser(): DocumentParser
	{
		$parser = new $this->model();

		$parser->isNewRecord = false;
		$parser->titles = $this->titles;
		$parser->setAttributes($this->attributes, false);

		return $parser;
	}

	/**
	 * @return ActiveQuery
	 */
	public function getUser(): ActiveQuery
	{
		return $this->hasOne(User::class, ['user_id' => 'user_id']);
	}

	/**
	 * @inheritDoc
	 */
	public function setFile(UploadedFile $file): DocumentParserInterface
	{
		$this->file = new Media();
		$this->file->inputFile($file);

		return $this;
	}

	public function setFileInstance(Media $file, string $fileName, string $fileHash): DocumentParserInterface
	{
		$this->file = $file;
		$this->file_name = $fileName;
		$this->file_hash = $fileHash;

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function getFile()
	{
		return $this->file;
	}

	public function getDividedParsers(): ?array
	{
		$date = new DateTime($this->created_at);
		$start = $date->modify('-2 minutes')->format('Y-m-d H:i:s');
		$end = $date->modify('+4 minutes')->format('Y-m-d H:i:s');

		return self::find()->where(['file_hash' => $this->file_hash])
			->andWhere(['!=', 'id', $this->id])
			->andWhere(['BETWEEN', 'created_at', $start, $end])
			->all();
	}

	/**
	 * @inheritDoc
	 */
	public function validate($attributeNames = null, $clearErrors = true): bool
	{
		if (!parent::validate() || !$this->checkFileFormat()) {
			return false;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getDeals($query = false)
	{
		if (!$this->deals) {
			$this->deals = $this->hasMany(DealContainer::class, ['commission_import_id' => 'id'])->all();
		}

		return $query ? $this->hasMany(DealContainer::class, ['commission_import_id' => 'id']) : $this->deals;
	}

	public function getDealsRelation(): ActiveQuery
	{
		return $this->hasMany(DealContainer::class, ['commission_import_id' => 'id']);
	}

	/**
	 * @inheritDoc
	 */
	public function getPendingDeals(): ActiveQuery
	{
		return $this->hasMany(DealContainer::class, ['commission_import_id' => 'id'])
			->andOnCondition(['status' => DealContainer::PENDING_STATUSES]);
	}

	public function getPendingMatchDealsQuery(): ActiveQuery
	{
		return $this->hasMany(DealContainer::class, ['commission_import_id' => 'id'])
			->andOnCondition(['status' => DealContainer::STATUS_PENDING_MATCH]);
	}

	/**
	 * @inheritDoc
	 */
	public function getProcessedDeals(): array
	{
		return $this->getDeals(true)->where(['status' => DealContainer::PROCESSED_STATUSES])->all();
	}

	/**
	 * @inheritDoc
	 */
	public function getPendingMatchDeals(): array
	{
		return $this->getDeals(true)->where(['status' => DealContainer::STATUS_PENDING_MATCH])->all();
	}

	/**
	 * @inheritDoc
	 */
	public function getPendingAdminDeals(): array
	{
		$solutions = implode(', ', array_map(function ($solution) {
			return '"' . $solution . '"';
		}, array_keys(self::SOLUTIONS)));

		return $this->getDeals(true)
			->where(['status' => DealContainer::STATUS_PENDING_ADMIN])
			->orderBy(new Expression('FIELD(solution, ' . $solutions . ')'))
			->addOrderBy(['advisor_name' => SORT_ASC])
			->all();
	}

	/**
	 * @inheritDoc
	 */
	public function getProcessingDealContainers(): array
	{
		return $this->resolver->getProcessingDealContainers();
	}

	/**
	 * @param DealContainer[] $deals
	 */
	public function setDeals(array $deals): void
	{
		$this->deals = $deals;
	}

	/**
	 * @return Resolver
	 */
	public function getResolver(): Resolver
	{
		return $this->resolver;
	}

	/**
	 * @inheritDoc
	 */
	public function getUnmatchedDeals(): ActiveQuery
	{
		return $this->getDeals(true)
			->andWhere(['=', 'solution', static::SOLUTION_UNMATCH])
			->andWhere(['!=', 'status', DealContainer::STATUS_PENDING_ADMIN]);
	}

	/**
	 * @inheritDoc
	 */
	public function getUploadToAdvisorDeals(): ActiveQuery
	{
		return $this->getDeals(true)->andWhere(['=', 'solution', static::SOLUTION_UPLOAD_TO_ADVISOR]);
	}

	/**
	 * @inheritDoc
	 */
	public function getUnmatchedParseResults(): array
	{
		$unmatchedDeals = [];

		foreach ($this->resolver->getProcessingDealContainers() as $deal) {
			if ($deal->isManualMatchSolution()) {
				$unmatchedDeals[] = $deal;
			}
		}

		return $unmatchedDeals;
	}

	/**
	 * @return DealContainer[]
	 */
	public function getDealsForManualMatching(): array
	{
		$unmatchedDeals = [];
		foreach ($this->getUnmatchedParseResults() as $deal) {
			if ($deal->isPendingAdmin() && $deal->isPossibleManualMatch()) {
				$unmatchedDeals[] = $deal;
			}
		}

		return $unmatchedDeals;
	}

	/**
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [Deal::class];
	}

	/**
	 * @inheritDoc
	 */
	public function matchDeals(): DocumentParserInterface
	{
		$this->resolver->matchDeals();

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function findSolutionsToMatchDeals(): DocumentParserInterface
	{
		$this->resolver->findSolutions();
		$this->resolver->findRelevantAdvisors();
		$this->resolver->saveActualSolution();

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function isNeedRecreateParseResults(): bool
	{
		return $this->resolver->hasDealsUpdatedActualSolution();
	}

	/**
	 * @inheritDoc
	 */
	public function groupByAdvisor(array $deals): array
	{
		$groupedDeals = [];
		foreach ($deals as $deal) {
			if ($deal->isManualMatchSolution()) continue;

			if (isset($groupedDeals[$deal->fullOwnerName])) {
				$groupedDeals[$deal->fullOwnerName][] = $deal;
			} else {
				$groupedDeals[$deal->fullOwnerName] = [$deal];
			}
		}
		return $groupedDeals;
	}

	/**
	 * @inheritDoc
	 */
	public function setDealContainers(array $dealContainers): void
	{
		$this->resolver->setDealContainers($dealContainers);
	}

	/**
	 * @inheritDoc
	 */
	public function setRelatedManagerToDealContainers(): void
	{
		$dealContainers = $this->resolver->getProcessingDealContainers();
		$batchRelatedDeals = DealContainer::getBatchRelatedDeals($dealContainers);

		foreach ($dealContainers as $dealContainer) {
			$relatedDeals = [];
			foreach ($dealContainer->foundedRelatedDeals as $foundedDeal) {
				$relatedDeal = $batchRelatedDeals[$foundedDeal['dealClass']][$foundedDeal['dealId']] ?? null;

				if ($relatedDeal) {
					$relatedDeals[] = $relatedDeal;
				}
			}

			$this->resolver->setRelatedManagerToDeal($dealContainer, $relatedDeals);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getSolutionsInput(DealContainer $deal): string
	{
		return $this->resolver->getSolutionsInput($deal);
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	public function getManualMatchingSolutionsInputs(DealContainer $deal): string
	{
		return $this->resolver->getManualMatchingSolutionsInputs($deal);
	}

	/**
	 * @inheritDoc
	 */
	public function updateSolutions(array $solutions, bool $onlyCounters): bool
	{
		return $this->resolver->updateSolutions($solutions, $onlyCounters);
	}

	/**
	 * @inheritDoc
	 */
	public function applySolutions(): bool
	{
		return $this->resolver->applySolutions();
	}

	/**
	 * @inheritDoc
	 */
	public function getReportUnmatched(): array
	{
		return $this->getReport($this->getUnmatchedDeals()->all());
	}

	/**
	 * @inheritDoc
	 */
	public function isFileUnique(): bool
	{
		$query = static::find()->where(['file_hash' => md5(file_get_contents($this->file->temp_file->tempName))]);
		if ($this->getPrimaryKey()) {
			$query->andWhere(['!=', 'id', $this->getPrimaryKey()]);
		}

		return !$query->exists();
	}

	/**
	 * @inheritDoc
	 */
	public function isPossibleMatchDealsByAdmin(): bool
	{
		return !$this->is_outdated
			&& !$this->getPendingMatchDeals()
			&& $this->getPendingAdminDeals();
	}

	/**
	 * @inheritDoc
	 */
	public function isPossibleDownloadUnmatchedDeals(): bool
	{
		return !$this->is_outdated
			&& !$this->getPendingDeals()->all()
			&& $this->unmatchedDeals;
	}

	/**
	 * @inheritDoc
	 */
	public function isPossibleDelete(): bool
	{
		return !$this->is_outdated
			&& !$this->getPendingMatchDeals()
			&& !$this->getProcessedDeals()
			&& $this->isPossibleDeleteDivided();
	}

	/**
	 * @inheritDoc
	 */
	public function parseFile(): bool
	{
		return !$this->hasErrors();
	}

	/**
	 * @inheritDoc
	 */
	public function checkFileFormat(): bool
	{
		return true;
	}

	public function getDividedRowsByDates(): array
	{
		return [];
	}

	public function isPossibleDeleteDivided(): bool
	{
		return !$this->getDividedParsers()
			|| isset(json_decode($this->file_additional_info, true)['isFirstPart'])
			|| !$this->getDeals();
	}

	/**
	 * @param DealContainer $deal
	 *
	 * @return string
	 */
	protected function getGroupDealKey(DealContainer $deal): string
	{
		return md5($deal->policy_number . $deal->advisor_name . $deal->parsed_deal_class);
	}

	/**
	 * Combines self deals by policy id, client name and advisor name
	 *
	 * @return $this
	 */
	protected function combineDeals(): self
	{
		$groupedDeals = [];
		foreach ($this->deals as $deal) {
			$key = $this->getGroupDealKey($deal);

			if (isset($groupedDeals[$key])) {
				$groupedDeals[$key]->commission = bcadd($groupedDeals[$key]->commission, $deal->commission, 2);
				$groupedDeals[$key]->premium = bcadd($groupedDeals[$key]->premium, $deal->premium, 2);
				$groupedDeals[$key]->original[] = $deal->original[0];
			} else {
				$groupedDeals[$key] = $deal;
			}
		}

		$this->deals = array_values($groupedDeals);

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function saveDeals(?string $status = null, ?string $errorMessage = null): DocumentParser
	{
		if ($this->isNewRecord) {
			$this->save();
			if ($this->divideToFilesByDates && !self::$activeImportId && $this->resolver->getProcessingDealContainers()) {
				self::$activeImportId = $this->id;
			}
		}

		foreach ($this->resolver->getProcessingDealContainers() as $deal) {
			if ($status) {
				$deal->status = $status;

				if (!$errorMessage && $deal->isManualMatchSolution() && !$deal->isPossibleManualMatch()) {
					$deal->status = DealContainer::STATUS_SKIPPED;
				}
			}

			if ($errorMessage) {
				$deal->solution = DocumentParser::SOLUTION_UNMATCH;
				$deal->errors = ['parser' => [$errorMessage]];
			}

			$deal->commission_import_id = $this->getPrimaryKey();
			$deal->save();
		}

		return $this;
	}

	/**
	 * Returns array of originals from given deals
	 *
	 * @param DealContainer[] $deals
	 *
	 * @return array
	 */
	protected function getReport(array $deals): array
	{
		foreach ($deals as $deal) {
			foreach ($deal->additional_report_data as $title => $value) {
				if (!isset($this->reportTitles[$title])) {
					$this->reportTitles[$title] = Coordinate::stringFromColumnIndex(
						count($this->titles) + count($this->reportTitles) + 1
					);
				}
			}
		}

		$result = [];
		foreach ($deals as $deal) {
			foreach ($deal->original as $row) {
				foreach ($this->reportTitles as $additionalTitle => $additionalTitleKey) {
					$row[$additionalTitleKey] = $deal->additional_report_data[$additionalTitle] ?? null;
				}
				$result[] = $row;
			}
		}

		return $result;
	}

	/**
	 * Returns the parsed name.
	 *
	 * @param string|null $name
	 *
	 * @return string
	 */
	protected function getParseSimpleName(?string $name): ?string
	{
		if (!$name) {
			return null;
		}

		$name = preg_replace('/[^[:print:]]/', ' ', $name);
		$name = preg_replace('/\s+/', ' ', $name);
		$name = explode(',', $name);
		$lastName = preg_replace("/[^`'\- A-Za-z]/",'', trim($name[0]));
		$firstName = isset($name[1]) ? preg_replace("/[^`'\- A-Za-z]/",'', trim($name[1])) : '';

		return trim($firstName . ' ' . $lastName);
	}

	/**
	 * @param int $companyId
	 *
	 * @return array
	 */
	protected function getContractingIdsByCompany(int $companyId): array
	{
		if (isset($this->contractingIds[$companyId])) {
			return $this->contractingIds[$companyId];
		}

		$this->contractingIds[$companyId] = Contracting::find()
			->select(['id'])->where(['company_id' => $companyId])->column();

		return $this->contractingIds[$companyId];
	}

	/**
	 * @param string $type
	 * @param        $userId
	 * @param null $shareUserId
	 *
	 * @param null $stateId
	 * @return bool|mixed
	 */
	public function getEoLicenseChecks(string $type, $userId, $shareUserId = null, $stateId = null): bool
	{
		$key = sprintf(
			self::UNIQUE_KEY_TEMPLATE,
			$userId,
			$shareUserId,
			$type,
			$stateId
		);

		if (!isset($this->eoLicenseChecks[$key])) {
			$this->eoLicenseChecks[$key] = EOLicenseChecker::check($type, $userId, $shareUserId, $stateId);
		}

		return $this->eoLicenseChecks[$key];
	}

	/**
	 * Returns the found agent by contract code.
	 *
	 * @param int         $companyId
	 * @param string|null $code
	 *
	 * @return User|null
	 */
	public function getAgentByContractCode(int $companyId, ?string $code): ?User
	{
		if (!$code) {
			return null;
		}

		$keyAgent = $companyId . '-' . $code;

		if (!array_key_exists($keyAgent, $this->agentsFoundByCode)) {
			$userContracting = UserContracting::findByCode(
				$code, $companyId, $this->getContractingIdsByCompany($companyId)
			);

			$this->agentsFoundByCode[$keyAgent] = $userContracting->user ?? null;
		}

		return $this->agentsFoundByCode[$keyAgent];
	}

	/**
	 * @inheritDoc
	 */
	public function hasNotClosedPreviousImport(): bool
	{
		return self::find()
			->alias('dp')
			->innerJoinWith(['pendingDeals d'], false)
			->where(['dp.is_outdated' => 0])
			->andWhere(['like', 'dp.model', $this->getModelName()])
			->exists();
	}

	/**
	 * @inheritDoc
	 */
	public function hasAccessMatchDeals(): bool
	{
		return $this->user_id === Yii::$app->user->getId();
	}

	/**
	 * Adds additional query settings such as select, where, join and others.
	 *
	 * @param DealQuery $query
	 *
	 * @return DealQuery
	 */
	public function additionalQuerySettings(DealQuery $query): DealQuery
	{
		return $query;
	}

	/**
	 * @return array
	 */
	public function getDealSubTypes(): array
	{
		return defined(static::class . '::DEAL_TYPES') ? static::DEAL_TYPES : [];
	}

	public function isAbleToPushQueue(): bool
	{
		return true;
	}

	public function getClientNameKeys(): array
	{
		return [];
	}

	/**
	 * @return array
	 */
	public function getOriginalAdvisorNameKeys(): array
	{
		return [];
	}

	/**
	 * @return bool
	 */
	public function hasPendingDeals(): bool
	{
		return $this->getPendingDeals()->exists();
	}

	/**
	 * @return bool
	 */
	public function isSkippedSplitRenewals(): bool
	{
		return true;
	}

	public function getDuplicatedAdvisorNames(): array
	{
		$deals = $this->deals;

		$advisorNames = [];
		foreach ($deals as $deal) {
			$advisorNames[] = $deal->advisor_name;
			if ($deal->is_shared) {
				$advisorNames[] = $deal->share_advisor_name;
			}
		}

		$duplicatedAdvisorNames = User::find()
			->select(['CONCAT(user.first_legal_name, " ", user.last_legal_name) as fullName'])
			->groupBy(['fullName'])
			->having(['>', 'COUNT(*)', 1])
			->column();

		return array_intersect($duplicatedAdvisorNames, $advisorNames);
	}
}