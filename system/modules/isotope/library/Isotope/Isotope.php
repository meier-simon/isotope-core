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

namespace Isotope;

use Isotope\Model\Config;
use Isotope\Model\TaxClass;
use Isotope\Model\TaxRate;
use Isotope\Model\ProductCollection\Cart;


/**
 * Class Isotope
 *
 * The base class for all Isotope components.
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @author     Christoph Wiechert <christoph.wiechert@4wardmedia.de>
 */
class Isotope extends \Controller
{

    /**
     * Current object instance (Singleton)
     * @var object
     */
    protected static $objInstance;

    /**
     * Cache select statement to load product data
     * @var string
     */
    protected $strSelect;

    /**
     * Current cart instance
     * @var object
     */
    public $Cart;

    /**
     * Current order instance
     * @var object
     */
    public $Order;


    /**
     * Prevent cloning of the object (Singleton)
     */
    final private function __clone() {}


    /**
     * Prevent direct instantiation (Singleton)
     */
    protected function __construct()
    {
        parent::__construct();

        $this->import('Database');
        $this->import('FrontendUser', 'User');
    }


    /**
     * Allow access to all protected parent methods
     */
    public function call($name, $arguments=null)
    {
        $arguments = $arguments === null ? array() : (is_array($arguments) ? $arguments : array($arguments));

        return call_user_func_array(array($this, $name), $arguments);
    }


    /**
     * Instantiate the Isotope object
     * @return object
     */
    public static function getInstance()
    {
        if (!is_object(self::$objInstance))
        {
            self::$objInstance = new static();

            // Make sure field data is available
            self::$objInstance->loadDataContainer('tl_iso_products');
            self::$objInstance->loadLanguageFile('tl_iso_products');

            if (strlen($_SESSION['ISOTOPE']['config_id']))
            {
                self::$objInstance->overrideConfig($_SESSION['ISOTOPE']['config_id']);
            }
            else
            {
                self::$objInstance->resetConfig();
            }

            if (TL_MODE == 'FE' && strpos(\Environment::get('script'), 'postsale.php') === false && strpos(self::$objInstance->Environment->script, 'cron.php') === false)
            {
                self::$objInstance->Cart = Cart::getDefaultForStore((int) self::$objInstance->Config->id, (int) self::$objInstance->Config->store_id);

                // Initialize request cache for product list filters
                if (\Input::get('isorc') != '')
                {
                    $objRequestCache = self::$objInstance->Database->prepare("SELECT * FROM tl_iso_requestcache WHERE id=? AND store_id=?")->execute(self::$objInstance->Input->get('isorc'), self::$objInstance->Config->store_id);

                    if ($objRequestCache->numRows)
                    {
                        $GLOBALS['ISO_FILTERS'] = deserialize($objRequestCache->filters);
                        $GLOBALS['ISO_SORTING'] = deserialize($objRequestCache->sorting);
                        $GLOBALS['ISO_LIMIT'] = deserialize($objRequestCache->limits);

                        global $objPage;
                        $objPage->noSearch = 1;
                    }
                    else
                    {
                        unset($_GET['isorc']);

                        // Unset the language parameter
                        if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
                        {
                            unset($_GET['language']);
                        }

                        $strQuery = http_build_query($_GET);
                        self::$objInstance->redirect(preg_replace('/\?.*$/i', '', \Environment::get('request')) . (($strQuery) ? '?' . $strQuery : ''));
                    }
                }

                // Set the product from the auto_item parameter
                if ($GLOBALS['TL_CONFIG']['useAutoItem'] && isset($_GET['auto_item']))
                {
                    Input::getInstance()->setGet('product', Input::getInstance()->get('auto_item'));
                }
            }
        }

        return self::$objInstance;
    }


    /**
     * Set the default store config
     */
    public function resetConfig()
    {
        if ($this->Database->tableExists('tl_iso_config'))
        {
            if (TL_MODE == 'FE')
            {
                global $objPage;

                $objConfig = Config::findByRootPageOrFallback($objPage->rootId);
            }
            else
            {
                $objConfig = Config::findByFallback();
            }
        }

        if ($objConfig === null || !$objConfig->numRows)
        {
            // Display error message in Isotope related backend modules
            if (TL_MODE == 'BE')
            {
                $do = \Input::get('do');

                if (isset($GLOBALS['BE_MOD']['isotope'][$do]))
                {
                    $_SESSION['TL_ERROR'][] = $GLOBALS['TL_LANG']['ERR']['noDefaultStoreConfiguration'];

                    if ($do == 'iso_products')
                    {
                        $this->redirect('contao/main.php?do=iso_setup&mod=configs&table=tl_iso_config&act=create');
                    }
                }
            }
            else
            {
                trigger_error($GLOBALS['TL_LANG']['ERR']['noStoreConfigurationSet'], E_USER_WARNING);
            }

            return;
        }

        $this->Config = new Config($objConfig);
    }


