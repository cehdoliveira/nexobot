<?php
class orders_model extends DOLModel
{
	protected $field = [
		"idx",
		"binance_order_id",
		"binance_client_order_id",
		"symbol",
		"side",
		"type",
		"order_type",
		"tp_target",
		"price",
		"quantity",
		"executed_qty",
		"status",
		"cumulative_quote_qty",
		"order_created_at",
		"order_updated_at",
		"api_response"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("orders", $bd);
	}
}