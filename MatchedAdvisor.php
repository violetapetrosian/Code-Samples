<?php

namespace common\models\CommissionsImport;

use common\helpers\Html;
use common\models\ForbidDealUser;
use common\models\User;

use kartik\select2\Select2;

use yii\base\Model;
use yii\web\JsExpression;

use Yii;

/**
 * Class MatchedAdvisor
 *
 * @package common\models\CommissionsImport
 */
class MatchedAdvisor extends Model
{

	/**
	 * @var int
	 */
	public $id;

	/**
	 * @var User
	 */
	public $user;

	/**
	 * @var DealContainer
	 */
	public $deal;

	/**
	 * @var bool
	 */
	public $isFooter = false;

	/**
	 * @var bool
	 */
	public $isSharedFooter = false;

	/**
	 * @var bool
	 */
	public $isDisabled = false;

	/**
	 * {@inheritDoc}
	 */
	public function rules(): array
	{
		return [['id', 'deal'], 'required'];
	}

	/**
	 * Returns agent name, or search input.
	 *
	 * @return string|null
	 * @throws \Exception
	 */
	public function getFullName(): ?string
	{
		$checks = $this->deal->parsed_deal_class && $this->deal->commission > 0
			? ForbidDealUser::EVER_HAD_CHECK | EOLicenseChecker::getChecksForType($this->deal->parsed_deal_class)
			: ForbidDealUser::EVER_HAD_CHECK;

		$enableForbidCheck = Yii::$app->params['enable_import_forbid_check'] ?? true;

		if ($this->user) {
			$errorMsg = '';
			if ($this->isDisabled) {
				$errorMsg = '<p style="color: #B53434; font-size: 11px;">Agent\'s License or EO is expired</p>';
			}

			return $this->user->getLegalFullName() . $errorMsg;
		}

		$bulkIndex = $this->getFooterSelector('-', $this->deal->id);

		return Select2::widget(
				Html::getAdvisorSelect2Config(
					(new User()), 'user_id', '',
					[
						'options' =>
							[
								'class' => $this->getFooterSelector('manual_', 'advisor_input') . ' manual-advisor custom-select2',
								'id' => $this->getFooterSelector('manual_', 'user_input') . $this->deal->id,
								'data-bulk-index' => $bulkIndex,
								'data-forbid-checks' => $checks,
								'data-forbid-check-home-state' => $this->deal->commission > 0 && $enableForbidCheck ? 1 : 0,
							],
						'pluginOptions' => [
							'templateResult' => new JsExpression('formatItemListForm'),
							'ajax' => ['url' => new JsExpression('getUrlToList')],
						],
					]
				)
			)
			. Html::tag('br')
			. Html::tag('label')
			. Html::checkbox('[' . $this->deal->id . ']' . $this->getFooterSelector('is_', 'deleted'), false, [
				'class' => 'user-list-is-deleted' . $bulkIndex,
			]) . ' Deleted/terminated users'
			. Html::endTag('label');
	}

	/**
	 * Returns the fs code of the agent.
	 *
	 * @return string|null
	 */
	public function getFsCode(): ?string
	{
		return $this->user ? $this->user->getAdvisorFsCode() : null;
	}

	/**
	 * Returns the status of the agent.
	 *
	 * @return string|null
	 */
	public function getStatus(): ?string
	{
		return $this->user ? $this->user->status : null;
	}

	/**
	 * Returns a radiobox for agent selection.
	 *
	 * @return string|null
	 */
	public function getMatchedRadioBox(): string
	{
		$options = $this->user
			? [
				'checked' => $this->id == 1 && !$this->isDisabled,
				'disabled' => $this->isDisabled
			]
			: ['class' => 'manualSelectedAdvisor'];

		return Html::input(
			'radio',
			"deals[" . $this->deal->id . "][matched_advisor_id]",
			$this->user->user_id ?? '',
			$options
		);
	}

	/**
	 * Returns a radiobox for share agent selection.
	 *
	 * @return string|null
	 */
	public function getShareMatchedRadioBox(): string
	{
		$options = $this->user
			? [
				'checked' => $this->id == 2 && !$this->isDisabled,
				'disabled' => $this->isDisabled
			]
			: ['class' => 'manualSelectedShareAdvisor'];

		return Html::input(
			'radio',
			"deals[" . $this->deal->id . "][matched_share_advisor_id]",
			$this->user->user_id ?? '',
			$options
		);
	}

	/**
	 * Returns the name of the selector inside the footer.
	 *
	 * @param string $alias
	 * @param string $name
	 *
	 * @return string
	 */
	private function getFooterSelector(string $alias, string $name): string
	{
		return $alias . ($this->isSharedFooter ? 'share_' : '') . $name;
	}

}