    /**
     * Manual override of the store configuration
     * @param integer
     */
    public function overrideConfig($intConfig)
    {
        if (($this->Config = Config::findByPk($intConfig)) === null)
        {
            $this->resetConfig();
        }
    }


    /**
     * Calculate price trough hook and foreign prices
     * @param float
     * @param object
     * @param string
     * @param integer
     * @return float
     */
    public function calculatePrice($fltPrice, &$objSource, $strField, $intTaxClass=0)
    {
        if (!is_numeric($fltPrice))
        {
            return $fltPrice;
        }

        // !HOOK: calculate price
        if (isset($GLOBALS['ISO_HOOKS']['calculatePrice']) && is_array($GLOBALS['ISO_HOOKS']['calculatePrice']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['calculatePrice'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $fltPrice = $objCallback->$callback[1]($fltPrice, $objSource, $strField, $intTaxClass);
            }
        }

        if ($this->Config->priceMultiplier != 1)
        {
            switch ($this->Config->priceCalculateMode)
            {
                case 'mul':
                    $fltPrice = $fltPrice * $this->Config->priceCalculateFactor;
                    break;

                case 'div':
                    $fltPrice = $fltPrice / $this->Config->priceCalculateFactor;
                    break;
            }
        }

        // Possibly add/subtract tax
        if (($objTaxClass = TaxClass::findByPk($intTaxClass)) !== null)
        {
            $fltPrice = $objTaxClass->calculatePrice($fltPrice);
        }

        return $this->roundPrice($fltPrice);
    }


    /**
     * Calculate a product surcharge and apply taxes if necessary
     * @param string
     * @param string
     * @param integer
     * @param array
     * @param object
     */
    public function calculateSurcharge($strPrice, $strLabel, $intTaxClass, $arrProducts, $objSource)
    {
        $blnPercentage = substr($strPrice, -1) == '%' ? true : false;

        if ($blnPercentage)
        {
            $fltTotal = 0;

            foreach( $arrProducts as $objProduct )
            {
                $fltTotal += (float) $objProduct->total_price;
            }

            $fltSurcharge = (float) substr($strPrice, 0, -1);
            $fltPrice = $this->Isotope->roundPrice($fltTotal / 100 * $fltSurcharge);
        }
        else
        {
            $fltPrice = $this->Isotope->calculatePrice((float) $strPrice, $objSource, 'price', $intTaxClass);
        }

        $arrSurcharge = array
        (
            'label'            => $strLabel,
            'price'            => ($blnPercentage ? $strPrice : '&nbsp;'),
            'total_price'    => $fltPrice,
            'tax_class'        => $intTaxClass,
            'before_tax'    => ($intTaxClass ? true : false),
        );

        if ($intTaxClass == -1)
        {
            $fltTotal = 0;

            foreach( $arrProducts as $objProduct )
            {
                $fltTotal += (float) $objProduct->tax_free_total_price;
            }

            $arrSubtract = array();
            foreach( $arrProducts as $objProduct )
            {
                if ($blnPercentage)
                {
                    $fltProductPrice = $objProduct->total_price / 100 * $fltSurcharge;
                }
                else
                {
                    $fltProductPrice = $fltPrice / 100 * (100 / $fltTotal * $objProduct->tax_free_total_price);
                }

                $fltProductPrice = $fltProductPrice > 0 ? (floor($fltProductPrice * 100) / 100) : (ceil($fltProductPrice * 100) / 100);
                $arrSubtract[$objProduct->collection_id] = $fltProductPrice;
            }

            $arrSurcharge['tax_class'] = 0;
            $arrSurcharge['before_tax'] = true;
            $arrSurcharge['products'] = $arrSubtract;
        }

        return $arrSurcharge;
    }


    /**
     * Calculate tax for a certain tax class, based on the current user information
     * @param integer
     * @param float
     * @param boolean
     * @param array
     * @return array
     */
    public function calculateTax($intTaxClass, $fltPrice, $blnAdd=true, $arrAddresses=null, $blnSubtract=true)
    {
        // Splitted would be -1, can't be calculated here
        if ($intTaxClass < 1)
        {
            return $fltPrice;
        }

        if (!is_array($arrAddresses))
        {
            $arrAddresses = array('billing'=>$this->Cart->billing_address, 'shipping'=>$this->Cart->shipping_address);
        }

        $objTaxClass = TaxClass::findByPk($intTaxClass);

        if (null === $objTaxClass)
        {
            return $fltPrice;
        }

        // !HOOK: calculate taxes
        if (isset($GLOBALS['ISO_HOOKS']['calculateTax']) && is_array($GLOBALS['ISO_HOOKS']['calculateTax']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['calculateTax'] as $callback)
            {
                $this->import($callback[0]);
                $varValue = $this->$callback[0]->$callback[1]($objTaxClass, $fltPrice, $blnAdd, $arrAddresses);

                if ($varValue !== false)
                {
                    return $varValue;
                }
            }
        }

        if (!$blnAdd)
        {
            return $objTaxClass->calculatePrice($fltPrice, $arrAddresses);
        }

        $arrTaxes = array();
        $objIncludes = $objTaxClass->getRelated('includes');

        if ($objIncludes->id > 0)
        {
            $fltTax = $objIncludes->calculateAmountIncludedInPrice($fltPrice);

            if (!$objIncludes->isApplicable($fltPrice, $arrAddresses))
            {
                if ($blnSubtract)
                {
                    $fltPrice -= $fltTax;
                }
            }
            else
            {
                $arrTaxRate = $objIncludes->rate;

                $arrTaxes[$objTaxClass->id . '_' . $objIncludes->id] = array
                (
                    'label'            => $objTaxClass->label ?: $objIncludes->label,
                    'price'            => $arrTaxRate['value'] . $arrTaxRate['unit'],
                    'total_price'    => $this->roundPrice($fltTax, $objTaxClass->applyRoundingIncrement),
                    'add'            => false,
                );
            }
        }

        $objRates = $objTaxClass->getRelated('rates');

        while ($objRates->next())
        {
            $objTaxRate = $objRates->current();

            if ($objTaxRate->isApplicable($fltPrice, $arrAddresses))
            {
                $fltTax = $objTaxRate->calculateAmountAddedToPrice($fltPrice);
                $arrTaxRate = $objTaxRate->rate;

                $arrTaxes[$objTaxRate->id] = array
                (
                    'label'            => $objTaxRate->label,
                    'price'            => $arrTaxRate['value'] . $arrTaxRate['unit'],
                    'total_price'    => $this->roundPrice($fltTax, $objTaxClass->applyRoundingIncrement),
                    'add'            => true,
                );

                if ($objTaxRate->stop)
                {
                    break;
                }
            }
        }

        return $arrTaxes;
    }


    /**
     * Rounds a price according to store config settings
     * @param float original value
     * @param bool apply rounding increment
     * @return float rounded value
     */
    public function roundPrice($fltValue, $blnApplyRoundingIncrement=true)
    {
        if ($blnApplyRoundingIncrement && $this->Config->priceRoundIncrement == '0.05')
        {
            $fltValue = (round(20 * $fltValue)) / 20;
        }

        return round($fltValue, $this->Config->priceRoundPrecision);
    }


    /**
     * Format given price according to store config settings
     * @param float
     * @return float
     */
    public function formatPrice($fltPrice)
    {
        // If price or override price is a string
        if (!is_numeric($fltPrice))
        {
            return $fltPrice;
        }

        $arrFormat = $GLOBALS['ISO_NUM'][$this->Config->currencyFormat];

        if (!is_array($arrFormat))
        {
            return $fltPrice;
        }

        return number_format($fltPrice, $arrFormat[0], $arrFormat[1], $arrFormat[2]);
    }


    /**
     * Format given price according to store config settings, including currency representation
     * @param float
     * @param boolean
     * @param string
     * @return string
     */
    public function formatPriceWithCurrency($fltPrice, $blnHtml=true, $strCurrencyCode=null)
    {
        // If price or override price is a string
        if (!is_numeric($fltPrice))
        {
            return $fltPrice;
        }

        $strCurrency = ($strCurrencyCode != '' ? $strCurrencyCode : $this->Config->currency);
        $strPrice = $this->formatPrice($fltPrice);

        if ($this->Config->currencySymbol && $GLOBALS['TL_LANG']['CUR_SYMBOL'][$strCurrency] != '')
        {
            $strCurrency = (($this->Config->currencyPosition == 'right' && $this->Config->currencySpace) ? ' ' : '') . ($blnHtml ? '<span class="currency">' : '') . $GLOBALS['TL_LANG']['CUR_SYMBOL'][$strCurrency] . ($blnHtml ? '</span>' : '') . (($this->Config->currencyPosition == 'left' && $this->Config->currencySpace) ? ' ' : '');
        }
        else
        {
            $strCurrency = ($this->Config->currencyPosition == 'right' ? ' ' : '') . ($blnHtml ? '<span class="currency">' : '') . $strCurrency . ($blnHtml ? '</span>' : '') . ($this->Config->currencyPosition == 'left' ? ' ' : '');
        }

        if ($this->Config->currencyPosition == 'right')
        {
            return $strPrice . $strCurrency;
        }

        return $strCurrency . $strPrice;
    }


    /**
     * Format the number of items and return the items string
     * @param integer
     * @return string
     */
    public function formatItemsString($intItems)
    {
        if ($intItems == 1)
        {
            return $GLOBALS['TL_LANG']['ISO']['productSingle'];
        }
        else
        {
            $arrFormat = $GLOBALS['ISO_NUM'][$this->Config->currencyFormat];

            if (is_array($arrFormat))
            {
                $intItems = number_format($intItems, 0, $arrFormat[1], $arrFormat[2]);
            }

            return sprintf($GLOBALS['TL_LANG']['ISO']['productMultiple'], $intItems);
        }
    }


    /**
     * Send an email using the isotope e-mail templates
     * @param integer
     * @param string
     * @param string
     * @param array
     * @param string
     * @param object
     */
    public function sendMail($intId, $strRecipient, $strLanguage, $arrData, $strReplyTo='', $objCollection=null)
    {
        try
        {
            $objEmail = new \Isotope\Email($intId, $strLanguage, $objCollection);

            if ($strReplyTo != '')
            {
                $objEmail->replyTo($strReplyTo);
            }

            $objEmail->send($strRecipient, $arrData);
        }
        catch (Exception $e)
        {
            \System::log('Isotope email error: '.$e->getMessage(), __METHOD__, TL_ERROR);
        }
    }


    /**
     * Update ConditionalSelect to include the product ID in conditionField
     * @param string
     * @param array
     * @param object
     * @return array
     */
    public function mergeConditionalOptionData($strField, $arrData, &$objProduct=null)
    {
        $arrData['eval']['conditionField'] = $arrData['attributes']['conditionField'] . (is_object($objProduct) ? '_' . $objProduct->formSubmit : '');

        return $arrData;
    }


    /**
     * Callback for isoButton Hook
     * @param array
     * @return array
     */
    public function defaultButtons($arrButtons)
    {
        $arrButtons['update'] = array('label'=>$GLOBALS['TL_LANG']['MSC']['buttonLabel']['update']);
        $arrButtons['add_to_cart'] = array('label'=>$GLOBALS['TL_LANG']['MSC']['buttonLabel']['add_to_cart'], 'callback'=>array('Isotope\Frontend', 'addToCart'));

        return $arrButtons;
    }


    /**
     * Standardize and calculate the total of multiple weights
     *
     * It's probably faster in theory to convert only the total to the final unit, and not each product weight.
     * However, we might loose precision, not sure about that.
     * Based on formulas found at http://jumk.de/calc/gewicht.shtml
     * @param array
     * @param string
     * @return mixed
     */
    public function calculateWeight($arrWeights, $strUnit)
    {
        if (!is_array($arrWeights) || empty($arrWeights))
        {
            return 0;
        }

        $fltWeight = 0;

        foreach ($arrWeights as $weight)
        {
            if (is_array($weight) && $weight['value'] > 0 && strlen($weight['unit']))
            {
                $fltWeight += $this->convertWeight(floatval($weight['value']), $weight['unit'], 'kg');
            }
        }

        return $this->convertWeight($fltWeight, 'kg', $strUnit);
    }


    /**
     * Convert weight units
     * Supported source/target units: mg, g, kg, t, ct, oz, lb, st, grain
     * @param float
     * @param string
     * @param string
     * @return mixed
     * @throws Exception
     */
    public function convertWeight($fltWeight, $strSourceUnit, $strTargetUnit)
    {
        switch ($strSourceUnit)
        {
            case 'mg':
                return $this->convertWeight(($fltWeight / 1000000), 'kg', $strTargetUnit);

            case 'g':
                return $this->convertWeight(($fltWeight / 1000), 'kg', $strTargetUnit);

            case 'kg':
                switch ($strTargetUnit)
                {
                    case 'mg':
                        return $fltWeight * 1000000;

                    case 'g':
                        return $fltWeight * 1000;

                    case 'kg':
                        return $fltWeight;

                    case 't':
                        return $fltWeight / 1000;

                    case 'ct':
                        return $fltWeight * 5000;

                    case 'oz':
                        return $fltWeight / 28.35 * 1000;

                    case 'lb':
                        return $fltWeight / 0.45359243;

                    case 'st':
                        return $fltWeight / 6.35029318;

                    case 'grain':
                        return $fltWeight / 64.79891 * 1000000;

                    default:
                        throw new InvalidArgumentException('Unknown target weight unit "' . $strTargetUnit . '"');
                }

            case 't':
                return $this->convertWeight(($fltWeight * 1000), 'kg', $strTargetUnit);

            case 'ct':
                return $this->convertWeight(($fltWeight / 5000), 'kg', $strTargetUnit);

            case 'oz':
                return $this->convertWeight(($fltWeight * 28.35 / 1000), 'kg', $strTargetUnit);

            case 'lb':
                return $this->convertWeight(($fltWeight * 0.45359243), 'kg', $strTargetUnit);

            case 'st':
                return $this->convertWeight(($fltWeight * 6.35029318), 'kg', $strTargetUnit);

            case 'grain':
                return $this->convertWeight(($fltWeight * 64.79891 / 1000000), 'kg', $strTargetUnit);

            default:
                throw new InvalidArgumentException('Unknown source weight unit "' . $strSourceUnit . '"');
        }
    }


    /**
     * Validate a custom regular expression
     * @param string
     * @param mixed
     * @param object
     * @return boolean
     */
    public function validateRegexp($strRegexp, $varValue, \Widget $objWidget)
    {
        switch ($strRegexp)
        {
            case 'price':
                if (!preg_match('/^[\d \.-]*$/', $varValue))
                {
                    $objWidget->addError(sprintf($GLOBALS['TL_LANG']['ERR']['digit'], $objWidget->label));
                }

                return true;
                break;

            case 'discount':
                if (!preg_match('/^[-+]\d+(\.\d{1,2})?%?$/', $varValue))
                {
                    $objWidget->addError(sprintf($GLOBALS['TL_LANG']['ERR']['discount'], $objWidget->label));
                }

                return true;
                break;

            case 'surcharge':
                if (!preg_match('/^-?\d+(\.\d{1,2})?%?$/', $varValue))
                {
                    $objWidget->addError(sprintf($GLOBALS['TL_LANG']['ERR']['surcharge'], $objWidget->label));
                }

                return true;
                break;
        }

        return false;
    }


    /**
     * Translate a value using the tl_iso_label table
     * @param mixed
     * @param boolean
     * @return mixed
     */
    public function translate($label, $language=false)
    {
        if (!in_array('isotope_multilingual', \Config::getInstance()->getActiveModules()))
        {
            return $label;
        }

        // Recursively translate label array
        if (is_array($label))
        {
            foreach ($label as $k => $v)
            {
                $label[$k] = $this->translate($v, $language);
            }

            return $label;
        }

        if (!$language)
        {
            $language = $GLOBALS['TL_LANGUAGE'];
        }

        if (!is_array($GLOBALS['TL_LANG']['TBL'][$language]))
        {
            $GLOBALS['TL_LANG']['TBL'][$language] = array();
            $objLabels = $this->Database->execute("SELECT * FROM tl_iso_labels WHERE language='$language'");

            while ($objLabels->next())
            {
                $GLOBALS['TL_LANG']['TBL'][$language][\String::decodeEntities($objLabels->label)] = $objLabels->replacement;
            }
        }

        $label = \String::decodeEntities($label);

        return $GLOBALS['TL_LANG']['TBL'][$language][$label] ? $GLOBALS['TL_LANG']['TBL'][$language][$label] : $label;
    }


    /**
     * Format value (based on DC_Table::show(), Contao 2.9.0)
     * @param string
     * @param string
     * @param mixed
     * @return string
     */
    public function formatValue($strTable, $strField, $varValue)
    {
        $varValue = deserialize($varValue);

        if (!is_array($GLOBALS['TL_DCA'][$strTable]))
        {
            $this->loadDataContainer($strTable);
            $this->loadLanguageFile($strTable);
        }

        // Get field value
        if (strlen($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['foreignKey']))
        {
            $chunks = explode('.', $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['foreignKey']);
            $varValue = empty($varValue) ? array(0) : $varValue;
            $objKey = $this->Database->execute("SELECT " . $chunks[1] . " AS value FROM " . $chunks[0] . " WHERE id IN (" . implode(',', array_map('intval', (array) $varValue)) . ")");

            return implode(', ', $objKey->fetchEach('value'));
        }

        elseif (is_array($varValue))
        {
            foreach ($varValue as $kk => $vv)
            {
                $varValue[$kk] = $this->formatValue($strTable, $strField, $vv);
            }

            return implode(', ', $varValue);
        }

        elseif ($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['eval']['rgxp'] == 'date')
        {
            return \System::parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $varValue);
        }

        elseif ($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['eval']['rgxp'] == 'time')
        {
            return \System::parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $varValue);
        }

