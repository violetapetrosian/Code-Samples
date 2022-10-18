<?php

namespace common\models\CommissionsImport;

use common\helpers\ModelSearch;
use common\models\CommissionsImport\Parsers\AhaHomeAndAutoParser;
use common\models\CommissionsImport\Parsers\BerkleyTravelParser;
use common\models\CommissionsImport\Parsers\DestinationTravelTravelParser;
use common\models\CommissionsImport\Parsers\DocumentParser;
use common\models\CommissionsImport\Parsers\EdgeBenefitsTravelAndHealthParser;
use common\models\CommissionsImport\Parsers\ForestersInsuranceAndRenewalsParser;
use common\models\CommissionsImport\Parsers\GmsHealthTravelAndRenewalParser;
use common\models\CommissionsImport\Parsers\GreenShieldHealthAndRenewalParser;
use common\models\CommissionsImport\Parsers\IndustrialAllianceInsuranceAndRenewalsParser;
use common\models\CommissionsImport\Parsers\IngleTravelParser;
use common\models\CommissionsImport\Parsers\MyBrokersHomeAndAutoParser;
use common\models\CommissionsImport\Parsers\SsqInsuranceAndRenewalsParser;

use yii\data\ActiveDataProvider;

/**
 * Class DocumentParserSearch
 *
 * @package common\models
 */
class DocumentParserSearch extends DocumentParser
{

	/**
	 * {@inheritdoc}
	 */
	public function rules(): array
	{
		return [
			[['id', 'user_id'], 'integer'],
			[['is_outdated'], 'boolean'],
			[['model', 'titles', 'file_name'], 'string'],
			[['created_at'], 'safe'],
		];
	}

	/**
	 * @param array|null $params
	 *
	 * @return ActiveDataProvider
	 */
	public function search(?array $params): ActiveDataProvider
	{
		$query = DocumentParser::find()
			->alias('dp')
			->distinct()
			->innerJoinWith('dealsRelation', false)
			->orderBy(['dp.id' => SORT_DESC]);

		$dataProvider = new ActiveDataProvider([
			'query' => $query,
		]);

		$this->load($params);

		if (!$this->validate()) {
			return $dataProvider;
		}

		$this->is_outdated = $this->is_outdated ?? 0;

		if ($this->created_at) {
			$query->andWhere(array_merge(
					['and'], ModelSearch::getRangeDateFilterReformat($this->created_at, 'ci.created_at'))
			);
		}

		$query->andFilterWhere(['=', 'dp.user_id', $this->user_id])
			->andFilterWhere(['=', 'dp.is_outdated', $this->is_outdated])
			->andFilterWhere(['like', 'dp.file_name', $this->file_name])
			->andFilterWhere(['like', 'dp.model', $this->model]);

		return $dataProvider;
	}

	/**
	 * Returns an array of users for the filter.
	 *
	 * @return array
	 */
	public static function getAdminList(): array
	{
		$users = DocumentParser::find()
			->alias('dp')
			->select(['dp.user_id', 'CONCAT(u.first_legal_name, " ", u.last_legal_name) as name'])
			->joinWith(['user u'], false)
			->groupBy('dp.user_id')
			->asArray()->all();

		return array_column($users, 'name', 'user_id');
	}

	/**
	 * Returns an array of companies for the filter.
	 *
	 * @return string[]
	 */
	public static function getParsersList(): array
	{
		return [
			AhaHomeAndAutoParser::class => 'Aha',
			BerkleyTravelParser::class => 'Berkley',
			DestinationTravelTravelParser::class => 'Destination Travel',
			EdgeBenefitsTravelAndHealthParser::class => 'Edge Benefits',
			ForestersInsuranceAndRenewalsParser::class => 'Foresters',
			GmsHealthTravelAndRenewalParser::class => 'GMS',
			GreenShieldHealthAndRenewalParser::class => 'Green Shield',
			IndustrialAllianceInsuranceAndRenewalsParser::class => 'Industrial Alliance',
			IngleTravelParser::class => 'Ingle',
			MyBrokersHomeAndAutoParser::class => 'My Brokers',
			SsqInsuranceAndRenewalsParser::class => 'SSQ',
		];
	}

}