<?php

namespace Grocy\Services;

/**
 * Base class for all services: provides the shared LessQL database connection
 * and a per-subclass singleton via GetInstance().
 */
class BaseService
{
	public function __construct()
	{
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	private static $Instances = [];

	/** @var \LessQL\Database The shared LessQL database connection */
	protected $DB;

	/**
	 * Returns the singleton instance of the called subclass (one instance per class).
	 *
	 * @return static
	 */
	public static function GetInstance()
	{
		$className = get_called_class();
		if (!isset(self::$Instances[$className]))
		{
			self::$Instances[$className] = new $className();
		}

		return self::$Instances[$className];
	}
}