        elseif ($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['eval']['rgxp'] == 'datim' || in_array($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['flag'], array(5, 6, 7, 8, 9, 10)) || $strField == 'tstamp')
        {
            return \System::parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $varValue);
        }

        elseif ($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['eval']['multiple'])
        {
            return strlen($varValue) ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
        }

        elseif ($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['inputType'] == 'textarea' && ($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['eval']['allowHtml'] || $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['eval']['preserveTags']))
        {
            return specialchars($varValue);
        }

        elseif (is_array($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['reference']))
        {
            return isset($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['reference'][$varValue]) ? ((is_array($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['reference'][$varValue])) ? $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['reference'][$varValue][0] : $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['reference'][$varValue]) : $varValue;
        }

        return $varValue;
    }


    /**
     * Format label (based on DC_Table::show(), Contao 2.9.0)
     * @param string
     * @param string
     * @return string
     */
    public function formatLabel($strTable, $strField)
    {
        if (!is_array($GLOBALS['TL_DCA'][$strTable]))
        {
            $this->loadDataContainer($strTable);
            $this->loadLanguageFile($strTable);
        }

        // Label
        if (!empty($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['label']))
        {
            $strLabel = is_array($GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['label']) ? $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['label'][0] : $GLOBALS['TL_DCA'][$strTable]['fields'][$strField]['label'];
        }

        else
        {
            $strLabel = is_array($GLOBALS['TL_LANG']['MSC'][$strField]) ? $GLOBALS['TL_LANG']['MSC'][$strField][0] : $GLOBALS['TL_LANG']['MSC'][$strField];
        }

        if (!strlen($strLabel))
        {
            $strLabel = $strField;
        }

        return $strLabel;
    }


    /**
     * Merge media manager data from fallback and translated product data
     * @param array
     * @param array
     * @return array
     */
    public function mergeMediaData($arrCurrent, $arrParent)
    {
        $arrTranslate = array();

        if (is_array($arrParent) && !empty($arrParent))
        {
            // Create an array of images where key = image name
            foreach( $arrParent as $i => $image)
            {
                if ($image['translate'] != 'all')
                {
                    $arrTranslate[$image['src']] = $image;
                }
            }
        }

        if (is_array($arrCurrent) && !empty($arrCurrent))
        {
            foreach ($arrCurrent as $i => $image)
            {
                if (isset($arrTranslate[$image['src']]))
                {
                    if ($arrTranslate[$image['src']]['translate'] == '')
                    {
                        $arrCurrent[$i] = $arrTranslate[$image['src']];
                    }
                    else
                    {
                        $arrCurrent[$i]['link'] = $arrTranslate[$image['src']]['link'];
                        $arrCurrent[$i]['translate'] = $arrTranslate[$image['src']]['translate'];
                    }

                    unset($arrTranslate[$image['src']]);
                }
                elseif ($arrCurrent[$i]['translate'] != 'all')
                {
                    unset($arrCurrent[$i]);
                }
            }

            // Add remaining parent image to the list
            if (!empty($arrTranslate))
            {
                $arrCurrent = array_merge($arrCurrent, array_values($arrTranslate));
            }

            $arrCurrent = array_values($arrCurrent);
        }
        else
        {
            $arrCurrent = array_values($arrTranslate);
        }

        return $arrCurrent;
    }
}
