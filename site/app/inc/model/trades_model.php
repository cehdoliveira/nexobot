<?php
class trades_model extends DOLModel
{
	protected $field = ["idx", "symbol", "status", "strategy", "timeframe", "entry_price", "exit_price", "quantity", "investment", "take_profit_price", "profit_loss", "profit_loss_percent", "exit_type", "opened_at", "closed_at"];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("trades", $bd);
	}
}
