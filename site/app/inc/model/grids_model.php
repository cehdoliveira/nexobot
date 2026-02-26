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
		"capital_per_level",
		"accumulated_profit_usdc",
		"current_price",
		"initial_capital_usdc",
		"peak_capital_usdc",
		"current_capital_usdc",
		"stop_loss_triggered",
		"stop_loss_triggered_at",
		"trailing_stop_triggered",
		"trailing_stop_triggered_at",
		"is_processing",
		"last_monitor_at",
		"last_checked_at",
		"created_at"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("grids", $bd);
	}
}
?>
