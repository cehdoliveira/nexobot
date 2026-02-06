<?php
class grids_model extends DOLModel
{
	protected $field = [
		"idx",
		"users_id",
		"symbol",
		"status",
		"grid_levels",
		"lower_price",
		"upper_price",
		"grid_spacing_percent",
		"capital_allocated_usdc",
		"accumulated_profit_usdc",
		"current_price",
		"created_at"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("grids", $bd);
	}
}
?>
