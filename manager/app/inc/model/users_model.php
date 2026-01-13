<?php
class users_model extends DOLModel
{
	protected $field = ["idx", "mail", "login", "password", "name", "cpf", "last_login", "phone", "genre", "enabled"];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("users", $bd);
	}
}