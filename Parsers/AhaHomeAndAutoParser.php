<?php

namespace common\models\CommissionsImport\Parsers;

use common\helpers\Money;
use common\models\CommissionsImport\DealContainer;
use common\models\CommissionsImport\Resolvers\AhaResolver;
use common\models\NBTUnlicensed;

use frontend\models\Company;
use frontend\models\UnlicensedDeal;

/**
 * Class AhaHomeAndAutoParser
 *
 * @package common\models\CommissionsImport\Parsers
 */
class AhaHomeAndAutoParser extends ExcelDocumentsParser
{
	const COMPANIES_IDS = [Company::AHA_UNLICENSED_DEALS_ID];
	const DEAL_TYPES = [NBTUnlicensed::PRODUCT_TYPE_AHA];
	const TOTAL_AMOUNT_TITLE = 'Grand Total';

	const ORIGINAL_ADVISOR_NAME_KEYS = ['Advisor'];

	protected $titlesIndex = 2;
	protected $columnsRange = ['A', 'L'];

	protected $resolverClass = AhaResolver::class;

	/**
	 * @var array $requiredFields
	 */
	protected $requiredFields = [
		'Advisor',
		'Pmt',
//		'Fs',
	];

	/**
	 * Array of matching rules for file columns
	 * @var array
	 */
	protected $rules = [
		'Advisor' => [
			'match' => 'advisor_name',
			'method' => 'parseAdvisorName',
		],
		'Pmt' => [
			'match' => 'commission',
			'method' => 'parseCommission',
			'money' => true,
		],
		'Gwp' => [
			'match' => 'commission',
			'money' => true,
		],
		'Ig Comm' => [
			'match' => 'commission',
			'money' => true,
		],
		'%to Exp' => [
			'match' => 'commission',
			'money' => true,
		],
		'Fs' => [
			'match' => 'additional_data',
			'method' => 'parseAdvisorCode',
		],
	];

	/**
	 * @inheritDoc
	 */
	public function checkFileFormat(): bool
	{
		parent::checkFileFormat();

		if (count($this->sheets) != 1) {
			$this->addError('sheets_count', 'The file should contain exactly one sheet!');
		}

		return !$this->hasErrors();
	}

	/**
	 * @return array|string[]
	 */
	public function getOriginalAdvisorNameKeys(): array
	{
		return self::ORIGINAL_ADVISOR_NAME_KEYS;
	}

	/**
	 * @inheritDoc
	 */
	protected function setDealDefaults(DealContainer $deal)
	{
		$deal->parsed_deal_class = UnlicensedDeal::class;
		$deal->client_name = 'AHA';
		$deal->premium = 0.0;
	}

	/**
	 * @inheritDoc
	 */
	public function getMatchingDealClasses(): array
	{
		return [UnlicensedDeal::class];
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $advisorName
	 */
	protected function parseAdvisorName(DealContainer $deal, ?string $advisorName)
	{
		if (trim($advisorName) == self::TOTAL_AMOUNT_TITLE) {
			$deal->advisor_name = null;
			return;
		}
		if (!$advisorName) {
			$deal->advisor_name = '';
			$deal->forceToUnmatch = true;
			$deal->toUnmatchWithoutAdvisorName = true;
			return;
		}
		$advisorName = preg_replace('/[^a-zA-Z \-`\']/', '', $advisorName);
		$advisorName = explode(' ', trim($advisorName));
		foreach ($advisorName as &$part) {
			$part = ucfirst(strtolower($part));
		}
		unset($part);

		$deal->advisor_name = implode(' ', $advisorName);
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $fsCode
	 */
	protected function parseAdvisorCode(DealContainer $deal, ?string $fsCode)
	{
		$deal->additional_data['fsCode'] = $fsCode;
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $value
	 */
	protected function parseCommission(DealContainer $deal, ?string $value)
	{
		$deal->commission = $this->parseMoneyColumn($value);
	}

	/**
	 * @param DealContainer $deal
	 * @param null|string   $value
	 */
	protected function parsePremium(DealContainer $deal, ?string $value)
	{
		$deal->premium = $this->parseMoneyColumn($value);
	}

	/**
	 * @param $value
	 *
	 * @return float|null
	 */
	private function parseMoneyColumn($value)
	{
		$value = str_replace(',', '.', $value);

		return preg_match('/[0-9]/', $value) ? Money::toFloat($value) : null;
	}

	public function getClientNameKeys(): array
	{
		return [];
	}

}