<?php namespace common\components\services;

interface CountryRestrictionInterface
{
    /**
     * @return array
     */
    public function restrictCountries();
}