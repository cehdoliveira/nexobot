<?php
class profiles_model extends DOLModel
{
    protected $field = ["idx", "name", "editabled", "slug", "adm", "parent"];
    protected $filter = ["active = 'yes'"];

    function __construct($bd = false)
    {
        return parent::__construct("profiles", $bd);
    }
}
