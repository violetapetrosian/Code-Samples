<?php

namespace common\models\CommissionsImport;

use common\models\CommissionsImport\Parsers\DocumentParserInterface;
use Exception;

use yii\base\BaseObject;
use yii\helpers\BaseInflector;

/**
 * Simple factory for create document parsers
 * Class ParserFactory
 *
 * @package common\models\CommissionsImport
 */
class ParserFactory extends BaseObject
{
	public $company;
	public $dealType;

	/**
	 * ParserFactory constructor.
	 *
	 * @param string $company
	 * @param string $dealType
	 * @param array  $config
	 */
	public function __construct(string $company, string $dealType, $config = [])
	{
		parent::__construct($config);

		$this->company = $company;
		$this->dealType = $dealType;
	}

	/**
	 * returns parser class instance by id or new if not found
	 *
	 * @param string|null $id id of parser class
	 *
	 * @return DocumentParserInterface
	 * @throws Exception
	 */
	public function createParser($id = null): DocumentParserInterface
	{
		$parser = null;
		$class = $this->makeClassName();
		if (class_exists($class)) {
			if ($id) {
				$parser = $class::findOne($id);
			}

			if (!$id || !$parser) {
				$parser = new $class();
			}

			return $parser;
		}

		throw new Exception('File not supported');
	}

	/**
	 * Compiles class full name from company name and deal type.
	 *
	 * @return string
	 */
	public function makeClassName()
	{
		$companyName = $this->normalizeString($this->company);
		$dealType = $this->normalizeString($this->dealType);

		return __NAMESPACE__ . '\\Parsers\\' . $companyName . $dealType . 'Parser';
	}

	/**
	 * @param $string
	 *
	 * @return string
	 */
	protected function normalizeString($string)
	{
		return BaseInflector::camelize(strtolower(preg_replace("/[^A-Za-z ]/", '', $string)));
	}

}