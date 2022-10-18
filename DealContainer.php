<?php

namespace common\models\CommissionsImport;

use common\behaviors\JsonBehavior;

use common\behaviors\StatisticsBehavior;
use common\behaviors\StatusBehavior;
use common\helpers\HArray;
use common\helpers\Html;
use common\jobs\ImportStatementJob;
use common\models\CommissionsImport\Parsers\DocumentParser;
use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use common\models\CommissionsImport\Solutions\UnmatchSolution;
use common\models\Renewals;
use common\models\User;
use common\models\UserContracting;

use frontend\models\Company;
use frontend\models\Deal;
use frontend\models\DealInterface;
use frontend\models\InsuranceDeals;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\Url;

/**
 * Container for parsing result.
 * Class DealContainer
 *
 * @package common\models\CommissionsImport
 * @property array                $original                    array or json of original rows that used for create this deal container
 * @property string               $client_name
 * @property string               $advisor_name                concat of first and last name separated by space
 * @property string               $share_advisor_name          concat of first and last name separated by space
 * @property string               $policy_number
 * @property boolean              $is_shared
 * @property int                  $share_percent
 * @property float                $commission
 * @property float                $premium
 * @property int                  $deal_id                     matched deal id after user action
 * @property int                  $matched_advisor_id          matched advisor id after user action
 * @property int                  $matched_share_advisor_id    matched advisor id after user action
 * @property string|DealInterface $parsed_deal_class           deal class from imported file
 * @property string               $matched_deal_class          deal class matched after user action
 * @property string               $created_deal_class          deal class created after cron action
 * @property int                  $created_deal_id             created deal id after action
 * @property string               $solution
 * @property string               $status
 * @property string               $additional_report_data_json
 * @property string               $advisorFirstName
 * @property string               $advisorLastName
 * @property string               $shareAdvisorFirstName
 * @property string               $shareAdvisorLastName
 * @property int                  $id                          [int(11)]
 * @property int                  $commission_import_id        [int(11)]
 * @property string               $original_json
 * @property string               $errors_json
 * @property string               $created_at                  [datetime]
 * @property string               $updated_at                  [datetime]
 * @property string               $additional_data_json
 * @property string               $fullOwnerName
 * @property string               $contract_code
 * @property string               $transaction_type
 *
 * @property DocumentParser       $documentParser
 */
class DealContainer extends ActiveRecord
{
	const STATUS_PENDING = 'pending';
	const STATUS_PENDING_MATCH = 'pendingMatch';
	const STATUS_PENDING_ADMIN = 'pendingAdmin';
	const STATUS_APPLIED = 'applied';
	const STATUS_SKIPPED = 'skipped';
	const STATUS_ERROR = 'error';

	const PROCESSED_STATUSES = [
		self::STATUS_PENDING => self::STATUS_PENDING,
		self::STATUS_APPLIED => self::STATUS_APPLIED,
		self::STATUS_ERROR => self::STATUS_ERROR,
	];

	const PENDING_STATUSES = [
		self::STATUS_PENDING_MATCH => self::STATUS_PENDING_MATCH,
		self::STATUS_PENDING_ADMIN => self::STATUS_PENDING_ADMIN,
		self::STATUS_PENDING => self::STATUS_PENDING,
	];

	const STATUSES = [
		self::STATUS_PENDING_MATCH => self::STATUS_PENDING_MATCH,
		self::STATUS_PENDING_ADMIN => self::STATUS_PENDING_ADMIN,
		self::STATUS_PENDING => self::STATUS_PENDING,
		self::STATUS_APPLIED => self::STATUS_APPLIED,
		self::STATUS_ERROR => self::STATUS_ERROR,
	];

	const EXPLANATION_ERROR_GLUE = ' | ';

	/**
	 * @var array
	 */
	public $explanationErrorArray = [];

	/**
	 * @var RelatedDealsManager
	 */
	public $relatedDealsManager;

	/**
	 * @var array
	 */
	public $relevantAdvisors = [];

