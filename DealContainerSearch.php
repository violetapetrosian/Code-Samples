<?php

namespace common\models\CommissionsImport;

use common\helpers\ModelSearch;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Class DealContainerSearch
 *
 * @package common\models
 */
class DealContainerSearch extends DealContainer
{
	public $created_at;

	/**
	 * Sets defaults
	 * DealContainerSearch constructor.
	 *
	 * @param array $config
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);

		$this->status = null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function rules()
	{
		return [
			[['id', 'deal_id'], 'integer'],
			[['client_name', 'advisor_name', 'status',
				'created_at', 'commission', 'solution', 'policy_number'], 'safe'
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function scenarios()
	{
		// bypass scenarios() implementation in the parent class
		return Model::scenarios();
	}

	/**
	 * Creates data provider instance with search query applied
	 *
	 * @param array $params
	 *
	 * @return ActiveDataProvider
	 */
	public function search($params)
	{
		$query = DealContainer::find()
			->alias('cip')
			->joinWith(['documentParser ci'], false)
			->andWhere(['cip.status' => self::PROCESSED_STATUSES])
			->orderBy(['cip.id' => SORT_DESC]);

		if ($params['parserId']) {
			$query->andWhere(['cip.commission_import_id' => $params['parserId']]);
		}

		$dataProvider = new ActiveDataProvider([
			'query' => $query,
		]);

		$this->load($params);

		if (!$this->validate()) {
			// uncomment the following line if you do not want to return any records when validation fails
			// $query->where('0=1');
			return $dataProvider;
		}

		if ($this->created_at) {
			$query->andWhere(array_merge(
					['and'], ModelSearch::getRangeDateFilterReformat($this->created_at, 'ci.created_at'))
			);
		}

		$query->andFilterWhere(['like', 'cip.client_name', $this->client_name])
			->andFilterWhere(['like', 'cip.advisor_name', $this->advisor_name])
			->andFilterWhere(['like', 'cip.policy_number', $this->policy_number])
			->andFilterWhere(['=', 'cip.solution', $this->solution])
			->andFilterWhere(['=', 'cip.status', $this->status])
			->andFilterWhere(['like', 'cip.premium', str_replace(['$', ','], '', $this->premium)])
			->andFilterWhere(['like', 'cip.commission', str_replace(['$', ','], '', $this->commission)]);

		return $dataProvider;
	}

}