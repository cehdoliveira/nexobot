<?php
class settings_model extends DOLModel
{
    protected $field = [
        "idx", "namespace", "key", "value", "description"
    ];
    protected $filter = ["active = 'yes'"];

    function __construct($bd = false)
    {
        return parent::__construct("settings", $bd);
    }
}
