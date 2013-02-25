<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope\Model;


/**
 * Class IsotopeConfig
 *
 * Provide methods to handle Isotope configuration.
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 */
class Config extends \Model
{

    /**
     * Name of the current table
     * @var string
     */
    protected static $strTable = 'tl_iso_config';


    /**
     * Return custom options or table row data
     * @param mixed
     * @return mixed
     */
    public function __get($strKey)
    {
        switch ($strKey)
        {
            case 'billing_fields_raw':
            case 'shipping_fields_raw':
                if (!is_array($this->arrCache[$strKey]))
                {
                    $strField = str_replace('_raw', '', $strKey);
                    $arrFields = array();

                    foreach( $this->$strField as $field )
                    {
                        if ($field['enabled'])
                        {
                            $arrFields[] = $field['value'];
                        }
                    }

                    $this->arrCache[$strKey] = $arrFields;
                }

                return $this->arrCache[$strKey];

            case 'billing_countries':
            case 'shipping_countries':
                $arrCountries = deserialize(parent::__get($strKey));

                if (!is_array($arrCountries) || empty($arrCountries))
                {
                    $this->import('Isotope\Isotope', 'Isotope');
                    $arrCountries = array_keys(\System::getCountries());
                }

                return $arrCountries;
                break;

            default:
                return deserialize(parent::__get($strKey));
        }
    }

    /**
     * Find config set in root page or the fallback
     * @param  int
     * @return object|null
     */
    public static function findByRootPageOrFallback($intRoot)
    {
        $arrOptions = array(
			'column' => "(id=(SELECT iso_config FROM tl_page WHERE id=?) OR fallback='1')",
			'value'  => $intRoot,
			'order'  => 'fallback',
			'return' => 'Model'
		);

		return static::find($arrOptions);
    }

    /**
     * Find the fallback config
     * @return object|null
     */
    public static function findByFallback()
    {
        $arrOptions = array(
			'column' => 'fallback',
			'value'  => '1',
			'return' => 'Model'
		);

		return static::find($arrOptions);
    }
}
