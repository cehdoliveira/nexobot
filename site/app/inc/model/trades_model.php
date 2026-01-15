<?php
class trades_model extends DOLModel
{
	protected $field = ["idx", "symbol", "status", "entry_price", "quantity", "investment", "strategy", "timeframe", "bb_upper", "bb_middle", "bb_lower", "take_profit_price", "exit_price", "exit_type", "profit_loss", "profit_loss_percent", "opened_at", "closed_at", "notes"];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("trades", $bd);
	}
}