	/**
	 * @var array
	 */
	public $foundedRelatedDeals = [];

	public $hasDealWithoutBlockedCompany;
	public $hasAgentWithoutEoOrLicense = false;
	public $industrialAlliancePlan;
	public $forceToUnmatch = false;
	public $toUnmatchWithoutAdvisorName = false;
	public $excludeFromManualMatch = false;
	public $skipToUnmatchReport = false;

	public $withoutEoOrLicenseExplanation = '';

	/**
	 * @var array
	 */
	public $original = [];

	/**
	 * @var array
	 */
	public $errors = [];

	/**
	 * @var array
	 */
	public $additional_data = [];

	/**
	 * Additional data for report.
	 *
	 * @var array
	 */
	public $additional_report_data = [];

	/**
	 * @var DocumentParserInterface
	 */
	protected $parser;

	/**
	 * @var array
	 */
	public static $dealContainerFullOwnerNames = [];

	/**
	 * Sets defaults
	 * DealContainer constructor.
	 *
	 * @param array $config
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);

		$this->status = self::STATUS_PENDING_MATCH;
	}

	/**
	 * @inheritDoc
	 */
	public static function tableName()
	{
		return '{{%commission_import_parsed}}';
	}

	/**
	 * @inheritDoc
	 */
	public function behaviors(): array
	{
		return [
			'json' => [
				'class' => JsonBehavior::class,
				'properties' => ['original', 'errors', 'additional_data', 'additional_report_data'],
				'jsonFields' => [
					'original' => 'original_json',
					'errors' => 'errors_json',
					'additional_data' => 'additional_data_json',
					'additional_report_data' => 'additional_report_data_json',
				],
			],
			'timestamp' => [
				'class' => TimestampBehavior::class,
				'value' => function() {
					return date('Y-m-d H:i:s');
				},
			],
			'statistics' => [
				'class' => StatisticsBehavior::class,
				'skipEvents' => ['afterInsert'],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function beforeSave($insert)
	{
		if (isset($this->additional_report_data['Explanation'])) {
			$existsErrors = explode(self::EXPLANATION_ERROR_GLUE, $this->additional_report_data['Explanation']);
			$this->explanationErrorArray = array_merge($existsErrors, $this->explanationErrorArray);
		}
		$this->additional_report_data['Explanation'] = implode(
			self::EXPLANATION_ERROR_GLUE,
			array_unique($this->explanationErrorArray)
		);

		$this->additional_data['properties'] = $this->getAdditionalPropertiesList();

		return parent::beforeSave($insert);
	}

	/**
	 * @inheritDoc
	 */
	public function afterSave($insert, $changedAttributes)
	{
		parent::afterSave($insert, $changedAttributes);

		if ($insert && $this->status === self::STATUS_PENDING_MATCH && $this->getParser()->isAbleToPushQueue()) {
			Yii::$app->importStatementQueue->push(new ImportStatementJob([
				'dealContainerId' => $this->id,
			]));
		}
	}

	/**
	 * @inheritDoc
	 */
	public function afterFind()
	{
		parent::afterFind();

		if (!isset($this->additional_data['properties']) || !is_array($this->additional_data['properties'])) return;

		foreach ($this->additional_data['properties'] as $property => $value) {
			if ($this->hasProperty($property)) {
				$this->{$property} = $value;
			}
		}
	}

	/**
	 * @return ActiveQuery
	 */
	public function getDocumentParser(): ActiveQuery
	{
		return $this->hasOne(DocumentParser::class, ['id' => 'commission_import_id']);
	}

	/**
	 * Creates exact DocumentParser from founded DocumentParser in getDocumentParser
	 *
	 * @return DocumentParserInterface
	 */
	public function getParser(): DocumentParserInterface
	{
		if (empty($this->parser)) {
			$this->parser = $this->documentParser->getInitializedParser();
		}

		return $this->parser;
	}

	/**
	 * returns advisor first name parsed from full name
	 *
	 * @return string
	 */
	public function getAdvisorFirstName(): string
	{
		$name = explode(' ', $this->advisor_name);

		return $name[0];
	}

	/**
	 * returns share advisor first name parsed from full name
	 *
	 * @return string
	 */
	public function getShareAdvisorFirstName(): string
	{
		$name = explode(' ', $this->share_advisor_name);

		return $name[0];
	}

	/**
	 * Returns advisor last name parsed from full name
	 *
	 * @return string
	 */
	public function getAdvisorLastName(): string
	{
		$name = explode(' ', $this->advisor_name);
		array_shift($name);

		return !empty($name) ? implode(' ', $name) : '';
	}

	/**
	 * Returns share advisor last name parsed from full name
	 *
	 * @return string
	 */
	public function getShareAdvisorLastName(): string
	{
		$name = explode(' ', $this->share_advisor_name);
		array_shift($name);

		return !empty($name) ? implode(' ', $name) : '';
	}

	/**
	 * Returns parsed deal type
	 */
	public function getParsedDealTypeAlias(?DocumentParser $parser = null)
	{
		if ($this->parsed_deal_class == InsuranceDeals::class) {
			$parser = $parser ?: $this->getParser();

			return implode('/', $parser::DEAL_TYPES);
		}

		foreach (Deal::getDealModelsName() as $deal) {
			if (('\\' . $this->parsed_deal_class) == $deal['dealFull']) {

				return $deal['caption'];
			}
		}

		return '';
	}

	/**
	 * @param string $classField
	 * @param string $idField
	 *
	 * @return string|null
	 */
	public function getMatchedDealUrl($classField = 'matched_deal_class', $idField = 'deal_id'): ?string
	{
		if ($this->$classField === InsuranceDeals::class) {
			$deal = InsuranceDeals::findOne($this->$idField);

			return $deal ? Url::to(['insurance/view', 'id' => $this->$idField, 'type' => $deal->type]) : null;
		}

		foreach (Deal::getDealModelsName() as $deal) {
			if (('\\' . $this->$classField) === $deal['dealFull']) {
				if (!$this->$idField) {
					return null;
				}

				$actionView = ArrayHelper::toArray($deal['actionView']);

				return Url::to(array_merge($actionView, ['id' => $this->$idField]));
			}
		}

		return null;
	}

	/**
	 * Returns the company name from a matched deal.
	 *
	 * @return Company|null
	 */
	public function getMatchedCompany(): ?Company
	{
		if (!$this->deal_id || !$this->matched_deal_class) {
			return null;
		}

		/**
		 * @var Deal $deal
		 */
		$deal = $this->matched_deal_class::findOne($this->deal_id);

		if ($deal && $deal->hasMethod('getCompany') && $company = $deal->company) {
			return $company;
		}

		return null;
	}

	/**
	 * @return string
	 */
	public function getUploadedDealUrl(): ?string
	{
		return $this->getMatchedDealUrl('created_deal_class', 'created_deal_id');
	}

	/**
	 * returns self json encoded
	 *
	 * @return string
	 */
	public function __toString()
	{
		try {
			$string = Json::encode([
				'client_name' => $this->client_name,
				'advisor_name' => $this->advisor_name,
				'share_advisor_name' => $this->share_advisor_name,
				'is_shared' => $this->is_shared,
				'policy_number' => $this->policy_number,
				'commission' => $this->commission,
				'original' => $this->original,
				'deal_id' => $this->deal_id,
			]);
		} catch (\Exception $e) {
			$string = '[]';
		}

		return $string;
	}

	/**
	 * @return DealInterface[]
	 */
	public function getDealsForMatching(): array
	{
		switch (true) {
			case $this->commission < 0 && count($this->possibleChargeBackDeals) :

				return $this->possibleChargeBackDeals;
			case count($this->relevantDeals) :

				return $this->relevantDeals;

			default :

				return $this->paidDeals;
		}
	}

	/**
	 * @return string
	 */
	public function getFullOwnerName($duplicatedAdvisorNames = []): string
	{
		$advisorName = static::$dealContainerFullOwnerNames[$this->advisor_name] ?? null;
		if (is_null($advisorName)) {
			$advisorName = static::$dealContainerFullOwnerNames[$this->advisor_name]
				= $this->getAdvisorFullName($this->advisor_name);
		}

		if ($this->is_shared) {
			$sharedAdvisorName = static::$dealContainerFullOwnerNames[$this->share_advisor_name] ?? null;
			if (is_null($sharedAdvisorName)) {
				$sharedAdvisorName = static::$dealContainerFullOwnerNames[$this->share_advisor_name]
					= $this->getAdvisorFullName($this->share_advisor_name, 'sharedUser');
			}

			$fullOwnerName = '';
			if (in_array($this->advisor_name, $duplicatedAdvisorNames)) {
				$fullOwnerName .= Html::tag('span', $advisorName, ['class' => 'text-danger']);
			} else {
				$fullOwnerName .= $advisorName;
			}

			if (in_array($this->share_advisor_name, $duplicatedAdvisorNames)) {
				$fullOwnerName .= ' / ' . Html::tag('span', $sharedAdvisorName, ['class' => 'text-danger']);
			} else {
				$fullOwnerName .= ' / ' . $sharedAdvisorName;
			}

			return $fullOwnerName;
		}

		return in_array($this->advisor_name, $duplicatedAdvisorNames)
			? Html::tag('p', $advisorName, ['class' => 'text-danger'])
			: $advisorName;
	}

	/**
	 * Getting user full name with fs code
	 *
	 * @param string|null $name
	 * @param string      $userKey
	 *
	 * @return string
	 */
	public function getAdvisorFullName(?string $name, $userKey = 'user'): ?string
	{
		$user = $this->additional_data[$userKey] ?? null;
		if ($user) {
			$advisor = is_array($user) ? new User($user) : $user;
		}

		if (!isset($advisor)) {
			$fsCode = $this->additional_data['fsCode'] ?? null;
			if ($fsCode) {
				/** @var User $advisor */
				$advisor = User::findAdvisorByFSCode($this->additional_data['fsCode']);
			} elseif ($this->contract_code) {
				if ($this->is_shared) {
					$this->contract_code = $this->additional_data[$userKey . 'ContractCode'] ?? null;
				}
				$advisor = User::find()
					->where($this->getContractCodeCondition($this->getParser()::COMPANIES_IDS, $userKey))
					->one();
			}
		}

		if (!isset($advisor)) {
			$advisor = User::find()
				->where($this->getRegUserNameCondition())
				->andWhere(['user.status' => StatusBehavior::STATUS_ACTIVE])
				->one();
		}

		return $advisor ? $advisor->getNameWithCode() : $name;
	}

	/**
	 * Returns a list of agents for a manual match.
	 *
	 * @param DocumentParserInterface|null $parser
	 *
	 * @return array
	 */
	public function getAdvisorsForManualMatching(?DocumentParserInterface $parser = null): array
	{
		$users = [];

		$id = 0;
		foreach ($this->relevantAdvisors as $relevantAdvisor) {
			$relevantAdvisor = is_array($relevantAdvisor) ? new User($relevantAdvisor) : $relevantAdvisor;

			$users[] = new MatchedAdvisor([
				'id' => ++$id,
				'user' => $relevantAdvisor,
				'deal' => $this,
				'isDisabled' => $parser
					&& !$parser->getEoLicenseChecks($this->parsed_deal_class, $relevantAdvisor->user_id)
					&& !$this->getIsShared(),
			]);
		}

		$users[] = new MatchedAdvisor(['id' => ++$id, 'deal' => $this, 'isFooter' => true]);

		if ($this->is_shared) {
			$users[] = new MatchedAdvisor(['id' => ++$id, 'deal' => $this, 'isSharedFooter' => true]);
		}

		return $users;
	}

	/**
	 * Pulls the agent from the hash.
	 *
	 * @return User|array|null
	 */
	public function getSavedAgent()
	{
		if (array_key_exists('user', $this->additional_data)) {
			return $this->additional_data['user'];
		}

		if (isset($deal->additional_data['fsCode'])) {
			return User::findAdvisorByFSCode($deal->additional_data['fsCode']);
		}

		return null;
	}

	/**
	 * Checks if a deal is split.
	 *
	 * @return bool
	 */
	public function getIsShared(): bool
	{
		/** @var DocumentParserInterface $parser */
		$parser = $this->getParser();
		$checkRenewals = $parser ? $parser->isSkippedSplitRenewals() : true;

		if ($this->is_shared) {
			return true;
		}

		if ($this->share_percent
			&& $this->share_percent != 100
			&& ($this->parsed_deal_class !== Renewals::class || !$checkRenewals)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Checks the solution for the possibility of a manual match.
	 *
	 * @return bool
	 */
	public function isManualMatchSolution(): bool
	{
		if ($this->excludeFromManualMatch) {
			return false;
		}

		return in_array($this->solution, DocumentParserInterface::MANUAL_MATCH_SOLUTIONS);
	}

	/**
	 * Checks the permission for the manual match.
	 *
	 * @return bool
	 */
	public function isPossibleManualMatch(): bool
	{
		return $this->commission != 0
			&& $this->advisor_name !== 'Experior'
			&& $this->advisor_name !== UnmatchSolution::UNMATCH_ADVISOR_NAME
			&& $this->contract_code != UnmatchSolution::UNMATCH_ADVISOR_CODE
			&& !$this->toUnmatchWithoutAdvisorName
			&& !$this->hasAgentWithoutEoOrLicense
			&& $this->hasDealWithoutBlockedCompany !== false
			&& !$this->excludeFromManualMatch
			&& !$this->skipToUnmatchReport;
	}

	/**
	 * Check that the transaction is waiting for processing by the admin.
	 *
	 * @return bool
	 */
	public function isPendingAdmin(): bool
	{
		return $this->status === self::STATUS_PENDING_ADMIN;
	}

	/**
	 * Returns the search term for a user based on contract code.
	 *
	 * @param int|array $companyId
	 *
	 * @return int[]
	 */
	public function getContractCodeCondition($companyId, $userKey = 'user'): array
	{
		if (array_key_exists($userKey, $this->additional_data)) {
			$user = $this->additional_data[$userKey] ?? null;
			return ['user.user_id' => $user['user_id'] ?? -1];
		}

		$userContracting = UserContracting::findByCode($this->contract_code, $companyId);

		return ['user.user_id' => $userContracting ? $userContracting->user_id : -1];
	}


	/**
	 * Add search conditions by firstname and lastname for advisor
	 *
	 * @param string|null $firstName
	 * @param string|null $lastName
	 * @param array $nameCondition
	 *
	 * @return array
	 */
	public function addRegUserNameConditionByNames(?string $firstName, ?string $lastName, array $nameCondition): array
	{
		foreach (['', 'legal_'] as $alias) {
			$nameCondition[] = [
				'and',
				['REGEXP', 'first_' . $alias . 'name', $firstName],
				['REGEXP', 'last_' . $alias . 'name', $lastName],
			];
			$nameCondition[] = [
				'and',
				['REGEXP', 'first_' . $alias . 'name', $lastName],
				['REGEXP', 'last_' . $alias . 'name', $firstName],
			];
		}
		return $nameCondition;
	}

	/**
	 * Returns the search term for a user based on REGEXP name.
	 *
	 * @return string[]
	 */
	public function getRegUserNameCondition(): array
	{
		$conditions = [
			$this->getRegNameCondition($this->advisor_name),
			$this->getRegNameCondition($this->share_advisor_name),
		];
		$nameCondition = ['or'];

		foreach ($conditions as $condition) {
			if (!$condition['firstName']) continue;
			$nameCondition = $this->addRegUserNameConditionByNames($condition['firstName'], $condition['lastName'], $nameCondition);
		}
		return $nameCondition;
	}

	/**
	 * @param string $explanation
	 */
	public function setExplanationToDeal(string $explanation): void
	{
		$this->explanationErrorArray[] = $explanation;
	}

	/**
	 * @return string
	 * Get advisor original name from file
	 */
	public function getOriginalAdvisorName()
	{
		$parserTitles = $this->documentParser ? $this->documentParser->titles : [];

		$originalAdvisorFullNames = [];
		foreach ($this->original as $originalItem)
		{
			$advisorFullName = '';
			foreach ($this->getParser()->getOriginalAdvisorNameKeys() as $originalAdvisorNameKey) {
				$advisorNameKey = array_search($originalAdvisorNameKey, $parserTitles);

				if ($advisorNameKey === false) {
					continue;
				}

				if (array_key_exists($advisorNameKey, $originalItem)) {
					$advisorFullName .= ' ' . $originalItem[$advisorNameKey];
				}
			}

			$originalAdvisorFullNames[] = trim($advisorFullName);
		}

		return implode('; ', array_unique($originalAdvisorFullNames));
	}

	/**
	 * Returns an array of related deals for this pack of deals.
	 *
	 * @param array $deals
	 *
	 * @return array
	 */
	public static function getBatchRelatedDeals(array $deals): array
	{
		$foundedDeals = HArray::arrayValuesByLevel(array_column($deals, 'foundedRelatedDeals'), 1);
		$foundedDeals = HArray::getGroupArray($foundedDeals, 'dealClass');

		$relatedDeals = [];
		foreach ($foundedDeals as $dealClass => $fDeals) {
			$relatedDeals[$dealClass] = $dealClass::find()
				->where(['id' => array_column($fDeals, 'dealId')])
				->indexBy('id')->all();
		}

		return $relatedDeals;
	}

	/**
	 * Returns a regular expression to search for a user by name.
	 *
	 * @param string|null $advisorName
	 *
	 * @return array
	 */
	private function getRegNameCondition(?string $advisorName): array
	{
		$advisorName = preg_replace('/[^\w\s\d-]/', '', $advisorName);
		$words = explode(' ', trim($advisorName));

		$getNameCondition = function ($words, $reverse = false) {
			$conditions = [];

			foreach ($words as $word) {
				if (in_array($word, ['Extra', 'EXTRA', '2nd', ''])) continue;

				if (!$conditions) {
					$conditions[] = [$word];
					continue;
				}

				$lastCondition = $conditions[array_key_last($conditions)];

				$conditions[] = $reverse? array_merge([$word], $lastCondition) : array_merge($lastCondition, [$word]);
			}

			$conditionName = '';
			foreach ($conditions as $condition) {
				$condition = implode(' +', $condition);

				if (!$conditionName) {
					$conditionName = '^' . $condition;
					continue;
				}

				$conditionName = '^' . $condition . '|' . $conditionName;
			}

			return $conditionName;
		};

		return [
			'firstName' => $getNameCondition($words),
			'lastName' => $getNameCondition(array_reverse($words), true),
		];
	}

	/**
	 * Returns an array of properties to save.
	 *
	 * @return array
	 */
	private function getAdditionalPropertiesList(): array
	{
		return [
			'foundedRelatedDeals' => $this->relatedDealsManager ? $this->relatedDealsManager->getDealsWithClass() : [],
			'relevantAdvisors' => $this->relevantAdvisors,
			'hasDealWithoutBlockedCompany' => $this->hasDealWithoutBlockedCompany,
			'hasAgentWithoutEoOrLicense' => $this->hasAgentWithoutEoOrLicense,
			'industrialAlliancePlan' => $this->industrialAlliancePlan,
			'forceToUnmatch' => $this->forceToUnmatch,
			'toUnmatchWithoutAdvisorName' => $this->toUnmatchWithoutAdvisorName,
			'excludeFromManualMatch' => $this->excludeFromManualMatch,
			'skipToUnmatchReport' => $this->skipToUnmatchReport,
			'withoutEoOrLicenseExplanation' => $this->withoutEoOrLicenseExplanation,
		];
	}

}